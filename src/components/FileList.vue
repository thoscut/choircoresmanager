<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Files linked to a copy. The app only reads files; a click opens the file
 * in Nextcloud in a new tab, with the permissions of the account: when the
 * archive folder is not shared with it, a note says so and what to do. The librarian can link a file of the archive
 * folder by hand (the file sync then never moves it) and remove such a link.
 */
import type { FileRef } from '../types.ts'

import { mdiFileAlertOutline, mdiFileDocumentOutline, mdiFileLinkOutline, mdiFileQuestionOutline, mdiLinkOff, mdiOpenInNew } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ActionDialog from './ActionDialog.vue'
import { adminSettingsUrl, errorMessage, linkFile, unlinkFile } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { formatSize } from '../format.ts'
import { navigate } from '../navigation.ts'
import { SETTINGS_SECTIONS } from '../settings.ts'
import { archiveNotShared, canChangeSettings, hasArchiveFolder, isLibrarian, isModuleActive } from '../store.ts'

const props = defineProps<{
	files: FileRef[]
	preservationCopy: boolean
	copyId: number
}>()

const emit = defineEmits<{
	changed: []
}>()

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

const sortedFiles = computed(() => [...props.files].sort((a, b) => {
	const foundA = a.found === false ? 1 : 0
	const foundB = b.found === false ? 1 : 0
	return foundA - foundB || collator.compare(a.name, b.name)
}))

/**
 * Translated name of a file role.
 *
 * @param role - role key from the server
 */
function roleLabel(role: string | null): string {
	switch (role) {
	case 'score_pdf':
		return t('notenarchiv', 'Score (PDF)')
	case 'musicxml':
		return t('notenarchiv', 'MusicXML')
	case 'musescore':
		return t('notenarchiv', 'MuseScore')
	case 'analysis':
		return t('notenarchiv', 'Analysis')
	case 'source':
		return t('notenarchiv', 'Source file')
	case 'audio':
		return t('notenarchiv', 'Audio')
	case 'image':
		return t('notenarchiv', 'Image')
	case 'other':
	case null:
		return t('notenarchiv', 'Other')
	default:
		return role
	}
}

/**
 * Link that opens the file in Nextcloud.
 *
 * @param file - linked file
 */
function fileUrl(file: FileRef): string {
	return generateUrl('/f/{fileId}', { fileId: file.file_id })
}

// ---- manual links ----

const linking = ref(false)

/** The button to link a file gets the focus back after picking and unlinking (WCAG 2.4.3) */
const linkButton = useTemplateRef('linkButton')

async function chooseFile(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('notenarchiv', 'Choose a file in the archive folder'))
			.setMultiSelect(false)
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (caught) {
		if (!(caught instanceof FilePickerClosed)) {
			showError(errorMessage(caught))
		}
		await focusAfterDialog(linkButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('notenarchiv', 'The chosen file cannot be used.'))
		await focusAfterDialog(linkButton)
		return
	}
	linking.value = true
	try {
		await linkFile(props.copyId, node.fileid)
		showSuccess(t('notenarchiv', 'The file has been linked.'))
		emit('changed')
	} catch (caught) {
		showError(errorMessage(caught))
	} finally {
		linking.value = false
	}
	// The button was disabled while linking
	await focusAfterUpdate(linkButton)
}

const unlinking = ref<FileRef | null>(null)
const unlinkBusy = ref(false)
const unlinkError = ref('')

/**
 * @param file - file whose manual link is removed
 */
function startUnlink(file: FileRef): void {
	unlinking.value = file
	unlinkError.value = ''
}

async function confirmUnlink(): Promise<void> {
	if (!unlinking.value) {
		return
	}
	unlinkBusy.value = true
	unlinkError.value = ''
	try {
		await unlinkFile(unlinking.value.id)
		showSuccess(t('notenarchiv', 'The manual link has been removed.'))
		unlinking.value = null
		emit('changed')
		// The button that removed the link is gone with the link
		focusAfterDialog(linkButton)
	} catch (caught) {
		unlinkError.value = errorMessage(caught)
	} finally {
		unlinkBusy.value = false
	}
}

/**
 * Type, role and size in one line.
 *
 * @param file - linked file
 */
function details(file: FileRef): string {
	return [file.type?.toUpperCase() ?? '', roleLabel(file.role), formatSize(file.size)]
		.filter((part) => part !== '')
		.join(' · ')
}
</script>

