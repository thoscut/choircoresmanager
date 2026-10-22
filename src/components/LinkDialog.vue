<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create an internal excerpt link: measures, voice, verse, tempo and a note,
 * valid for at most six months. Only logged-in holders of an archive role
 * can open it; the server checks the rights on every call. After creating,
 * the link can be copied.
 */
import type { ExcerptLink, LinkInput } from '../types.ts'

import { mdiContentCopy } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createLink, errorMessage, linkUrl } from '../api.ts'
import { copyText } from '../clipboard.ts'
import { formatDate, toIsoDate } from '../format.ts'

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
const url = computed(() => (created.value ? linkUrl(created.value.token) : ''))

const MEASURE = /^[0-9A-Za-z.]{1,16}$/

async function save(): Promise<void> {
	for (const measure of [draft.measureFrom, draft.measureTo]) {
		if (measure.trim() !== '' && !MEASURE.test(measure.trim())) {
			error.value = t('notenarchiv', 'Measure numbers may only contain digits, letters and dots.')
			return
		}
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
		note: draft.note.trim() || null,
		task_id: props.prefill?.taskId ?? null,
		expires: draft.expires,
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
		:name="t('notenarchiv', 'Internal link to {shelfmark}', { shelfmark })"
		:submit-label="t('notenarchiv', 'Create link')"
		:busy="busy"
		:error="error"
		size="large"
		@submit="save"
		@close="emit('close')">
		<p class="action-dialog__hint">
			{{ t('notenarchiv', 'Only people with a role in the music archive can open the link. It shows the measures with all voices; the voice is highlighted. The server checks the rights on every call.') }}
		</p>
		<div class="link-dialog__row">
			<NcTextField v-model="draft.measureFrom" :label="t('notenarchiv', 'From measure')" />
			<NcTextField v-model="draft.measureTo" :label="t('notenarchiv', 'To measure')" />
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
		<NcTextArea v-model="draft.note"
			:label="t('notenarchiv', 'Note (optional)')"
			resize="vertical"
			rows="3" />
		<DateField v-model="draft.expires"
			:min="today"
			:max="latest"
			required
			:label="t('notenarchiv', 'Valid until (at most six months)')" />
	</ActionDialog>

	<NcDialog v-else
		:name="t('notenarchiv', 'Link created')"
		:open="true"
		@update:open="emit('close')">
		<NcNoteCard type="success">
			{{ t('notenarchiv', 'The link is valid until {date}. It can be revoked at any time.', { date: formatDate(created.expires ?? '') }) }}
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
</style>
