<script setup lang="ts">
/**
 * Complete backup as a download (JSON with all tables of the app) and the
 * restore of such a backup – only into an empty catalog, e.g. after a
 * reinstallation. Used in the archive management and in the settings.
 */
import { mdiAlertOutline, mdiBackupRestore, mdiDownload } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, fullBackupUrl, restoreBackup } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { entries, entriesStatus, loadEntries, refreshPortalRows, reloadMeta } from '../store.ts'

const emit = defineEmits<{
	busy: [busy: boolean]
}>()

/** The catalog has copies: a restore would be refused. */
const catalogFilled = computed(() => entriesStatus.value === 'ready' && entries.value.length > 0)

const file = ref<{ id: number, name: string } | null>(null)
const understood = ref(false)
const busy = ref(false)
const error = ref('')
const restored = ref<Record<string, number> | null>(null)

/** Where the focus goes after the file picker and after the restore (WCAG 2.4.3) */
const chooseButton = useTemplateRef('chooseButton')
const restoredNote = useTemplateRef('restoredNote')

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('notenarchiv', 'Choose the backup file (JSON)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['application/json'])
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
	understood.value = false
	error.value = ''
}

async function restore(): Promise<void> {
	if (!file.value || !understood.value) {
		return
	}
	busy.value = true
	emit('busy', true)
	error.value = ''
	try {
		restored.value = await restoreBackup(file.value.id)
		file.value = null
		showSuccess(t('notenarchiv', 'The backup has been restored.'))
		await reloadMeta()
		await loadEntries()
		await refreshPortalRows()
		// The button to restore is disabled now (the catalog is filled)
		await focusAfterDialog(restoredNote)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
		emit('busy', false)
	}
}

/**
 * Readable name of a table of the backup.
 *
 * @param table - table name
 */
function tableLabel(table: string): string {
	switch (table.replace(/^notarch_/, '')) {
	case 'works':
		return t('notenarchiv', 'Works')
	case 'versions':
		return t('notenarchiv', 'Versions')
	case 'copies':
		return t('notenarchiv', 'Copies')
	case 'files':
		return t('notenarchiv', 'Files')
	case 'licenses':
		return t('notenarchiv', 'Licenses')
	case 'proposals':
		return t('notenarchiv', 'Proposals')
	case 'tasks':
		return t('notenarchiv', 'Tasks')
	case 'links':
		return t('notenarchiv', 'Internal links')
	case 'events':
		return t('notenarchiv', 'Concerts and song lists')
	case 'event_items':
		return t('notenarchiv', 'Programme lines')
	case 'persons':
		return t('notenarchiv', 'Persons')
	case 'contributions':
		return t('notenarchiv', 'Contributions')
	case 'merges':
		return t('notenarchiv', 'Merges')
	case 'audit':
		return t('notenarchiv', 'Audit log')
	default:
		return table
	}
}
</script>

<template>
	<div class="backup-restore">
		<p>
			{{ t('notenarchiv', 'The complete backup contains all data of the music archive (catalog, rights, licenses, tasks, programmes, persons and the audit log) in one JSON file. The files of the archive folder are not part of it.') }}
		</p>
		<div class="backup-restore__actions">
			<NcButton :href="fullBackupUrl()" download>
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('notenarchiv', 'Download complete backup (JSON)') }}
			</NcButton>
		</div>

		<h4>{{ t('notenarchiv', 'Restore') }}</h4>
		<NcNoteCard type="warning">
			{{ t('notenarchiv', 'A backup can only be restored into an empty catalog, e.g. after reinstalling the app. Nothing existing is overwritten; with data in the catalog the restore is refused.') }}
		</NcNoteCard>
		<p v-if="catalogFilled" class="backup-restore__muted">
			{{ t('notenarchiv', 'The catalog is not empty, so a restore is not possible.') }}
		</p>
		<div class="backup-restore__actions">
			<NcButton ref="chooseButton" :disabled="busy || catalogFilled" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBackupRestore" />
				</template>
				{{ t('notenarchiv', 'Restore from a backup …') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="restored" ref="restoredNote" type="success">
			<p>{{ t('notenarchiv', 'Restored:') }}</p>
			<dl class="backup-restore__figures">
				<template v-for="(count, table) in restored" :key="table">
					<dt>{{ tableLabel(String(table)) }}</dt>
					<dd>{{ count }}</dd>
				</template>
			</dl>
		</NcNoteCard>

		<ActionDialog v-if="file"
			:name="t('notenarchiv', 'Restore backup')"
			:submit-label="t('notenarchiv', 'Restore now')"
			:busy="busy"
			:error="error"
			:submit-disabled="!understood"
			variant="error"
			@submit="restore"
			@close="file = null">
			<p class="backup-restore__warning">
				<NcIconSvgWrapper :path="mdiAlertOutline" inline />
				{{ t('notenarchiv', 'All data of the backup are written into the empty catalog: works, versions, copies, rights, licenses, tasks, programmes, persons and the audit log.') }}
			</p>
			<p>{{ t('notenarchiv', 'File: {name}', { name: file.name }) }}</p>
			<NcCheckboxRadioSwitch v-model="understood">
				{{ t('notenarchiv', 'I have checked that this is the right and most recent backup.') }}
			</NcCheckboxRadioSwitch>
		</ActionDialog>
	</div>
</template>

<style scoped>
.backup-restore {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.backup-restore h4 {
	margin: var(--default-grid-baseline) 0 0;
	font-weight: bold;
}

.backup-restore__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.backup-restore__figures {
	display: grid;
	grid-template-columns: max-content auto;
	gap: 2px calc(4 * var(--default-grid-baseline));
	margin: 0;
}

.backup-restore__figures dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.backup-restore__warning {
	font-weight: bold;
}

.backup-restore__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
