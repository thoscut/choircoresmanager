<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create or change a choir folder of the folder book: number or name, voice,
 * the copies it holds with their number, condition, missing sheets and a
 * note. A folder is never deleted; it can be taken out of service (with a
 * reason, only when it is back) and put back into service.
 */
import type { Folder, FolderInput, FolderWear } from '../folderBook.ts'

import { mdiDeleteOutline, mdiPlus } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import { errorMessage } from '../api.ts'
import { contentsInput, createFolder, updateFolder, WEARS, wearLabel } from '../folderBook.ts'
import { entries } from '../store.ts'

const props = defineProps<{
	/** the folder to change; null for a new folder */
	folder: Folder | null
}>()

const emit = defineEmits<{
	saved: []
	close: []
}>()

interface CopyOption {
	id: number
	label: string
}

/** Every copy of the catalog, by shelf mark and title. */
const copyOptions = computed<CopyOption[]>(() => entries.value.map((entry) => ({
	id: entry.id,
	label: entry.status === 'active' ? `${entry.shelfmark} ${entry.title ?? ''}`.trim() : `${entry.shelfmark} ${entry.title ?? ''} (${t('cantoria', 'not active')})`,
})))

const draft = reactive({
	label: props.folder?.label ?? '',
	voice: props.folder?.voice ?? '',
	wear: (props.folder?.wear ?? 'good') as FolderWear,
	missing: props.folder?.missing ?? '',
	note: props.folder?.note ?? '',
	retire: false,
	reactivate: false,
	statusReason: '',
})

/** Rows of the contents: a copy and its number as typed. */
const rows = ref<{ key: number, copy: CopyOption | null, count: string }[]>((props.folder?.contents ?? []).map((item, index) => ({
	key: index,
	copy: { id: item.copyId, label: `${item.shelfmark} ${item.title ?? ''}`.trim() },
	count: String(item.count),
})))
let nextKey = rows.value.length

function addRow(): void {
	rows.value.push({ key: nextKey++, copy: null, count: '1' })
}

/**
 * Remove a row of the contents.
 *
 * @param key - key of the row
 */
function removeRow(key: number): void {
	rows.value = rows.value.filter((row) => row.key !== key)
}

if (rows.value.length === 0) {
	addRow()
}

const retired = computed(() => props.folder?.status === 'retired')
const out = computed(() => props.folder?.loan !== null && props.folder?.loan !== undefined)

const busy = ref(false)
const error = ref('')

/**
 * Text or null for empty input.
 *
 * @param value - input
 */
function orNull(value: string): string | null {
	return value.trim() === '' ? null : value.trim()
}

async function save(): Promise<void> {
	error.value = ''
	if (draft.label.trim() === '') {
		error.value = t('cantoria', 'Please give the folder a number or name.')
		return
	}
	const contents = contentsInput(rows.value.map((row) => ({ copyId: row.copy?.id ?? null, count: row.count })))
	if ('error' in contents) {
		error.value = contents.error
		return
	}
	if (draft.retire && draft.statusReason.trim() === '') {
		error.value = t('cantoria', 'Please give a reason for taking the folder out of service.')
		return
	}
	const input: FolderInput = {
		label: draft.label.trim(),
		voice: orNull(draft.voice),
		contents: contents.contents,
		wear: draft.wear,
		missing: orNull(draft.missing),
		note: orNull(draft.note),
	}
	if (draft.retire) {
		input.status = 'retired'
		input.statusReason = draft.statusReason.trim()
	} else if (draft.reactivate) {
		input.status = 'active'
	}
	busy.value = true
	try {
		if (props.folder) {
			await updateFolder(props.folder.id, input)
		} else {
			await createFolder(input)
		}
		emit('saved')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="folder ? t('cantoria', 'Edit folder {label}', { label: folder.label }) : t('cantoria', 'New folder')"
		:submit-label="t('cantoria', 'Save')"
		:busy="busy"
		:error="error"
		size="large"
		@submit="save"
		@close="emit('close')">
		<div class="folder-dialog__row">
			<NcTextField v-model="draft.label"
				:label="t('cantoria', 'Number or name (required)')"
				required
				maxlength="64" />
			<NcTextField v-model="draft.voice"
				:label="t('cantoria', 'Voice or section, e.g. Tenor I')"
				maxlength="64" />
		</div>

		<fieldset class="folder-dialog__group">
			<legend>{{ t('cantoria', 'Contents: copies from the catalog') }}</legend>
			<div v-for="(row, index) in rows" :key="row.key" class="folder-dialog__item">
				<NcSelect v-model="row.copy"
					class="folder-dialog__copy"
					:input-label="t('cantoria', 'Copy {number}', { number: index + 1 })"
					:placeholder="t('cantoria', 'Shelf mark or title')"
					:options="copyOptions" />
				<NcTextField v-model="row.count"
					class="folder-dialog__count"
					type="number"
					inputmode="numeric"
					min="1"
					max="999"
					step="1"
					:label="t('cantoria', 'Number')" />
				<NcButton variant="tertiary"
					:aria-label="t('cantoria', 'Remove copy {number}', { number: index + 1 })"
					@click="removeRow(row.key)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDeleteOutline" />
					</template>
				</NcButton>
			</div>
			<NcButton @click="addRow">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('cantoria', 'Add a copy') }}
			</NcButton>
		</fieldset>

		<fieldset class="folder-dialog__group">
			<legend>{{ t('cantoria', 'Condition') }}</legend>
			<NcCheckboxRadioSwitch v-for="wear in WEARS"
				:key="wear"
				v-model="draft.wear"
				type="radio"
				:value="wear"
				name="folder-wear">
				{{ wearLabel(wear) }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcTextArea v-model="draft.missing"
			:label="t('cantoria', 'Missing sheets at the moment')"
			:helper-text="t('cantoria', 'For example: 2-35, page 3. Empty when nothing is missing.')"
			resize="vertical"
			rows="2" />
		<NcTextArea v-model="draft.note"
			:label="t('cantoria', 'Note')"
			:helper-text="t('cantoria', 'About the folder only. Notes about a person belong to the loan, where they are removed after the retention period.')"
			resize="vertical"
			rows="2" />

		<template v-if="folder && !retired">
			<NcCheckboxRadioSwitch v-model="draft.retire" type="switch" :disabled="out">
				{{ t('cantoria', 'Take this folder out of service') }}
			</NcCheckboxRadioSwitch>
			<p v-if="out" class="action-dialog__hint">
				{{ t('cantoria', 'The folder is out. Take it back first, then you can take it out of service.') }}
			</p>
			<NcTextField v-if="draft.retire"
				v-model="draft.statusReason"
				:label="t('cantoria', 'Reason (required)')"
				required />
		</template>
		<NcCheckboxRadioSwitch v-if="folder && retired" v-model="draft.reactivate" type="switch">
			{{ t('cantoria', 'Put this folder back into service') }}
		</NcCheckboxRadioSwitch>
		<p class="action-dialog__hint">
			{{ t('cantoria', 'Folders are not deleted. A folder out of service stays in the folder book with its loans.') }}
		</p>
	</ActionDialog>
</template>

<style scoped>
.folder-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.folder-dialog__row > * {
	flex: 1 1 14em;
}

.folder-dialog__group {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	border: none;
}

.folder-dialog__group legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}

.folder-dialog__item {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: var(--default-grid-baseline);
}

.folder-dialog__copy {
	flex: 1 1 18em;
}

.folder-dialog__count {
	flex: 0 0 7em;
}
</style>
