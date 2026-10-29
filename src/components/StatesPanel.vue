<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Revisions of the version of a copy (milestone 0.8, module
 * "version_states"): the list with where each revision came from, what it
 * changed and its checksum; the catalog librarian sets the current one.
 * Two revisions are compared in the browser on their files, measure by
 * measure and voice by voice, with a short note for choir members to copy.
 */
import type { Comparison } from '../score/compare.ts'
import type { Detail, StateList, VersionState } from '../types.ts'

import { mdiCheckDecagramOutline, mdiContentCopy, mdiFileCompare } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import GuideLink from './GuideLink.vue'
import HelpTerm from './HelpTerm.vue'
import { ApiError, errorMessage, fetchFileContent, fetchStates, setCurrentState } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { USER_GUIDE, userGuideUrl } from '../docs.ts'
import { formatDate, formatDateTime } from '../format.ts'
import { changeWords, compareWords, revisionLine, revisionSourceLabel } from '../revisionLabels.ts'
import { compareScores } from '../score/compare.ts'
import { ArchiveLimitError, readScoreText } from '../score/mxl.ts'
import { changeText, memberNote, sha256Hex } from '../states.ts'
import { showError } from '../toasts.ts'

const props = defineProps<{
	detail: Detail
}>()

const emit = defineEmits<{
	changed: []
}>()

const list = ref<StateList | null>(null)
const loading = ref(false)
const loadError = ref('')

const versionId = computed(() => Number(props.detail.version.id))
const shelfmark = computed(() => String(props.detail.copy.shelfmark ?? ''))
const title = computed(() => String(props.detail.work.title ?? ''))

async function load(): Promise<void> {
	loading.value = true
	loadError.value = ''
	try {
		list.value = await fetchStates(versionId.value)
	} catch (error) {
		loadError.value = errorMessage(error)
	} finally {
		loading.value = false
	}
}

onMounted(load)
// Another version, or a revision recorded or set elsewhere (correction, file sync)
watch([versionId, () => props.detail.revision?.count, () => props.detail.revision?.current?.id], ([id], [oldId]) => {
	if (id !== oldId) {
		result.value = null
	}
	load()
})

const states = computed(() => list.value?.states ?? [])
/** Newest first, as people read a history. */
const newestFirst = computed(() => [...states.value].reverse())
const current = computed(() => states.value.find((state) => state.id === list.value?.current_id) ?? null)
const newest = computed(() => states.value[states.value.length - 1] ?? null)
const maySetCurrent = computed(() => list.value?.may.setCurrent === true)

/** The version line with the current revision, as printed on outputs. */
const line = computed(() => revisionLine(shelfmark.value, props.detail.version, current.value?.number ?? null, current.value?.valid_since ?? null))

/**
 * Measures and voices a revision changed, as one line.
 *
 * @param state - the revision
 */
function changedText(state: VersionState): string {
	return [
		state.measures ? t('cantoria', 'Measures: {measures}', { measures: state.measures }) : '',
		state.voices ? t('cantoria', 'Voices: {voices}', { voices: state.voices }) : '',
	].filter((part) => part !== '').join(' · ')
}

/**
 * Who recorded a revision and when.
 *
 * @param state - the revision
 */
function recordedText(state: VersionState): string {
	return [revisionSourceLabel(state.source), formatDateTime(state.created), state.created_by_name ?? ''].filter((part) => part !== '').join(' · ')
}

// ---- setting the current revision ----

const setting = ref<VersionState | null>(null)
const reason = ref('')
const busy = ref(false)
const dialogError = ref('')

/**
 * @param state - revision to make the current one
 */
function startSetCurrent(state: VersionState): void {
	setting.value = state
	reason.value = ''
	dialogError.value = ''
}

