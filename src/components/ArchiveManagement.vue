<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Archive management for the librarian role: import the catalog from a CSV
 * file (always a dry run first), import performances and song lists and the
 * list of persons, sync files with the archive folder, export, backup and
 * restore.
 */
import type { FileSyncReport, ImportReport as ImportReportData } from '../types.ts'

import {
	mdiDownload,
	mdiFileSyncOutline,
	mdiFileTableOutline,
	mdiFolderDownloadOutline,
	mdiImport,
	mdiShieldCheckOutline,
} from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import BackupRestore from './BackupRestore.vue'
import FileSyncReportView from './FileSyncReport.vue'
import ImportReport from './ImportReport.vue'
import PerformanceImport from './PerformanceImport.vue'
import PersonImport from './PersonImport.vue'
import { adminSettingsUrl, errorMessage, exportUrl, runFileSync, runImport, storeBackup, storeExport } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import {
	archiveDialogOpen,
	canChangeSettings,
	entries,
	entriesStatus,
	hasArchiveFolder,
	isModuleActive,
	loadDetail,
	loadEntries,
	meta,
	refreshPortalRows,
	reloadMeta,
} from '../store.ts'

/** The catalog has copies: a restore would be refused. */
const catalogFilled = computed(() => entriesStatus.value === 'ready' && entries.value.length > 0)

/** After a restore: the app shows the restored catalog. */
async function onRestored(): Promise<void> {
	await reloadMeta()
	await loadEntries()
	await refreshPortalRows()
}

// ---- import ----

const importFile = ref<{ id: number, name: string } | null>(null)
const importReport = ref<ImportReportData | null>(null)
const importBusy = ref(false)
const importError = ref('')

/** Where the focus goes after the file picker and after actions (WCAG 2.4.3) */
const importButton = useTemplateRef('importButton')
const importSection = useTemplateRef<HTMLElement>('importSection')
const syncButton = useTemplateRef('syncButton')
const syncSection = useTemplateRef<HTMLElement>('syncSection')
const storeButton = useTemplateRef('storeButton')
const backupButton = useTemplateRef('backupButton')

async function chooseCsv(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('notenarchiv', 'Choose the catalog file (CSV)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['text/csv'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			showError(errorMessage(error))
		}
		await focusAfterDialog(importButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('notenarchiv', 'The chosen file cannot be used.'))
		await focusAfterDialog(importButton)
		return
	}
	importFile.value = { id: node.fileid, name: node.displayname || node.basename }
	importReport.value = null
	await doImport(true)
}

/**
 * Run the import.
 *
 * @param dryRun - true: only check and report, false: really import
 */
async function doImport(dryRun: boolean): Promise<void> {
	if (!importFile.value) {
		return
	}
	importBusy.value = true
	importError.value = ''
	try {
		importReport.value = await runImport(importFile.value.id, dryRun)
		if (!dryRun) {
			showSuccess(t('notenarchiv', 'The catalog has been imported.'))
			await loadEntries()
			await loadDetail()
		}
	} catch (error) {
		importError.value = errorMessage(error)
	} finally {
		importBusy.value = false
	}
	// The buttons were disabled while running: the report (or, after an
	// error, the button to choose a file) gets the focus.
	await focusAfterUpdate(() => (importError.value === '' ? importSection.value?.querySelector('.import-report .notecard') : importButton.value))
}

function resetImport(): void {
	importFile.value = null
	importReport.value = null
	importError.value = ''
}

// ---- file sync ----

const syncReport = ref<FileSyncReport | null>(null)
const syncBusy = ref(false)
const syncError = ref('')

async function doFileSync(): Promise<void> {
	syncBusy.value = true
	syncError.value = ''
	try {
		syncReport.value = await runFileSync()
		await loadEntries()
		await loadDetail()
	} catch (error) {
		syncError.value = errorMessage(error)
	} finally {
		syncBusy.value = false
	}
	await focusAfterUpdate(() => (syncError.value === '' ? syncSection.value?.querySelector('.file-sync-report .notecard') : syncButton.value))
}

// ---- export ----

const storedPath = ref('')
const storeBusy = ref(false)

