<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Import of the research list of persons (CSV): always a dry run first with
 * a report, then the import. A repeated import only fills empty values and
 * adds missing contributions; checked persons are never changed.
 */
import type { PersonImportReport } from '../types.ts'

import { mdiFileTableOutline, mdiImport } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { errorMessage, importPersons } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { personOptionLabel } from '../labels.ts'
import { loadDetail } from '../store.ts'
import { showError } from '../toasts.ts'

const emit = defineEmits<{
	busy: [busy: boolean]
}>()

const file = ref<{ id: number, name: string } | null>(null)
const report = ref<PersonImportReport | null>(null)
const busy = ref<'' | 'dry' | 'import'>('')
const error = ref('')

/** Where the focus goes after the file picker and after a run (WCAG 2.4.3) */
const chooseButton = useTemplateRef('chooseButton')
const reportNote = useTemplateRef('reportNote')

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose the list of persons (CSV)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['text/csv'])
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
		showError(t('cantoria', 'The chosen file cannot be used.'))
		await focusAfterDialog(chooseButton)
		return
	}
	file.value = { id: node.fileid, name: node.displayname || node.basename }
	report.value = null
	await run(true)
}

/**
 * @param dryRun - only check and report
 */
async function run(dryRun: boolean): Promise<void> {
	if (!file.value) {
		return
	}
	busy.value = dryRun ? 'dry' : 'import'
	emit('busy', true)
	error.value = ''
	try {
		report.value = await importPersons(file.value.id, dryRun)
		if (!dryRun) {
			showSuccess(t('cantoria', 'The persons have been imported.'))
			await loadDetail()
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = ''
		emit('busy', false)
	}
	// The buttons were disabled while running: the report (or, after an
	// error, the button to choose a file) gets the focus.
	await focusAfterUpdate(() => (error.value === '' ? reportNote.value : chooseButton.value))
}

function reset(): void {
	file.value = null
	report.value = null
	error.value = ''
}

/**
 * Counts per key as "label: count" list.
 *
 * @param field - person field of the keys
 * @param counts - count per key
 */
function distribution(field: string, counts: Record<string, number>): string {
	return Object.entries(counts)
		.map(([key, count]) => `${personOptionLabel(field, key) || key}: ${count}`)
		.join(', ') || '–'
}
</script>

<template>
	<div class="person-import">
		<div class="person-import__actions">
			<NcButton ref="chooseButton" :disabled="busy !== ''" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileTableOutline" />
				</template>
				{{ file ? t('cantoria', 'Choose another file …') : t('cantoria', 'Choose CSV file …') }}
			</NcButton>
			<NcButton v-if="file && report?.dryRun"
				variant="primary"
				:disabled="busy !== ''"
				@click="run(false)">
				<template #icon>
					<NcLoadingIcon v-if="busy === 'import'" />
					<NcIconSvgWrapper v-else :path="mdiImport" />
				</template>
				{{ t('cantoria', 'Import now') }}
			</NcButton>
			<NcButton v-if="file && report && !report.dryRun" variant="tertiary" @click="reset">
				{{ t('cantoria', 'Done') }}
			</NcButton>
		</div>
		<p v-if="file" class="person-import__muted">
			{{ t('cantoria', 'File: {name}', { name: file.name }) }}
		</p>
		<p v-if="busy !== ''" class="person-import__busy">
			<NcLoadingIcon :size="20" />
			{{ busy === 'dry' ? t('cantoria', 'Checking the file (dry run) …') : t('cantoria', 'Importing …') }}
		</p>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<section v-if="report && busy === ''" class="person-import__report" :aria-label="t('cantoria', 'Import report')">
			<NcNoteCard ref="reportNote" :type="report.dryRun ? 'info' : 'success'">
				{{ report.dryRun
					? t('cantoria', 'Dry run – nothing has been changed yet')
					: t('cantoria', 'Import completed') }}
			</NcNoteCard>
			<dl class="person-import__figures">
				<dt>{{ t('cantoria', 'Rows') }}</dt>
				<dd>{{ report.rows }}</dd>
				<dt>{{ t('cantoria', 'New persons') }}</dt>
				<dd>{{ report.created }}</dd>
				<dt>{{ t('cantoria', 'Already in the register') }}</dt>
				<dd>{{ report.existing }}</dd>
				<dt>{{ t('cantoria', 'Supplemented') }}</dt>
				<dd>{{ report.updated }}</dd>
				<dt>{{ t('cantoria', 'Skipped') }}</dt>
				<dd>{{ report.skipped }}</dd>
				<dt>{{ t('cantoria', 'New contributions') }}</dt>
				<dd>{{ report.contributions }}</dd>
				<dt>{{ t('cantoria', 'Contributions already recorded') }}</dt>
				<dd>{{ report.contributionsExisting }}</dd>
				<dt>{{ t('cantoria', 'Date of death') }}</dt>
				<dd>{{ distribution('died_precision', report.precision) }}</dd>
				<dt>{{ t('cantoria', 'Identification') }}</dt>
				<dd>{{ distribution('identification', report.identification) }}</dd>
			</dl>
			<p class="person-import__muted">
				{{ t('cantoria', 'Imported persons are at most "probable"; please check them in the person register. Every shelf mark becomes a contribution with the role "unspecified".') }}
			</p>

			<template v-if="report.renamed.length > 0">
				<h4>{{ n('cantoria', '%n name has been cleaned up', '%n names have been cleaned up', report.renamed.length) }}</h4>
				<ul class="person-import__list">
					<li v-for="item in report.renamed" :key="item.from">
						{{ item.from }} → {{ item.to }}
					</li>
				</ul>
			</template>
			<template v-if="report.unknownShelfmarks.length > 0">
				<h4>{{ n('cantoria', '%n shelf mark is not in the catalog', '%n shelf marks are not in the catalog', report.unknownShelfmarks.length) }}</h4>
				<p class="person-import__marks">
					{{ report.unknownShelfmarks.join(', ') }}
				</p>
			</template>
			<template v-if="report.protectionDiffers.length > 0">
				<h4>{{ n('cantoria', 'Protection end differs for %n person', 'Protection end differs for %n persons', report.protectionDiffers.length) }}</h4>
				<table class="person-import__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('cantoria', 'Name') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'In the list') }}
							</th>
							<th scope="col">
								{{ t('cantoria', 'By the life dates') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in report.protectionDiffers" :key="item.name">
							<td>{{ item.name }}</td>
							<td>{{ item.list }}</td>
							<td>{{ item.computed }}</td>
						</tr>
					</tbody>
				</table>
			</template>
			<template v-if="report.warnings.length > 0">
				<h4>{{ t('cantoria', 'Notes') }}</h4>
				<ul class="person-import__list">
					<li v-for="(warning, index) in report.warnings" :key="index">
						{{ warning }}
					</li>
				</ul>
			</template>
			<p v-if="report.snapshot" class="person-import__muted">
				{{ t('cantoria', 'The previous state was saved as a snapshot: {path}', { path: report.snapshot }) }}
			</p>
			<p class="person-import__muted">
				{{ t('cantoria', 'Checksum (SHA-256): {sha256}', { sha256: report.sha256 }) }}
			</p>
		</section>
	</div>
</template>

<style scoped>
.person-import {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__report {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.person-import__figures {
	display: grid;
	grid-template-columns: max-content auto;
	gap: 2px calc(4 * var(--default-grid-baseline));
	margin: 0;
}

.person-import__figures dt {
	color: var(--color-text-maxcontrast);
}

.person-import__figures dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.person-import__report h4 {
	margin: 0;
	font-weight: bold;
}

.person-import__list {
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
	overflow-wrap: anywhere;
}

.person-import__table {
	border-collapse: collapse;
	/* Nextcloud keeps table cells on one line; names wrap here */
	white-space: normal;
}

.person-import__table th,
.person-import__table td {
	padding: 2px calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.person-import__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.person-import__marks {
	margin: 0;
	overflow-wrap: anywhere;
}

.person-import__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>