async function submitSetCurrent(): Promise<void> {
	if (!setting.value) {
		return
	}
	busy.value = true
	dialogError.value = ''
	try {
		await setCurrentState(setting.value.id, reason.value.trim() === '' ? null : reason.value.trim())
		showSuccess(t('cantoria', 'Revision {number} is now the current revision.', { number: setting.value.number }))
		setting.value = null
		await load()
		emit('changed')
	} catch (error) {
		dialogError.value = errorMessage(error)
	} finally {
		busy.value = false
	}
}

// ---- comparing two revisions ----

interface Option {
	id: number
	label: string
}

/**
 * Label of a revision in the choice.
 *
 * @param state - the revision
 */
function optionLabel(state: VersionState): string {
	const label = t('cantoria', 'Revision {number}', { number: state.number })
	return state.valid_since ? t('cantoria', '{revision} (current)', { revision: label }) : label
}

const options = computed<Option[]>(() => newestFirst.value.map((state) => ({ id: state.id, label: optionLabel(state) })))
const olderOption = ref<Option | null>(null)
const newerOption = ref<Option | null>(null)

// Preselect: the newest against the current one, or against the one before
watch(states, (all) => {
	const latest = all[all.length - 1]
	const before = current.value && current.value.id !== latest?.id ? current.value : all[all.length - 2]
	newerOption.value = latest ? options.value.find((option) => option.id === latest.id) ?? null : null
	olderOption.value = before ? options.value.find((option) => option.id === before.id) ?? null : null
}, { immediate: true })

const comparing = ref(false)
const compareError = ref('')
const result = ref<{ older: VersionState, newer: VersionState, comparison: Comparison } | null>(null)

/** The file of a revision no longer has the recorded content. */
class RevisionChangedError extends Error {}

/**
 * The content of a revision's file, checked against the recorded checksum.
 *
 * @param state - the revision
 */
async function revisionText(state: VersionState): Promise<string> {
	const content = await fetchFileContent(state.file_id)
	const sha256 = await sha256Hex(content)
	if (state.sha256 !== null && sha256 !== null && sha256 !== state.sha256) {
		throw new RevisionChangedError(t('cantoria', 'The file of revision {number} has been changed since it was recorded, so its content is no longer there. Compare newer revisions, or look for the earlier content under "Versions" of the file in the Files app.', { number: state.number }))
	}
	return readScoreText(content)
}

async function compare(): Promise<void> {
	const byId = (option: Option | null) => states.value.find((state) => state.id === option?.id) ?? null
	let older = byId(olderOption.value)
	let newer = byId(newerOption.value)
	compareError.value = ''
	result.value = null
	if (!older || !newer || older.id === newer.id) {
		compareError.value = t('cantoria', 'Please choose two different revisions.')
		return
	}
	if (older.number > newer.number) {
		[older, newer] = [newer, older]
	}
	comparing.value = true
	try {
		const [before, after] = await Promise.all([revisionText(older), revisionText(newer)])
		result.value = { older, newer, comparison: compareScores(before, after, compareWords()) }
	} catch (error) {
		if (error instanceof RevisionChangedError || error instanceof ApiError) {
			compareError.value = error.message
		} else if (error instanceof ArchiveLimitError) {
			compareError.value = t('cantoria', 'The score file is too large to show.')
		} else if (error instanceof Error) {
			compareError.value = t('cantoria', 'One of the files could not be read as MusicXML.')
		} else {
			compareError.value = errorMessage(error)
		}
	} finally {
		comparing.value = false
	}
}

const words = changeWords()
const lines = computed(() => result.value?.comparison.changes.map((change) => changeText(change, words)) ?? [])

const statusText = computed(() => {
	if (!result.value) {
		return ''
	}
	const count = result.value.comparison.changes.length
	return count === 0
		? t('cantoria', 'No differences in notes and lyrics between revision {older} and revision {newer}.', { older: result.value.older.number, newer: result.value.newer.number })
		: n('cantoria', '%n difference between revision {older} and revision {newer}.', '%n differences between revision {older} and revision {newer}.', count,
			{ older: result.value.older.number, newer: result.value.newer.number })
})

