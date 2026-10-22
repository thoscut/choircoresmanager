<template>
	<NcDialog
		:name="t('Import catalogue')"
		:open="open"
		size="large"
		@update:open="onUpdateOpen">
		<div class="catalogue-import">
			<p>
				{{ t('Select the catalogue as a CSV file (semicolon-separated, UTF-8). A dry run first shows what would change. Nothing is saved until you click Apply.') }}
			</p>
			<p class="catalogue-import__hint">
				{{ t('Rows are matched to scores by their signature. Scores that are not in the file stay unchanged, nothing is deleted.') }}
			</p>

			<div class="catalogue-import__file">
				<input
					ref="fileInput"
					type="file"
					accept=".csv,text/csv"
					class="catalogue-import__input"
					@change="onFileSelected">
				<NcButton :disabled="busy" @click="fileInput?.click()">
					<template #icon>
						<UploadIcon :size="20" />
					</template>
					{{ t('Choose CSV file') }}
				</NcButton>
				<span v-if="fileName" class="catalogue-import__file-name">{{ fileName }}</span>
			</div>

			<NcLoadingIcon v-if="busy" :size="32" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<template v-else-if="result">
				<NcNoteCard v-if="applied" type="success">
					{{ t('The catalogue has been imported.') }}
				</NcNoteCard>
				<h3 class="catalogue-import__heading">
					{{ applied ? t('Result') : t('Dry run') }}
				</h3>
				<ul class="catalogue-import__counts">
					<li>{{ t('New: {count}', { count: result.counts.new }) }}</li>
					<li>{{ t('Changed: {count}', { count: result.counts.changed }) }}</li>
					<li>{{ t('Unchanged: {count}', { count: result.counts.unchanged }) }}</li>
					<li :class="{ 'catalogue-import__counts--error': result.counts.errors > 0 }">
						{{ t('With errors: {count}', { count: result.counts.errors }) }}
					</li>
				</ul>
				<NcNoteCard v-if="result.counts.errors > 0" type="warning">
					{{ t('Rows with errors are not applied. Correct them in the file and choose it again.') }}
				</NcNoteCard>

				<details class="catalogue-import__columns">
					<summary>{{ t('How the columns are stored') }}</summary>
					<p>
						<strong>{{ t('In score fields:') }}</strong>
						{{ result.mappedColumns.join(', ') }}
					</p>
					<p>
						<strong>{{ t('Verbatim as further catalogue data:') }}</strong>
						{{ result.extraColumns.length > 0 ? result.extraColumns.join(', ') : '–' }}
					</p>
				</details>

				<ul class="catalogue-import__rows">
					<li
						v-for="row in sortedRows"
						:key="row.line"
						:class="`catalogue-import__row catalogue-import__row--${row.status}`">
						<details>
							<summary>
								<span class="catalogue-import__status">{{ statusLabel(row.status) }}</span>
								{{ t('Line {line}', { line: row.line }) }} · {{ row.signature || '–' }} · {{ row.title }}
							</summary>
							<ul v-if="row.errors.length > 0" class="catalogue-import__errors">
								<li v-for="message in row.errors" :key="message">
									{{ message }}
								</li>
							</ul>
							<table v-if="row.changes.length > 0" class="catalogue-import__changes">
								<thead>
									<tr>
										<th>{{ t('Column') }}</th>
										<th>{{ t('Before') }}</th>
										<th>{{ t('After') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="change in row.changes" :key="change.column">
										<td>{{ change.column }}</td>
										<td class="catalogue-import__value">
											{{ change.old }}
										</td>
										<td class="catalogue-import__value">
											{{ change.new }}
										</td>
									</tr>
								</tbody>
							</table>
						</details>
					</li>
				</ul>
			</template>
		</div>

		<template #actions>
			<NcButton @click="onUpdateOpen(false)">
				{{ applied ? t('Close') : t('Cancel') }}
			</NcButton>
			<NcButton
				v-if="!applied"
				variant="primary"
				:disabled="!canApply"
				@click="onApply">
				<template #icon>
					<ConfirmIcon />
				</template>
				{{ t('Apply') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { CatalogueImportResult, CatalogueImportRow } from '@/api/generated/openapi/data-contracts'

import { showSuccess } from '@nextcloud/dialogs'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ConfirmIcon, UploadIcon } from '@/icons/vue-material'
import { useCatalogueStore } from '@/stores/catalogueStore'
import { useScoreSidebarStore } from '@/stores/scoreSidebarStore'
import { useScoresStore } from '@/stores/scoresStore'
import { extractErrorMessage } from '@/utils/errorHandling'
import { readUtf8File } from '@/utils/fileUtils'
import { t } from '@/utils/l10n'

interface Props {
	/** Whether the dialog is open */
	open: boolean
}

defineProps<Props>()

const emit = defineEmits<{
	'update:open': [value: boolean]
}>()

const catalogueStore = useCatalogueStore()
const scoresStore = useScoresStore()
const scoreSidebarStore = useScoreSidebarStore()

const fileInput = ref<HTMLInputElement | null>(null)
const fileName = ref('')
const csv = ref<string | null>(null)
const result = ref<CatalogueImportResult | null>(null)
const applied = ref(false)
const busy = ref(false)
const error = ref('')

const STATUS_ORDER: CatalogueImportRow['status'][] = ['error', 'new', 'changed']

const sortedRows = computed((): CatalogueImportRow[] => {
	return [...(result.value?.rows ?? [])].sort((a, b) => STATUS_ORDER.indexOf(a.status) - STATUS_ORDER.indexOf(b.status) || a.line - b.line)
})

const canApply = computed((): boolean => {
	return !busy.value && !applied.value && csv.value !== null && result.value !== null
		&& result.value.counts.new + result.value.counts.changed > 0
})

/**
 * Get the translated label of a row status
 *
 * @param status - The row status
 */
function statusLabel(status: CatalogueImportRow['status']): string {
	switch (status) {
		case 'new':
			return t('New')
		case 'changed':
			return t('Changed')
		default:
			return t('Error')
	}
}

/**
 * Forget the selected file and the last result
 */
function reset(): void {
	fileName.value = ''
	csv.value = null
	result.value = null
	applied.value = false
	error.value = ''
}

/**
 * Open or close the dialog; closing resets it
 *
 * @param value - Whether the dialog should be open
 */
function onUpdateOpen(value: boolean): void {
	if (!value) {
		if (busy.value) {
			return
		}
		reset()
	}
	emit('update:open', value)
}

/**
 * Read the selected file and run the dry run
 *
 * @param event - The change event of the file input
 */
async function onFileSelected(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	// allow choosing the same file again after correcting it
	input.value = ''
	if (!file) {
		return
	}

	reset()
	fileName.value = file.name
	busy.value = true
	try {
		try {
			csv.value = await readUtf8File(file)
		} catch {
			error.value = t('The file is not valid UTF-8. Save it as "CSV UTF-8" and try again.')
			return
		}
		result.value = await catalogueStore.previewImport(csv.value)
	} catch (e) {
		error.value = extractErrorMessage(e)
	} finally {
		busy.value = false
	}
}

/**
 * Apply the import of the selected file
 */
async function onApply(): Promise<void> {
	if (!canApply.value || csv.value === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		result.value = await catalogueStore.applyImport(csv.value)
		applied.value = true
		showSuccess(t('Catalogue imported: {new} new, {changed} changed', {
			new: result.value.counts.new,
			changed: result.value.counts.changed,
		}))
		const selected = scoreSidebarStore.selectedScore
		if (selected) {
			const reloaded = scoresStore.getScoreById(selected.id)
			if (reloaded) {
				scoreSidebarStore.updateSelectedScore(reloaded)
			}
		}
	} catch (e) {
		error.value = extractErrorMessage(e)
	} finally {
		busy.value = false
	}
}
</script>

<style lang="scss" scoped>
.catalogue-import {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding-bottom: calc(2 * var(--default-grid-baseline));
}

.catalogue-import__hint {
	color: var(--color-text-maxcontrast);
}

.catalogue-import__file {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.catalogue-import__input {
	display: none;
}

.catalogue-import__file-name {
	overflow-wrap: anywhere;
}

.catalogue-import__heading {
	margin: 0;
}

.catalogue-import__counts {
	display: flex;
	flex-wrap: wrap;
	gap: calc(4 * var(--default-grid-baseline));
}

.catalogue-import__counts--error {
	color: var(--color-text-error, var(--color-error));
	font-weight: bold;
}

.catalogue-import__rows {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.catalogue-import__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));

	summary {
		cursor: pointer;
	}
}

.catalogue-import__row--error {
	border-color: var(--color-error);
}

.catalogue-import__status {
	display: inline-block;
	min-width: 80px;
	font-weight: bold;
}

.catalogue-import__row--error .catalogue-import__status {
	color: var(--color-text-error, var(--color-error));
}

.catalogue-import__errors {
	margin: var(--default-grid-baseline) 0;
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
}

.catalogue-import__changes {
	width: 100%;
	margin-top: var(--default-grid-baseline);
	border-collapse: collapse;

	th,
	td {
		padding: 2px var(--default-grid-baseline);
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
	}
}

.catalogue-import__value {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