<template>
	<div class="file-list">
		<NcNoteCard v-if="preservationCopy" type="error">
			{{ t('notenarchiv', 'Internal preservation copy – do not share. Do not pass these files on.') }}
		</NcNoteCard>

		<NcNoteCard v-if="archiveNotShared && files.length > 0" type="warning">
			<p>{{ t('notenarchiv', 'The files lie in the archive folder, which is not shared with your account, so they do not open for you. Please ask the music librarians to share the archive folder with you or your group.') }}</p>
			<NcButton v-if="canChangeSettings" variant="secondary" :href="adminSettingsUrl(SETTINGS_SECTIONS.access)">
				{{ t('notenarchiv', 'Check access to the archive folder') }}
			</NcButton>
		</NcNoteCard>

		<NcNoteCard v-if="files.some((file) => file.sha256_mismatch)" type="warning">
			<p>{{ t('notenarchiv', 'A file has changed without a visible modification and may be damaged.') }}</p>
			<NcButton v-if="isModuleActive('checksums')" variant="secondary" @click="navigate('checksums')">
				{{ t('notenarchiv', 'Show checksums') }}
			</NcButton>
		</NcNoteCard>

		<div v-if="isLibrarian && hasArchiveFolder" class="file-list__toolbar">
			<NcButton ref="linkButton" :disabled="linking" @click="chooseFile">
				<template #icon>
					<NcLoadingIcon v-if="linking" />
					<NcIconSvgWrapper v-else :path="mdiFileLinkOutline" />
				</template>
				{{ t('notenarchiv', 'Link a file …') }}
			</NcButton>
		</div>

		<NcEmptyContent v-if="files.length === 0"
			:name="t('notenarchiv', 'No files linked')"
			:description="t('notenarchiv', 'Files are linked by the file sync in the archive management. The file name has to start with the shelf mark, e.g. 5-01_Title.pdf. Other files of the archive folder can be linked by hand.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileQuestionOutline" />
			</template>
		</NcEmptyContent>

		<ul v-else class="file-list__items">
			<li v-for="file in sortedFiles"
				:key="file.id"
				class="file-list__item"
				:class="{ 'file-list__item--missing': file.found === false }">
				<NcIconSvgWrapper class="file-list__icon" :path="file.found === false ? mdiFileQuestionOutline : (file.sha256_mismatch ? mdiFileAlertOutline : mdiFileDocumentOutline)" />
				<div class="file-list__body">
					<a v-if="file.found !== false"
						class="file-list__name"
						:href="fileUrl(file)"
						target="_blank"
						rel="noopener noreferrer">
						{{ file.name }}
						<NcIconSvgWrapper class="file-list__external"
							:path="mdiOpenInNew"
							:size="16"
							inline
							:name="t('notenarchiv', 'opens in a new tab')" />
					</a>
					<span v-else class="file-list__name">{{ file.name }}</span>
					<span class="file-list__details">{{ details(file) }}</span>
					<span class="file-list__flags">
						<span v-if="file.musicxml_valid" class="file-flag file-flag--success">
							{{ t('notenarchiv', 'Valid MusicXML') }}
						</span>
						<span v-if="file.listed" class="file-flag">
							{{ t('notenarchiv', 'Listed in the catalog') }}
						</span>
						<span v-if="file.found === false" class="file-flag file-flag--error">
							{{ t('notenarchiv', 'Not found') }}
						</span>
						<span v-if="file.linked_manually" class="file-flag">
							{{ t('notenarchiv', 'Linked by hand') }}
						</span>
						<span v-if="file.sha256_mismatch" class="file-flag file-flag--error">
							{{ t('notenarchiv', 'Changed without modification') }}
						</span>
					</span>
					<span class="file-list__path">{{ file.path }}</span>
					<!-- Rights notice of a MusicXML file (milestone 0.8): information, never a rights status -->
					<span v-if="file.rights_notice && isModuleActive('rights')" class="file-list__notice">
						<span class="file-flag">{{ t('notenarchiv', 'Not checked') }}</span>
						{{ t('notenarchiv', 'Rights notice in the file: “{notice}”', { notice: file.rights_notice }) }}
						<span class="file-list__path">
							{{ t('notenarchiv', 'Taken from the MusicXML file as it is. It changes no rights status; the catalog librarian checks it and confirms the rights in the tab Rights.') }}
						</span>
					</span>
					<NcButton v-if="isLibrarian && file.linked_manually"
						class="file-list__unlink"
						variant="tertiary"
						@click="startUnlink(file)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiLinkOff" />
						</template>
						{{ t('notenarchiv', 'Remove manual link') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<ActionDialog v-if="unlinking"
			:name="t('notenarchiv', 'Remove manual link')"
			:submit-label="t('notenarchiv', 'Remove link')"
			:busy="unlinkBusy"
			:error="unlinkError"
			@submit="confirmUnlink"
			@close="unlinking = null">
			<p>{{ t('notenarchiv', 'File: {name}', { name: unlinking.name }) }}</p>
			<p class="action-dialog__hint">
				{{ t('notenarchiv', 'Afterwards the file name decides again: the next file sync links the file to the copy whose shelf mark it starts with, or to none. The file itself is not changed.') }}
			</p>
		</ActionDialog>
	</div>
</template>

<style scoped>
.file-list {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
}

.file-list__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.file-list__unlink {
	align-self: flex-start;
}

.file-list__items {
	display: flex;
	flex-direction: column;
}

.file-list__item {
	display: flex;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.file-list__item:last-child {
	border-bottom: none;
}

.file-list__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.file-list__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.file-list__name {
	font-weight: bold;
	overflow-wrap: anywhere;
}

a.file-list__name:hover,
a.file-list__name:focus-visible {
	text-decoration: underline;
}

.file-list__external {
	color: var(--color-text-maxcontrast);
}

.file-list__details,
.file-list__path {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}

.file-list__notice {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	overflow-wrap: anywhere;
}

.file-list__flags {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.file-flag {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	font-size: var(--font-size-small, 13px);
}

.file-flag--success {
	border-color: var(--color-success-text, var(--color-success));
}

.file-flag--error {
	border-color: var(--color-error-text, var(--color-error));
	color: var(--color-error-text, var(--color-error));
}

.file-list__item--missing .file-list__name {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>