/** The short note for choir members. */
const note = computed(() => {
	if (!result.value || result.value.comparison.changes.length === 0) {
		return ''
	}
	const heading = t('cantoria', '{title} ({shelfmark}): changes from revision {older} to revision {newer}', {
		title: title.value, shelfmark: shelfmark.value, older: result.value.older.number, newer: result.value.newer.number,
	})
	return memberNote(heading, result.value.comparison.changes, words,
		result.value.comparison.truncated ? t('cantoria', '… and further changes; please look at the score.') : null)
})

async function copyNote(): Promise<void> {
	if (await copyText(note.value)) {
		showSuccess(t('cantoria', 'The note has been copied.'))
	} else {
		showError(t('cantoria', 'The text could not be copied. Please select and copy it by hand.'))
	}
}
</script>

<template>
	<section class="states" aria-labelledby="states-heading">
		<div class="states__title">
			<h3 id="states-heading">
				{{ t('cantoria', 'Revisions of this version') }}
			</h3>
			<HelpTerm term="revision" />
		</div>
		<p class="states__line">
			{{ line }}
		</p>

		<p v-if="loading && !list" class="states__muted">
			<NcLoadingIcon :size="20" :name="t('cantoria', 'Loading …')" />
		</p>
		<NcNoteCard v-else-if="loadError" type="error">
			<p>{{ loadError }}</p>
			<NcButton @click="load">
				{{ t('cantoria', 'Try again') }}
			</NcButton>
		</NcNoteCard>

		<template v-else-if="list">
			<p v-if="states.length === 0" class="states__muted">
				{{ t('cantoria', 'No revision has been recorded yet. The next file sync records the MusicXML of this version as revision 1; a correction in the app does the same.') }}
			</p>
			<template v-else>
				<NcNoteCard v-if="!current" type="info">
					{{ maySetCurrent
						? t('cantoria', 'No current revision is set yet. Check the revision that is valid for the choir and choose "Set as current". Outputs name it from then on.')
						: t('cantoria', 'The catalog librarian has not set a current revision yet.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="newest && newest.number > current.number" type="info">
					{{ maySetCurrent
						? t('cantoria', 'Revision {newer} is newer than the current revision {current}. Compare them below and set the newer one as current when it is valid.', { newer: newest.number, current: current.number })
						: t('cantoria', 'Revision {newer} is newer than the current revision {current}.', { newer: newest.number, current: current.number }) }}
				</NcNoteCard>

				<ol class="states__list" reversed>
					<li v-for="state in newestFirst" :key="state.id" class="states__item">
						<p class="states__title">
							<strong>{{ t('cantoria', 'Revision {number}', { number: state.number }) }}</strong>
							<span v-if="state.valid_since" class="states__current">
								<NcIconSvgWrapper :path="mdiCheckDecagramOutline" :size="18" inline />
								{{ t('cantoria', 'current since {date}', { date: formatDate(state.valid_since) }) }}
							</span>
						</p>
						<p class="states__muted">
							{{ recordedText(state) }}
						</p>
						<p v-if="changedText(state)">
							{{ changedText(state) }}
						</p>
						<p v-if="state.note" class="states__note">
							{{ state.note }}
						</p>
						<p class="states__muted states__file">
							{{ t('cantoria', 'File: {name}', { name: state.file_name }) }}<template v-if="state.sha256">
								· <abbr :title="state.sha256">{{ t('cantoria', 'Checksum {checksum}', { checksum: state.sha256.slice(0, 12) }) }}</abbr>
							</template>
						</p>
						<NcButton v-if="maySetCurrent && !state.valid_since"
							variant="secondary"
							:aria-label="t('cantoria', 'Set revision {number} as current', { number: state.number })"
							@click="startSetCurrent(state)">
							{{ t('cantoria', 'Set as current') }}
						</NcButton>
					</li>
				</ol>

				<section v-if="states.length >= 2" class="states__compare" aria-labelledby="states-compare-heading">
					<h4 id="states-compare-heading">
						{{ t('cantoria', 'Compare two revisions') }}
					</h4>
					<form class="states__compare-form" @submit.prevent="compare">
						<NcSelect v-model="olderOption"
							:input-label="t('cantoria', 'Older revision')"
							:options="options"
							:clearable="false"
							:searchable="false" />
						<NcSelect v-model="newerOption"
							:input-label="t('cantoria', 'Newer revision')"
							:options="options"
							:clearable="false"
							:searchable="false" />
						<NcButton type="submit" :disabled="comparing">
							<template #icon>
								<NcLoadingIcon v-if="comparing" :size="20" />
								<NcIconSvgWrapper v-else :path="mdiFileCompare" />
							</template>
							{{ t('cantoria', 'Compare') }}
						</NcButton>
					</form>
					<p class="states__muted">
						{{ t('cantoria', 'Compares notes, rests, note values, accidentals and lyrics measure by measure and voice by voice. Layout, dynamics and slurs are not compared.') }}
					</p>

					<NcNoteCard v-if="compareError" type="error">
						{{ compareError }}
					</NcNoteCard>
					<p role="status" class="states__status">
						{{ statusText }}
					</p>
					<template v-if="result && lines.length > 0">
						<ul class="states__changes">
							<li v-for="(text, index) in lines" :key="index">
								{{ text }}
							</li>
						</ul>
						<p v-if="result.comparison.truncated" class="states__muted">
							{{ t('cantoria', 'Only the first {count} differences are listed.', { count: lines.length }) }}
						</p>
						<NcTextArea :model-value="note"
							:label="t('cantoria', 'Note for choir members')"
							:helper-text="t('cantoria', 'Plain text to copy into an e-mail or a message. It contains no notation.')"
							readonly
							resize="vertical"
							rows="6" />
						<NcButton @click="copyNote">
							<template #icon>
								<NcIconSvgWrapper :path="mdiContentCopy" />
							</template>
							{{ t('cantoria', 'Copy the note') }}
						</NcButton>
					</template>
				</section>
			</template>
		</template>

		<p class="states__muted">
			<GuideLink :href="userGuideUrl(USER_GUIDE.revisions)" :label="t('cantoria', 'Revisions of a version (user guide)')" />
		</p>

		<ActionDialog v-if="setting"
			:name="t('cantoria', 'Set revision {number} as current', { number: setting.number })"
			:submit-label="t('cantoria', 'Set as current')"
			:busy="busy"
			:error="dialogError"
			@submit="submitSetCurrent"
			@close="setting = null">
			<p>
				{{ current
					? t('cantoria', 'From today, outputs name revision {number} instead of revision {current}. The change is recorded in the audit log.', { number: setting.number, current: current.number })
					: t('cantoria', 'From today, outputs name revision {number}. The change is recorded in the audit log.', { number: setting.number }) }}
			</p>
			<NcTextArea v-model="reason"
				:label="t('cantoria', 'Reason (optional)')"
				resize="vertical"
				rows="3" />
		</ActionDialog>
	</section>
</template>

<style scoped>
.states {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-block: calc(2 * var(--default-grid-baseline));
}

.states__title {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.states h3,
.states h4 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.states h4 {
	font-size: 1em;
}

.states p {
	margin: 0;
}

.states__line {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.states__muted {
	color: var(--color-text-maxcontrast);
}

.states__list {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.states__item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	padding-block-end: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.states__title {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	align-items: center;
}

.states__current {
	display: inline-flex;
	gap: 2px;
	align-items: center;
	font-weight: bold;
	color: var(--color-success-text, var(--color-main-text));
}

.states__note {
	white-space: pre-line;
}

.states__file {
	overflow-wrap: anywhere;
}

.states__compare {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.states__compare-form {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	align-items: flex-end;
}

.states__changes {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}
</style>
