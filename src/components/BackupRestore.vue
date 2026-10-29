<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Complete backup as a download (JSON with all tables of the app) and the
 * restore of such a backup – only into an empty catalog, e.g. after a
 * reinstallation. Used in the archive management and on the settings page
 * (Administration → Cantoria, admins only). Needs no catalog: the page
 * tells whether the catalog is filled and reloads what it shows after a
 * restore (`restored`).
 */
import { mdiAlertOutline, mdiBackupRestore, mdiDownload } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, fullBackupUrl, restoreBackup } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { showError } from '../toasts.ts'

defineProps<{
	/** The catalog holds data: a restore would be refused. */
	catalogFilled: boolean
}>()

const emit = defineEmits<{
	busy: [busy: boolean]
	/** a backup was restored; the page reloads what it shows */
	restored: []
}>()

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
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose the backup file (JSON)'))
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
		showError(t('cantoria', 'The chosen file cannot be used.'))
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
		showSuccess(t('cantoria', 'The backup has been restored.'))
		emit('restored')
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
	switch (table.replace(/^cantoria_/, '')) {
	case 'works':
		return t('cantoria', 'Works')
	case 'versions':
		return t('cantoria', 'Versions')
	case 'copies':
		return t('cantoria', 'Copies')
	case 'files':
		return t('cantoria', 'Files')
	case 'licenses':
		return t('cantoria', 'Licenses')
	case 'proposals':
		return t('cantoria', 'Proposals')
	case 'tasks':
		return t('cantoria', 'Tasks')
	case 'links':
		return t('cantoria', 'Internal links')
	case 'events':
		return t('cantoria', 'Concerts and song lists')
	case 'event_items':
		return t('cantoria', 'Programme lines')
	case 'persons':
		return t('cantoria', 'Persons')
	case 'contributions':
		return t('cantoria', 'Contributions')
	case 'merges':
		return t('cantoria', 'Merges')
	case 'audit':
		return t('cantoria', 'Audit log')
	default:
		return table
	}
}
</script>

<template>
	<div class="backup-restore">
		<p>
			{{ t('cantoria', 'The complete backup contains all data of the music archive (catalog, rights, licenses, tasks, programmes, persons and the audit log) in one JSON file. The files of the archive folder are not part of it.') }}
		</p>
		<div class="backup-restore__actions">
			<NcButton :href="fullBackupUrl()" download>
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('cantoria', 'Download complete backup (JSON)') }}
			</NcButton>
		</div>

		<h4>{{ t('cantoria', 'Restore') }}</h4>
		<NcNoteCard type="warning">
			{{ t('cantoria', 'A backup can only be restored into an empty catalog, e.g. after reinstalling the app. Nothing existing is overwritten; with data in the catalog the restore is refused.') }}
		</NcNoteCard>
		<p v-if="catalogFilled" class="backup-restore__muted">
			{{ t('cantoria', 'The catalog is not empty, so a restore is not possible.') }}
		</p>
		<div class="backup-restore__actions">
			<NcButton ref="chooseButton" :disabled="busy || catalogFilled" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBackupRestore" />
				</template>
				{{ t('cantoria', 'Restore from a backup …') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="restored" ref="restoredNote" type="success">
			<p>{{ t('cantoria', 'Restored:') }}</p>
			<dl class="backup-restore__figures">
				<template v-for="(count, table) in restored" :key="table">
					<dt>{{ tableLabel(String(table)) }}</dt>
					<dd>{{ count }}</dd>
				</template>
			</dl>
		</NcNoteCard>

		<ActionDialog v-if="file"
			:name="t('cantoria', 'Restore backup')"
			:submit-label="t('cantoria', 'Restore now')"
			:busy="busy"
			:error="error"
			:submit-disabled="!understood"
			variant="error"
			@submit="restore"
			@close="file = null">
			<p class="backup-restore__warning">
				<NcIconSvgWrapper :path="mdiAlertOutline" inline />
				{{ t('cantoria', 'All data of the backup are written into the empty catalog: works, versions, copies, rights, licenses, tasks, programmes, persons and the audit log.') }}
			</p>
			<p>{{ t('cantoria', 'File: {name}', { name: file.name }) }}</p>
			<NcCheckboxRadioSwitch v-model="understood">
				{{ t('cantoria', 'I have checked that this is the right and most recent backup.') }}
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
