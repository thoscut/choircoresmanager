<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings of the mirror folder for the print station (module
 * "print_mirror") or of the hand-over folder for a choir system (module
 * "choir_handover"), for administrators: choose the target folder, which the server checks
 * right away (not the archive folder, not inside it, write permission), save
 * it, then look at the preview and start the first sync.
 */
import type { MirrorFolder, MirrorKind } from '../printMirror.ts'

import { mdiFolderOutline } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PrintMirrorSync from './PrintMirrorSync.vue'
import { errorMessage } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { checkMirrorFolder, fetchMirrorStatus, saveMirrorFolder } from '../printMirror.ts'
import { showError } from '../toasts.ts'

const props = withDefaults(defineProps<{
	/** which folder */
	kind?: MirrorKind
}>(), {
	kind: 'print',
})

const handover = computed(() => props.kind === 'handover')

const current = ref<MirrorFolder | null>(null)
/** A chosen folder that passed the check, not saved yet */
const pending = ref<MirrorFolder | null>(null)
/** Why the chosen folder does not fit */
const problem = ref('')
const busy = ref(false)
const loadError = ref('')

const chooseButton = useTemplateRef('chooseButton')
const saveButton = useTemplateRef('saveButton')

onMounted(async () => {
	try {
		current.value = (await fetchMirrorStatus(true, props.kind)).folder
	} catch (caught) {
		loadError.value = errorMessage(caught)
	}
})

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(handover.value
			? t('cantoria', 'Choose the hand-over folder for the choir system')
			: t('cantoria', 'Choose the target folder of the print station'))
			.setMultiSelect(false)
			.allowDirectories(true)
			.setMimeTypeFilter(['httpd/unix-directory'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			showError(errorMessage(error))
		}
		await focusAfterDialog(chooseButton)
		return
	}
	const node = nodes[0]
	pending.value = null
	problem.value = ''
	if (!node?.fileid || node.mime !== 'httpd/unix-directory') {
		problem.value = t('cantoria', 'Please choose a folder.')
		await focusAfterDialog(chooseButton)
		return
	}
	busy.value = true
	try {
		// Checked right away: not the archive folder, not inside it, write permission
		pending.value = await checkMirrorFolder(node.fileid, props.kind)
	} catch (caught) {
		problem.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
	await focusAfterDialog(pending.value ? saveButton : chooseButton)
}

async function save(): Promise<void> {
	if (!pending.value) {
		return
	}
	busy.value = true
	try {
		current.value = await saveMirrorFolder(pending.value.id, props.kind)
		pending.value = null
		showSuccess(t('cantoria', 'The target folder has been saved. Look at the preview before the first sync.'))
	} catch (caught) {
		problem.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
	await focusAfterUpdate(chooseButton)
}

async function remove(): Promise<void> {
	busy.value = true
	try {
		current.value = await saveMirrorFolder(0, props.kind)
		showSuccess(handover.value
			? t('cantoria', 'The hand-over has no target folder any more. The files in the old folder stay there.')
			: t('cantoria', 'The print station has no target folder any more. The files in the old folder stay there.'))
	} catch (caught) {
		problem.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="mirror-settings">
		<template v-if="handover">
			<p>
				{{ t('cantoria', 'The app copies what the singers\' portal releases into this folder and keeps it up to date: the PDF scans, the MusicXML files and the practice recordings the rights allow, with a list of the pieces (Liste.csv) and the conductor\'s notes per piece. A choir system such as a member app can read the folder; the app never writes into the choir system itself. The folder must not be the archive folder, lie inside it or be the folder of the print station.') }}
			</p>
			<NcNoteCard type="warning">
				{{ t('cantoria', 'Everyone who can open the hand-over folder can download the files in it. Share it only with the account of the choir system.') }}
			</NcNoteCard>
		</template>
		<template v-else>
			<p>
				{{ t('cantoria', 'The app copies the PDF scans of the versions released for the print station into this folder and keeps it up to date. The folder must not be the archive folder or lie inside it.') }}
			</p>
			<NcNoteCard type="warning">
				{{ t('cantoria', 'Everyone who can open the target folder can print the scans in it. Share it only with the print station or the people who may print.') }}
			</NcNoteCard>
		</template>
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<p v-if="current">
			<strong>{{ t('cantoria', 'Current target folder:') }}</strong>
			{{ current.path }}
			<span class="mirror-settings__muted">{{ t('cantoria', '(chosen by {user})', { user: current.user }) }}</span>
		</p>
		<div class="mirror-settings__actions">
			<NcButton ref="chooseButton" :disabled="busy" @click="choose">
				<template #icon>
					<NcLoadingIcon v-if="busy" />
					<NcIconSvgWrapper v-else :path="mdiFolderOutline" />
				</template>
				{{ t('cantoria', 'Choose folder …') }}
			</NcButton>
			<NcButton v-if="current && !pending"
				variant="tertiary"
				:disabled="busy"
				@click="remove">
				{{ t('cantoria', 'Remove target folder') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="problem" type="error" role="alert">
			{{ problem }}
		</NcNoteCard>
		<template v-if="pending">
			<NcNoteCard type="success" role="status">
				{{ t('cantoria', 'The folder {path} fits: outside the archive folder, with write permission.', { path: pending.path }) }}
			</NcNoteCard>
			<div class="mirror-settings__actions">
				<NcButton :disabled="busy" @click="pending = null">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
				<NcButton ref="saveButton"
					variant="primary"
					:disabled="busy"
					@click="save">
					{{ t('cantoria', 'Save target folder') }}
				</NcButton>
			</div>
		</template>
		<PrintMirrorSync v-if="current"
			admin
			:folder-id="current.id"
			:kind="kind" />
	</div>
</template>

<style scoped>
.mirror-settings {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.mirror-settings p {
	max-width: 70ch;
	margin: 0;
	overflow-wrap: anywhere;
}

.mirror-settings__muted {
	color: var(--color-text-maxcontrast);
}

.mirror-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
