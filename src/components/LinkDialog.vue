<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create an excerpt link: measures, voice, verse, tempo and a note, valid
 * for at most six months. By default only logged-in holders of an archive
 * role can open it. With the modules "member_links" and "public_links"
 * (milestone 0.7) the link can be made for the choir members or for anyone
 * without signing in; the dialog then shows beforehand what the recipients
 * will see and why such a link is not possible, if it is not. The server
 * checks the rights on every call. After creating, the link can be copied.
 */
import type { CircleOption, ExcerptLink, LinkCircle, LinkInput, LinkOptions } from '../types.ts'

import { mdiContentCopy } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, onMounted, reactive, ref, shallowRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createLink, errorMessage, fetchLinkOptions, linkUrl } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, toIsoDate } from '../format.ts'
import { canShareOutside } from '../store.ts'

export interface LinkPrefill {
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	taskId?: number | null
}

const props = defineProps<{
	copyId: number
	shelfmark: string
	prefill?: LinkPrefill | null
}>()

const emit = defineEmits<{
	created: [link: ExcerptLink]
	close: []
}>()

/** Longest validity allowed by the server (days). */
const MAX_DAYS = 183

const today = toIsoDate(new Date())
const latest = (() => {
	const date = new Date()
	date.setDate(date.getDate() + MAX_DAYS)
	return toIsoDate(date)
})()

const draft = reactive({
	measureFrom: props.prefill?.measureFrom ?? '',
	measureTo: props.prefill?.measureTo ?? '',
	voice: props.prefill?.voice ?? '',
	verse: props.prefill?.verse ? String(props.prefill.verse) : '',
	tempo: props.prefill?.tempo && props.prefill.tempo !== 100 ? String(props.prefill.tempo) : '',
	note: '',
	expires: latest,
})

const busy = ref(false)
const error = ref('')
const created = ref<ExcerptLink | null>(null)
const url = computed(() => (created.value ? linkUrl(created.value.token, created.value.circle) : ''))

// ---- who can open the link (milestone 0.7) --------------------------------------

const circle = ref<LinkCircle>('internal')
const options = shallowRef<LinkOptions | null>(null)
const optionsLoading = ref(false)
const optionsError = ref('')

async function loadOptions(): Promise<void> {
	optionsLoading.value = true
	optionsError.value = ''
	try {
		options.value = await fetchLinkOptions(props.copyId)
	} catch (caught) {
		optionsError.value = errorMessage(caught)
	} finally {
		optionsLoading.value = false
	}
}

onMounted(() => {
	if (canShareOutside.value) {
		loadOptions()
	}
})

/** What the server says about the chosen circle; null for internal links. */
const option = computed<CircleOption | null>(() => (circle.value === 'internal' ? null : options.value?.[circle.value] ?? null))

/** Why a link for the chosen circle cannot be made, or ''. */
const blockReason = computed(() => {
	if (circle.value === 'internal' || optionsLoading.value) {
		return ''
	}
	if (optionsError.value !== '') {
		return t('notenarchiv', 'It could not be checked whether this link is possible: {reason}', { reason: optionsError.value })
	}
	const chosen = option.value
	if (chosen === null) {
		return ''
	}
	if (!chosen.available) {
		return chosen.reason ?? t('notenarchiv', 'This kind of link is switched off. An administrator can switch it on in the settings of the music archive under "Modules".')
	}
	return chosen.allowed ? '' : (chosen.reason ?? t('notenarchiv', 'The rights of this version do not allow this link.'))
})

const blocked = computed(() => circle.value !== 'internal' && (optionsLoading.value || option.value === null || blockReason.value !== ''))

const dialogName = computed(() => (canShareOutside.value
	? t('notenarchiv', 'Share excerpt of {shelfmark}', { shelfmark: props.shelfmark })
	: t('notenarchiv', 'Internal link to {shelfmark}', { shelfmark: props.shelfmark })))

const circleHint = computed(() => {
	switch (circle.value) {
	case 'members':
		return t('notenarchiv', 'Choir members see the excerpt in the music archive under "Shared with me", without the catalog: only these measures with all voices, the voice highlighted. The server cuts the score to these measures and checks the rights on every call.')
	case 'public':
		return t('notenarchiv', 'Anyone who has the link can view and play the excerpt without signing in, until it expires or is revoked: only these measures, without download, without names and without a note. The server cuts the score to these measures and checks the rights on every call.')
	default:
		return t('notenarchiv', 'Only people with a role in the music archive can open the link. It shows the measures with all voices; the voice is highlighted. The server checks the rights on every call.')
	}
})