async function doStoreExport(): Promise<void> {
	storeBusy.value = true
	try {
		storedPath.value = await storeExport()
		showSuccess(t('notenarchiv', 'The export was stored in the archive folder.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		storeBusy.value = false
	}
	await focusAfterUpdate(storeButton)
}

// ---- backup ----

const backupPaths = ref<string[]>([])
const backupAccount = ref('')
const backupBusy = ref(false)

async function doBackup(): Promise<void> {
	backupBusy.value = true
	try {
		const stored = await storeBackup()
		backupPaths.value = stored.paths
		backupAccount.value = stored.account
		showSuccess(t('notenarchiv', 'The backup has been stored in the backup folder.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		backupBusy.value = false
	}
	await focusAfterUpdate(backupButton)
}

// ---- performance import ----

const performanceBusy = ref(false)
const personBusy = ref(false)
const restoreBusy = ref(false)

/**
 * The dialog asks to close.
 *
 * @param open - new open state
 */
function onUpdateOpen(open: boolean): void {
	if (!open && !importBusy.value && !syncBusy.value && !performanceBusy.value && !personBusy.value && !restoreBusy.value) {
		archiveDialogOpen.value = false
	}
}
</script>

<template>
	<NcDialog :name="t('notenarchiv', 'Archive management')"
		:open="true"
		size="large"
		:no-close="importBusy || syncBusy || performanceBusy || personBusy || restoreBusy"
		@update:open="onUpdateOpen">
		<NcNoteCard v-if="!hasArchiveFolder" type="warning">
			<p>{{ t('notenarchiv', 'No archive folder has been chosen yet. File sync and stored exports need it; an administrator sets it in the settings.') }}</p>
			<NcButton v-if="canChangeSettings" :href="adminSettingsUrl()">
				{{ t('notenarchiv', 'Open settings') }}
			</NcButton>
		</NcNoteCard>
		<p v-else class="archive-management__muted">
			{{ t('notenarchiv', 'Archive folder: {path}', { path: meta?.archive.path ?? '' }) }}
		</p>

		<!-- Import -->
		<section ref="importSection" class="archive-management__section" aria-labelledby="archive-import">
			<h3 id="archive-import">
				{{ t('notenarchiv', 'Import catalog') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'Imports the catalog from a CSV file in your Nextcloud. First a dry run shows what would change; only then you decide. Copies are matched by shelf mark, nothing is deleted, and before every import a snapshot of the current state is saved. The import can be repeated.') }}
			</p>

			<div class="archive-management__actions">
				<NcButton ref="importButton" :disabled="importBusy" @click="chooseCsv">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileTableOutline" />
					</template>
					{{ importFile ? t('notenarchiv', 'Choose another file …') : t('notenarchiv', 'Choose CSV file …') }}
				</NcButton>
				<NcButton v-if="importFile && importReport?.dryRun"
					variant="primary"
					:disabled="importBusy"
					@click="doImport(false)">
					<template #icon>
						<NcLoadingIcon v-if="importBusy" />
						<NcIconSvgWrapper v-else :path="mdiImport" />
					</template>
					{{ t('notenarchiv', 'Import now') }}
				</NcButton>
				<NcButton v-if="importFile && importReport && !importReport.dryRun"
					variant="tertiary"
					@click="resetImport">
					{{ t('notenarchiv', 'Done') }}
				</NcButton>
			</div>

			<p v-if="importFile" class="archive-management__muted">
				{{ t('notenarchiv', 'File: {name}', { name: importFile.name }) }}
			</p>
			<p v-if="importBusy" class="archive-management__busy">
				<NcLoadingIcon :size="20" />
				{{ importReport?.dryRun ? t('notenarchiv', 'Importing …') : t('notenarchiv', 'Checking the file (dry run) …') }}
			</p>
			<NcNoteCard v-if="importError" type="error">
				{{ importError }}
			</NcNoteCard>
			<ImportReport v-if="importReport && !importBusy" :report="importReport" />
		</section>

		<!-- Performances and song lists (module "performance_import") -->
		<section v-if="isModuleActive('performance_import')" class="archive-management__section" aria-labelledby="archive-performances">
			<h3 id="archive-performances">
				{{ t('notenarchiv', 'Import performances and song lists') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'Imports the history of concerts and song lists from a spreadsheet (.xlsx or .csv). First a dry run shows what would be imported. Lines with an unclear shelf mark can be assigned afterwards under "Unclear programme lines".') }}
			</p>
			<PerformanceImport @busy="performanceBusy = $event" />
		</section>

		<!-- Persons (module "persons") -->
		<section v-if="isModuleActive('persons')" class="archive-management__section" aria-labelledby="archive-persons">
			<h3 id="archive-persons">
				{{ t('notenarchiv', 'Import persons') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'Imports the research list of composers, lyricists and arrangers (CSV with the columns Name; Werke; Signaturen; Lebensdaten laut Verzeichnis; Lebensdaten recherchiert; Schutz bis; Status; Quelle; Geprueft am). First a dry run shows what would be imported. A repeated import only fills empty values; checked persons are never changed.') }}
			</p>
			<PersonImport @busy="personBusy = $event" />
		</section>

		<!-- File sync -->
		<section ref="syncSection" class="archive-management__section" aria-labelledby="archive-file-sync">
			<h3 id="archive-file-sync">
				{{ t('notenarchiv', 'File sync') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'Links the files in the archive folder to the copies. A file belongs to a copy if its name starts with the shelf mark, e.g. "6-08_Title.pdf" belongs to 6-8. Files are only read, never changed.') }}
			</p>
			<div class="archive-management__actions">
				<NcButton ref="syncButton" :disabled="syncBusy || !hasArchiveFolder" @click="doFileSync">
					<template #icon>
						<NcLoadingIcon v-if="syncBusy" />
						<NcIconSvgWrapper v-else :path="mdiFileSyncOutline" />
					</template>
					{{ t('notenarchiv', 'Run file sync') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="syncError" type="error">
				{{ syncError }}
			</NcNoteCard>
			<FileSyncReportView v-if="syncReport && !syncBusy" :report="syncReport" />
		</section>

		<!-- Export -->
		<section class="archive-management__section" aria-labelledby="archive-export">
			<h3 id="archive-export">
				{{ t('notenarchiv', 'Export') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'The catalog as a CSV file (UTF-8, separated by semicolons) – the way back without the app. The catalog format has the columns of the original catalog and can be imported again; the complete export also contains all new fields.') }}
			</p>
			<div class="archive-management__actions">
				<NcButton :href="exportUrl(false)" download>
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('notenarchiv', 'Download (catalog format)') }}
				</NcButton>
				<NcButton :href="exportUrl(true)" download>
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" />
					</template>
					{{ t('notenarchiv', 'Download (complete)') }}
				</NcButton>
				<NcButton ref="storeButton" :disabled="storeBusy || !hasArchiveFolder" @click="doStoreExport">
					<template #icon>
						<NcLoadingIcon v-if="storeBusy" />
						<NcIconSvgWrapper v-else :path="mdiFolderDownloadOutline" />
					</template>
					{{ t('notenarchiv', 'Store export in the archive folder') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="storedPath" type="success">
				{{ t('notenarchiv', 'Stored as {path} in the archive folder.', { path: storedPath }) }}
			</NcNoteCard>
		</section>

		<!-- Backup -->
		<section class="archive-management__section" aria-labelledby="archive-backup">
			<h3 id="archive-backup">
				{{ t('notenarchiv', 'Backup') }}
			</h3>
			<p>
				{{ t('notenarchiv', 'A complete backup (CSV, spreadsheet with the audit log and JSON for a restore) is stored every week in the backup folder, outside the archive folder; the newest eight weekly backups are kept. Store one now, e.g. before larger changes.') }}
			</p>
			<div class="archive-management__actions">
				<NcButton ref="backupButton" :disabled="backupBusy || !hasArchiveFolder" @click="doBackup">
					<template #icon>
						<NcLoadingIcon v-if="backupBusy" />
						<NcIconSvgWrapper v-else :path="mdiShieldCheckOutline" />
					</template>
					{{ t('notenarchiv', 'Store a complete backup now') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="backupPaths.length > 0" type="success">
				<p>{{ t('notenarchiv', 'Stored in the files of the account {account}:', { account: backupAccount }) }}</p>
				<ul class="archive-management__paths">
					<li v-for="path in backupPaths" :key="path">
						{{ path }}
					</li>
				</ul>
			</NcNoteCard>
		</section>

		<!-- Complete backup and restore -->
		<section class="archive-management__section" aria-labelledby="archive-restore">
			<h3 id="archive-restore">
				{{ t('notenarchiv', 'Complete backup and restore') }}
			</h3>
			<BackupRestore :catalog-filled="catalogFilled" @busy="restoreBusy = $event" @restored="onRestored" />
		</section>

		<template #actions>
			<NcButton :disabled="importBusy || syncBusy || performanceBusy || personBusy || restoreBusy" @click="onUpdateOpen(false)">
				{{ t('notenarchiv', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.archive-management__section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.archive-management__section h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.archive-management__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.archive-management__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.archive-management__paths {
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
	overflow-wrap: anywhere;
}

.archive-management__muted {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>
