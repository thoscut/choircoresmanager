<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Import of documented performances and song lists from a spreadsheet
 * (.xlsx or .csv): always a dry run first with a report, including the own
 * choir as detected in the file (can be corrected), then the import. A new
 * import replaces the previously imported events; planned ones stay.
 */
import type { PerformanceImportReport } from '../types.ts'

import { mdiFileTableOutline, mdiImport, mdiRefresh } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, importPerformances } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { loadEntries } from '../store.ts'
import { showError } from '../toasts.ts'

const emit = defineEmits<{
	busy: [busy: boolean]
}>()

const file = ref<{ id: number, name: string } | null>(null)
const report = ref<PerformanceImportReport | null>(null)
const ownChoir = ref('')
const busy = ref(false)
/** the running request is a dry run */
const dryRunning = ref(false)
const error = ref('')

/** Where the focus goes after the file picker and after a run (WCAG 2.4.3) */
const chooseButton = useTemplateRef('chooseButton')
const reportSection = useTemplateRef<HTMLElement>('reportSection')

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('notenarchiv', 'Choose the list of performances (.xlsx or .csv)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (caught) {
		if (!(caught instanceof FilePickerClosed)) {
			showError(errorMessage(caught))
		}
		await focusAfterDialog(chooseButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('notenarchiv', 'The chosen file cannot be used.'))
		await focusAfterDialog(chooseButton)
		return
	}
	file.value = { id: node.fileid, name: node.displayname || node.basename }
	report.value = null
	ownChoir.value = ''
	await run(true)
}

/**
 * @param dryRun - only check and report
 */
async function run(dryRun: boolean): Promise<void> {
	if (!file.value) {
		return
	}
	busy.value = true
	dryRunning.value = dryRun
	emit('busy', true)
	error.value = ''
	try {
		report.value = await importPerformances(file.value.id, dryRun, ownChoir.value.trim() === '' ? null : ownChoir.value.trim())
		if (dryRun && ownChoir.value === '' && report.value.ownChoir) {
			ownChoir.value = report.value.ownChoir
		}
		if (!dryRun) {
			showSuccess(t('notenarchiv', 'The performances have been imported.'))
			await loadEntries()
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
		emit('busy', false)
	}
	// The buttons were disabled while running: the report (or, after an
	// error, the button to choose a file) gets the focus.
	await focusAfterUpdate(() => (error.value === '' ? reportSection.value?.querySelector('.notecard') : chooseButton.value))
}

function reset(): void {
	file.value = null
	report.value = null
	ownChoir.value = ''
	error.value = ''
}
</script>

<template>
	<div class="performance-import">
		<div class="performance-import__actions">
			<NcButton ref="chooseButton" :disabled="busy" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileTableOutline" />
				</template>
				{{ file ? t('notenarchiv', 'Choose another file …') : t('notenarchiv', 'Choose file …') }}
			</NcButton>
			<NcButton v-if="file && report?.dryRun"
				variant="primary"
				:disabled="busy"
				@click="run(false)">
				<template #icon>
					<NcLoadingIcon v-if="busy" />
					<NcIconSvgWrapper v-else :path="mdiImport" />
				</template>
				{{ t('notenarchiv', 'Import now') }}
			</NcButton>
			<NcButton v-if="file && report && !report.dryRun" variant="tertiary" @click="reset">
				{{ t('notenarchiv', 'Done') }}
			</NcButton>
		</div>
		<p v-if="file" class="performance-import__muted">
			{{ t('notenarchiv', 'File: {name}', { name: file.name }) }}
		</p>
		<p v-if="busy" class="performance-import__busy">
			<NcLoadingIcon :size="20" />
			{{ dryRunning ? t('notenarchiv', 'Checking the file (dry run) …') : t('notenarchiv', 'Importing …') }}
		</p>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<section v-if="report && !busy"
			ref="reportSection"
			class="performance-import__report"
			:aria-label="t('notenarchiv', 'Import report')">
			<NcNoteCard :type="report.dryRun ? 'info' : 'success'">
				{{ report.dryRun
					? t('notenarchiv', 'Dry run – nothing has been changed yet')
					: t('notenarchiv', 'Import completed') }}
			</NcNoteCard>
			<form v-if="report.dryRun" class="performance-import__choir" @submit.prevent="run(true)">
				<NcTextField v-model="ownChoir"
					:label="t('notenarchiv', 'Own choir in the file')"
					:helper-text="t('notenarchiv', 'Only lines of this choir count as performances. Correct the name if it was detected wrongly and check again.')" />
				<NcButton type="submit" :disabled="busy">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('notenarchiv', 'Check again') }}
				</NcButton>
			</form>
			<dl class="performance-import__figures">
				<dt>{{ t('notenarchiv', 'Concerts') }}</dt>
				<dd>{{ report.events }}</dd>
				<dt>{{ t('notenarchiv', 'Song lists') }}</dt>
				<dd>{{ report.songLists }}</dd>
				<dt>{{ t('notenarchiv', 'Programme lines') }}</dt>
				<dd>{{ report.items }}</dd>
				<dt>{{ t('notenarchiv', 'Assigned to a copy') }}</dt>
				<dd>{{ report.certain }}</dd>
				<dt>{{ t('notenarchiv', 'Shelf mark unclear') }}</dt>
				<dd>{{ report.ambiguous }}</dd>
				<dt>{{ t('notenarchiv', 'Not in the archive') }}</dt>
				<dd>{{ report.notInCatalog }}</dd>
				<dt>{{ t('notenarchiv', 'Counted as performances') }}</dt>
				<dd>{{ report.counted }}</dd>
				<dt>{{ t('notenarchiv', 'Sheets read') }}</dt>
				<dd>{{ report.sheets.join(', ') || '–' }}</dd>
			</dl>
			<template v-if="report.unknownShelfmarks.length > 0">
				<h4>{{ n('notenarchiv', '%n shelf mark is not in the catalog', '%n shelf marks are not in the catalog', report.unknownShelfmarks.length) }}</h4>
				<p class="performance-import__marks">
					{{ report.unknownShelfmarks.join(', ') }}
				</p>
			</template>
			<p class="performance-import__muted">
				{{ t('notenarchiv', 'A new import replaces the previously imported concerts and song lists. Planned events are never touched.') }}
			</p>
			<p v-if="report.snapshot" class="performance-import__muted">
				{{ t('notenarchiv', 'The previous state was saved as a snapshot: {path}', { path: report.snapshot }) }}
			</p>
			<p class="performance-import__muted">
				{{ t('notenarchiv', 'Checksum (SHA-256): {sha256}', { sha256: report.sha256 }) }}
			</p>
		</section>
	</div>
</template>

<style scoped>
.performance-import {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.performance-import__actions,
.performance-import__choir {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.performance-import__choir > :first-child {
	flex: 1 1 18em;
}

.performance-import__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.performance-import__report {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.performance-import__figures {
	display: grid;
	grid-template-columns: max-content auto;
	gap: 2px calc(4 * var(--default-grid-baseline));
	margin: 0;
}

.performance-import__figures dt {
	color: var(--color-text-maxcontrast);
}

.performance-import__figures dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.performance-import__report h4 {
	margin: 0;
	font-weight: bold;
}

.performance-import__marks {
	margin: 0;
	overflow-wrap: anywhere;
}

.performance-import__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>