const yes = t('notenarchiv', 'Yes')
const no = t('notenarchiv', 'No')

/** What the recipients of a link for choir members or the public will see. */
const preview = computed(() => {
	const chosen = option.value
	if (circle.value === 'internal' || chosen === null || blockReason.value !== '') {
		return []
	}
	const from = draft.measureFrom.trim()
	const to = draft.measureTo.trim()
	const rows = [
		{ label: t('notenarchiv', 'Measures'), value: from !== '' && to !== '' ? t('notenarchiv', '{from} to {to}', { from, to }) : t('notenarchiv', 'still missing') },
		{ label: t('notenarchiv', 'Focus voice'), value: draft.voice.trim() || t('notenarchiv', 'none') },
	]
	if (draft.verse.trim() !== '') {
		rows.push({ label: t('notenarchiv', 'Verse'), value: draft.verse.trim() })
	}
	rows.push(
		{ label: t('notenarchiv', 'Tempo'), value: draft.tempo.trim() !== '' ? draft.tempo.trim() + ' %' : t('notenarchiv', 'as in the score') },
		{ label: t('notenarchiv', 'Valid until'), value: formatDate(draft.expires) },
		{ label: t('notenarchiv', 'Playback'), value: chosen.playback ? yes : no },
	)
	if (circle.value === 'members') {
		rows.push({ label: t('notenarchiv', 'Practice files'), value: chosen.practiceAudio ? yes : no })
	}
	return rows
})

// ---- creating -------------------------------------------------------------------

const MEASURE = /^[0-9A-Za-z.]{1,16}$/

