<script setup lang="ts">
/**
 * Merging works and moving copies: proposals (works with the same title and
 * composer surname), a merge by shelf mark, and the history with "Undo".
 * Nothing is deleted: a merged work only disappears from the lists.
 */
import type { Entry, Merge, MergeCandidateGroup } from '../types.ts'

import { mdiAlertCircleOutline, mdiCallMerge, mdiCheckCircleOutline, mdiFileMoveOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import MergeList from './MergeList.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchMergeCandidates, fetchMerges, mergeWorks, moveCopy } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { canonicalShelfmark, parseShelfmark } from '../catalog.ts'
import { entries, isLibrarian, loadDetail, loadEntries, openEntry, refreshPortalRows } from '../store.ts'

const candidates = ref<MergeCandidateGroup[]>([])
const merges = ref<Merge[]>([])
const showUndone = ref(false)
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const [groups, history] = await Promise.all([fetchMergeCandidates(), fetchMerges(showUndone.value)])
		candidates.value = groups
		merges.value = history
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

async function loadHistory(): Promise<void> {
	try {
		merges.value = await fetchMerges(showUndone.value)
	} catch (caught) {
		error.value = errorMessage(caught)
	}
}

/** After a merge or an undo everything that shows works may have changed. */
async function afterChange(): Promise<void> {
	await Promise.all([load(), loadEntries(), refreshPortalRows(), loadDetail()])
}

onMounted(load)

// ---- proposals ----

/** Work kept per proposal group (index of the group => work id). */
const kept = ref<Record<number, string>>({})

/**
 * Work kept in a group: chosen, otherwise the one with the most copies.
 *
 * @param group - proposal group
 * @param index - index of the group
 */
function keptWorkId(group: MergeCandidateGroup, index: number): number {
	const chosen = Number.parseInt(kept.value[index] ?? '', 10)
	if (group.works.some((work) => work.workId === chosen)) {
		return chosen
	}
	return [...group.works].sort((a, b) => b.copies.length - a.copies.length || a.workId - b.workId)[0]?.workId ?? 0
}

/**
 * Title, composer and work number in one line.
 *
 * @param work - work facts
 * @param work.title - title
 * @param work.composer - composer
 * @param work.work_no - work number
 */
function workName(work: { title: string | null, composer: string | null, work_no?: string | null }): string {
	const parts = [work.composer, work.work_no].filter((part) => part)
	const title = work.title || t('notenarchiv', 'Untitled')
	return parts.length > 0 ? `${title} (${parts.join(', ')})` : title
}

// ---- confirmation ----

interface Pending {
	kind: 'work' | 'copy'
	sourceId: number
	targetId: number
	text: string
}

const pending = ref<Pending | null>(null)
const reason = ref('')
const busy = ref(false)
const pendingError = ref('')

/**
 * Ask before merging a work of a proposal group into the kept one.
 *
 * @param group - proposal group
 * @param index - index of the group
 * @param sourceWorkId - work to merge
 */
function askMergeWork(group: MergeCandidateGroup, index: number, sourceWorkId: number): void {
	const source = group.works.find((work) => work.workId === sourceWorkId)
	const target = group.works.find((work) => work.workId === keptWorkId(group, index))
	if (!source || !target) {
		return
	}
	pending.value = {
		kind: 'work',
		sourceId: source.workId,
		targetId: target.workId,
		text: t('notenarchiv', 'All versions of "{source}" ({sourceCopies}) move to "{target}" ({targetCopies}).', {
			source: workName(source),
			sourceCopies: source.copies.map((copy) => copy.shelfmark).join(', '),
			target: workName(target),
			targetCopies: target.copies.map((copy) => copy.shelfmark).join(', '),
		}),
	}
	reason.value = ''
	pendingError.value = ''
}

async function confirmPending(): Promise<void> {
	if (!pending.value) {
		return
	}
	busy.value = true
	pendingError.value = ''
	const note = reason.value.trim() === '' ? null : reason.value.trim()
	try {
		if (pending.value.kind === 'work') {
			await mergeWorks(pending.value.sourceId, pending.value.targetId, note)
			showSuccess(t('notenarchiv', 'The works have been merged.'))
		} else {
			await moveCopy(pending.value.sourceId, pending.value.targetId, note)
			showSuccess(t('notenarchiv', 'The copy has been moved.'))
		}
		pending.value = null
		manualSource.value = ''
		manualTarget.value = ''
		await afterChange()
		// The proposal that was used is gone: the list of merges gets the focus
		await focusAfterDialog(() => document.getElementById('merges-history'))
	} catch (caught) {
		pendingError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

// ---- by shelf mark ----

const manualKind = ref<'work' | 'copy'>('work')
const manualSource = ref('')
const manualTarget = ref('')
const manualError = ref('')

/**
 * Catalog row of a typed shelf mark.
 *
 * @param text - typed shelf mark
 */
function entryOf(text: string): Entry | null {
	const shelfmark = parseShelfmark(text)
	if (shelfmark === null) {
		return null
	}
	return entries.value.find((entry) => canonicalShelfmark(entry.shelfmark) === shelfmark) ?? null
}

const manualKindOptions = computed(() => [
	{ id: 'work', label: t('notenarchiv', 'Merge works') },
	{ id: 'copy', label: t('notenarchiv', 'Move a copy to another version') },
])

function askManual(): void {
	manualError.value = ''
	const source = entryOf(manualSource.value)
	const target = entryOf(manualTarget.value)
	if (!source || !target) {
		manualError.value = t('notenarchiv', 'Please enter two shelf marks from the catalog.')
		return
	}
	if (source.work_id === null || target.work_id === null || source.version_id === null || target.version_id === null) {
		manualError.value = t('notenarchiv', 'The catalog list is not up to date. Please reload the page.')
		return
	}
	if (manualKind.value === 'work') {
		if (source.work_id === target.work_id) {
			manualError.value = t('notenarchiv', '{source} and {target} already belong to the same work.', { source: source.shelfmark, target: target.shelfmark })
			return
		}
		pending.value = {
			kind: 'work',
			sourceId: source.work_id,
			targetId: target.work_id,
			text: t('notenarchiv', 'All versions of "{source}" (work of {sourceShelfmark}) move to "{target}" (work of {targetShelfmark}).', {
				source: workName(source),
				sourceShelfmark: source.shelfmark,
				target: workName(target),
				targetShelfmark: target.shelfmark,
			}),
		}
	} else {
		if (source.version_id === target.version_id) {
			manualError.value = t('notenarchiv', '{source} and {target} already belong to the same version.', { source: source.shelfmark, target: target.shelfmark })
			return
		}
		pending.value = {
			kind: 'copy',
			sourceId: source.id,
			targetId: target.version_id,
			text: t('notenarchiv', 'Copy {source} moves to the version of {target} ("{title}"). Its former version is kept, also when it has no copies left.', {
				source: source.shelfmark,
				target: target.shelfmark,
				title: workName(target),
			}),
		}
	}
	reason.value = ''
	pendingError.value = ''
}
</script>

<template>
	<div class="merges-view">
		<ViewHeader :title="t('notenarchiv', 'Merge')"
			:description="t('notenarchiv', 'When the catalog lists the same work twice, the entries become one. Nothing is deleted; every merge can be undone as long as the moved data has not been changed. A merge never changes a rights status.')"
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="merges-view__content">
			<NcEmptyContent v-if="!loaded && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error" :name="t('notenarchiv', 'The list could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>

				<!-- Proposals -->
				<section class="merges-view__section" aria-labelledby="merges-candidates">
					<h3 id="merges-candidates">
						{{ t('notenarchiv', 'Proposals') }}
					</h3>
					<p class="merges-view__muted">
						{{ t('notenarchiv', 'Works with the same title and the same composer surname. Check the copies before merging: different arrangements stay different versions of one work.') }}
					</p>
					<p v-if="candidates.length === 0" class="merges-view__empty">
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" inline />
						{{ t('notenarchiv', 'No proposals: no work seems to be listed twice.') }}
					</p>
					<article v-for="(group, index) in candidates"
						:key="group.title + index"
						class="merges-view__group"
						:aria-labelledby="`merges-group-${index}`">
						<h4 :id="`merges-group-${index}`">
							{{ group.title }}
							<span class="merges-view__muted">{{ n('notenarchiv', '%n work', '%n works', group.works.length) }}</span>
						</h4>
						<fieldset class="merges-view__works">
							<legend class="hidden-visually">
								{{ t('notenarchiv', 'Work to keep') }}
							</legend>
							<div v-for="work in group.works" :key="work.workId" class="merges-view__work">
								<div class="merges-view__work-text">
									<NcCheckboxRadioSwitch v-if="isLibrarian"
										type="radio"
										:name="`merges-keep-${index}`"
										:value="String(work.workId)"
										:model-value="String(keptWorkId(group, index))"
										@update:model-value="kept[index] = $event">
										<span class="merges-view__work-name">{{ workName(work) }}</span>
										<span v-if="work.workId === keptWorkId(group, index)" class="merges-view__keep">{{ t('notenarchiv', 'kept') }}</span>
									</NcCheckboxRadioSwitch>
									<span v-else class="merges-view__work-name">{{ workName(work) }}</span>
									<span class="merges-view__copies">
										<button v-for="copy in work.copies"
											:key="copy.id"
											type="button"
											class="merges-view__shelfmark"
											:aria-label="t('notenarchiv', 'Open {shelfmark}', { shelfmark: copy.shelfmark })"
											@click="openEntry(copy.id)">
											{{ copy.shelfmark }}
										</button>
									</span>
								</div>
								<NcButton v-if="isLibrarian && work.workId !== keptWorkId(group, index)"
									variant="secondary"
									@click="askMergeWork(group, index, work.workId)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiCallMerge" />
									</template>
									{{ t('notenarchiv', 'Merge into the kept work') }}
								</NcButton>
							</div>
						</fieldset>
					</article>
				</section>

				<!-- By shelf mark -->
				<section v-if="isLibrarian" class="merges-view__section" aria-labelledby="merges-manual">
					<h3 id="merges-manual">
						{{ t('notenarchiv', 'Merge by shelf mark') }}
					</h3>
					<form class="merges-view__manual" @submit.prevent="askManual">
						<fieldset class="merges-view__kinds">
							<legend class="hidden-visually">
								{{ t('notenarchiv', 'What to do') }}
							</legend>
							<NcCheckboxRadioSwitch v-for="option in manualKindOptions"
								:key="option.id"
								v-model="manualKind"
								type="radio"
								name="merges-manual-kind"
								:value="option.id">
								{{ option.label }}
							</NcCheckboxRadioSwitch>
						</fieldset>
						<div class="merges-view__fields">
							<NcTextField v-model="manualSource"
								:label="manualKind === 'work' ? t('notenarchiv', 'Work of shelf mark (is merged)') : t('notenarchiv', 'Copy (shelf mark)')" />
							<NcTextField v-model="manualTarget"
								:label="manualKind === 'work' ? t('notenarchiv', 'into the work of shelf mark (is kept)') : t('notenarchiv', 'to the version of shelf mark')" />
							<NcButton type="submit" :disabled="manualSource.trim() === '' || manualTarget.trim() === ''">
								<template #icon>
									<NcIconSvgWrapper :path="manualKind === 'work' ? mdiCallMerge : mdiFileMoveOutline" />
								</template>
								{{ t('notenarchiv', 'Check and confirm …') }}
							</NcButton>
						</div>
						<NcNoteCard v-if="manualError" type="error" role="alert">
							{{ manualError }}
						</NcNoteCard>
					</form>
				</section>

				<!-- History -->
				<section class="merges-view__section" aria-labelledby="merges-history">
					<h3 id="merges-history">
						{{ t('notenarchiv', 'Merges') }}
					</h3>
					<NcCheckboxRadioSwitch v-model="showUndone" type="switch" @update:model-value="loadHistory">
						{{ t('notenarchiv', 'Also show undone merges') }}
					</NcCheckboxRadioSwitch>
					<p v-if="merges.length === 0" class="merges-view__muted">
						{{ t('notenarchiv', 'No merges yet.') }}
					</p>
					<MergeList v-else :merges="merges" @changed="afterChange" />
				</section>
			</template>
		</div>

		<ActionDialog v-if="pending"
			:name="pending.kind === 'work' ? t('notenarchiv', 'Merge works') : t('notenarchiv', 'Move copy')"
			:submit-label="pending.kind === 'work' ? t('notenarchiv', 'Merge') : t('notenarchiv', 'Move')"
			:busy="busy"
			:error="pendingError"
			@submit="confirmPending"
			@close="pending = null">
			<p>{{ pending.text }}</p>
			<p class="action-dialog__hint">
				{{ pending.kind === 'work'
					? t('notenarchiv', 'The merged work disappears from the lists but is never deleted; the kept work gets a work number if it has none. This is refused when both works have a version valid for the choir. Programme lines move along.')
					: t('notenarchiv', 'Programme lines move along. The rights status of the versions does not change.') }}
			</p>
			<NcTextField v-model="reason" :label="t('notenarchiv', 'Reason (optional)')" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.merges-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	max-width: 70em;
	padding: calc(3 * var(--default-grid-baseline));
}

.merges-view__section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.merges-view__section + .merges-view__section {
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.merges-view__section h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.merges-view__group {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.merges-view__group h4 {
	margin: 0 0 var(--default-grid-baseline);
	font-weight: bold;
}

.merges-view__works,
.merges-view__kinds {
	margin: 0;
	padding: 0;
	border: none;
}

.merges-view__work {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
}

.merges-view__work:last-child {
	border-bottom: none;
}

.merges-view__work-text {
	display: flex;
	flex: 1 1 20em;
	flex-direction: column;
	min-width: 0;
	overflow-wrap: anywhere;
}

.merges-view__work-name {
	font-weight: bold;
}

.merges-view__keep {
	margin-inline-start: calc(2 * var(--default-grid-baseline));
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
}

.merges-view__copies {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	padding-inline-start: calc(var(--default-clickable-area) + var(--default-grid-baseline));
}

.merges-view__shelfmark {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.merges-view__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.merges-view__manual {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.merges-view__kinds {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(4 * var(--default-grid-baseline));
}

.merges-view__fields {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.merges-view__fields > :not(button) {
	/* wide enough for the labels, also with more letter spacing (WCAG 1.4.12) */
	flex: 1 1 20em;
}

.merges-view__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.merges-view__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
</style>