async function save(): Promise<void> {
	if (blocked.value) {
		return
	}
	for (const measure of [draft.measureFrom, draft.measureTo]) {
		if (measure.trim() !== '' && !MEASURE.test(measure.trim())) {
			error.value = t('notenarchiv', 'Measure numbers may only contain digits, letters and dots.')
			return
		}
	}
	if (circle.value !== 'internal' && (draft.measureFrom.trim() === '' || draft.measureTo.trim() === '')) {
		error.value = t('notenarchiv', 'Please give the first and the last measure: choir members and the public only see these measures.')
		return
	}
	const verse = draft.verse.trim() === '' ? null : Number(draft.verse)
	const tempo = draft.tempo.trim() === '' ? null : Number(draft.tempo)
	if (verse !== null && (!Number.isInteger(verse) || verse < 1 || verse > 99)) {
		error.value = t('notenarchiv', 'The verse must be a number from 1 to 99.')
		return
	}
	if (tempo !== null && (!Number.isInteger(tempo) || tempo < 20 || tempo > 300)) {
		error.value = t('notenarchiv', 'The tempo must be between 20 and 300 %.')
		return
	}
	if (draft.expires === '' || draft.expires < today || draft.expires > latest) {
		error.value = t('notenarchiv', 'The expiry date must lie between today and six months from now.')
		return
	}
	const link: LinkInput = {
		measure_from: draft.measureFrom.trim() || null,
		measure_to: draft.measureTo.trim() || null,
		voice: draft.voice.trim() || null,
		verse,
		tempo,
		// a public link has no note: everyone would read it
		note: circle.value === 'public' ? null : (draft.note.trim() || null),
		task_id: circle.value === 'internal' ? (props.prefill?.taskId ?? null) : null,
		expires: draft.expires,
		circle: circle.value,
	}
	busy.value = true
	error.value = ''
	try {
		created.value = await createLink(props.copyId, link)
		emit('created', created.value)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

const createdText = computed(() => {
	const link = created.value
	if (!link) {
		return ''
	}
	const date = formatDate(link.expires ?? '')
	switch (link.circle) {
	case 'members':
		return t('notenarchiv', 'Choir members find the excerpt under "Shared with me" until {date}; you can also send them this link. It can be revoked at any time in the list of links.', { date })
	case 'public':
		return t('notenarchiv', 'Anyone with this link can view and play the excerpt until {date}, without signing in. It can be revoked at any time in the list of links.', { date })
	default:
		return t('notenarchiv', 'The link is valid until {date}. It can be revoked at any time.', { date })
	}
})

async function copyLink(): Promise<void> {
	if (await copyText(url.value)) {
		showSuccess(t('notenarchiv', 'The link has been copied.'))
	} else {
		showError(t('notenarchiv', 'The link could not be copied. Please select and copy it by hand.'))
	}
}
</script>

<template>
	<ActionDialog v-if="created === null"
		:name="dialogName"
		:submit-label="t('notenarchiv', 'Create link')"
		:busy="busy"
		:error="error"
		:submit-disabled="blocked"
		size="large"
		@submit="save"
		@close="emit('close')">
		<fieldset v-if="canShareOutside" class="link-dialog__circles">
			<legend>{{ t('notenarchiv', 'Who can open the link?') }}</legend>
			<NcCheckboxRadioSwitch v-model="circle"
				value="internal"
				type="radio"
				name="link-dialog-circle">
				{{ t('notenarchiv', 'Archive roles (internal)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="circle"
				value="members"
				type="radio"
				name="link-dialog-circle">
				{{ t('notenarchiv', 'Choir members') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="circle"
				value="public"
				type="radio"
				name="link-dialog-circle">
				{{ t('notenarchiv', 'Public (without signing in)') }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<p class="action-dialog__hint">
			{{ circleHint }}
		</p>
		<NcNoteCard v-if="circle !== 'internal' && optionsLoading" type="info">
			{{ t('notenarchiv', 'Checking whether this link is possible …') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="blockReason !== ''" type="warning" role="status">
			{{ blockReason }}
		</NcNoteCard>
		<div class="link-dialog__row">
			<NcTextField v-model="draft.measureFrom"
				:label="t('notenarchiv', 'From measure')"
				:required="circle !== 'internal'" />
			<NcTextField v-model="draft.measureTo"
				:label="t('notenarchiv', 'To measure')"
				:required="circle !== 'internal'" />
		</div>
		<div class="link-dialog__row">
			<NcTextField v-model="draft.voice" :label="t('notenarchiv', 'Voice')" />
			<NcTextField v-model="draft.verse"
				type="number"
				min="1"
				max="99"
				:label="t('notenarchiv', 'Verse')" />
			<NcTextField v-model="draft.tempo"
				type="number"
				min="20"
				max="300"
				:label="t('notenarchiv', 'Tempo in % (optional)')" />
		</div>
		<template v-if="circle !== 'public'">
			<NcTextArea v-model="draft.note"
				:label="t('notenarchiv', 'Note (optional)')"
				:helper-text="circle === 'members' ? t('notenarchiv', 'The choir members see this note.') : ''"
				resize="vertical"
				rows="3" />
		</template>
		<DateField v-model="draft.expires"
			:min="today"
			:max="latest"
			required
			:label="t('notenarchiv', 'Valid until (at most six months)')" />
		<section v-if="preview.length > 0" class="link-dialog__preview" :aria-label="t('notenarchiv', 'What the recipients see')">
			<h3 class="link-dialog__preview-title">
				{{ t('notenarchiv', 'What the recipients see') }}
			</h3>
			<dl class="link-dialog__preview-list">
				<div v-for="row in preview" :key="row.label">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
		</section>
	</ActionDialog>

	<NcDialog v-else
		:name="t('notenarchiv', 'Link created')"
		:open="true"
		@update:open="emit('close')">
		<NcNoteCard type="success">
			{{ createdText }}
		</NcNoteCard>
		<NcTextField :model-value="url"
			readonly
			:label="t('notenarchiv', 'Link')" />
		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('notenarchiv', 'Close') }}
			</NcButton>
			<NcButton variant="primary" @click="copyLink">
				<template #icon>
					<NcIconSvgWrapper :path="mdiContentCopy" />
				</template>
				{{ t('notenarchiv', 'Copy link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.link-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.link-dialog__row > * {
	flex: 1 1 9em;
}

.link-dialog__circles {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
}

.link-dialog__circles legend {
	font-weight: bold;
}

.link-dialog__preview {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.link-dialog__preview-title {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 1em;
	font-weight: bold;
}

.link-dialog__preview-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(12em, 1fr));
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
	margin: 0;
}

.link-dialog__preview-list dt {
	color: var(--color-text-maxcontrast);
}

.link-dialog__preview-list dd {
	margin: 0;
	font-weight: bold;
}
</style>
