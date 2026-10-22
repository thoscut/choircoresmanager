<script setup lang="ts">
/**
 * Settings for administrators: archive folder, which Nextcloud groups hold
 * which role, and the thresholds of the repertoire classes.
 */
import type { Settings } from '../types.ts'

import { mdiFolderOutline } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BackupRestore from './BackupRestore.vue'
import { errorMessage, fetchRepertoireThresholds, fetchSettings, saveRepertoireThresholds, saveSettings } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { translateLabel } from '../format.ts'
import { loadEntries, meta, reloadMeta, settingsDialogOpen } from '../store.ts'

const open = computed({
	get: () => settingsDialogOpen.value,
	set: (value: boolean) => {
		settingsDialogOpen.value = value
	},
})

const settings = ref<Settings | null>(null)
const loading = ref(false)
const loadError = ref('')

const draftRoles = reactive<Record<string, string[]>>({})
const rolesSaving = ref(false)

const pendingFolder = ref<{ id: number, path: string } | null>(null)
const folderSaving = ref(false)

/** Role keys with their translated labels, in the order of the server. */
const roles = computed(() => Object.entries(meta.value?.roleLabels ?? {})
	.map(([key, label]) => ({ key, label: translateLabel(label), description: roleDescription(key) })))

/**
 * Short explanation of what a role may do.
 *
 * @param role - role key
 */
function roleDescription(role: string): string {
	switch (role) {
	case 'librarian':
		return t('notenarchiv', 'Maintains works, versions and rights; imports the catalog and runs the file sync.')
	case 'operations':
		return t('notenarchiv', 'Maintains the status and counts of copies.')
	case 'conductor':
		return t('notenarchiv', 'Decides which version is valid for the choir.')
	case 'contributor':
		return t('notenarchiv', 'Tools and transcription rounds: proposals only.')
	case 'reader':
		return t('notenarchiv', 'Reads everything, including the audit log.')
	default:
		return ''
	}
}

/**
 * Take the server's settings as the current state of the form.
 *
 * @param value - settings from the server
 */
function apply(value: Settings): void {
	settings.value = value
	for (const key of Object.keys(draftRoles)) {
		delete draftRoles[key]
	}
	for (const { key } of roles.value) {
		const groups = value.roles && !Array.isArray(value.roles) ? value.roles[key] : undefined
		draftRoles[key] = Array.isArray(groups) ? [...groups] : []
	}
}

// ---- repertoire thresholds ----

const thresholds = reactive({ current: '', dormant: '' })
const thresholdsSaving = ref(false)
const thresholdsError = ref('')

async function loadThresholds(): Promise<void> {
	try {
		const value = await fetchRepertoireThresholds()
		thresholds.current = String(value.current)
		thresholds.dormant = String(value.dormant)
	} catch (error) {
		thresholdsError.value = errorMessage(error)
	}
}

async function saveThresholds(): Promise<void> {
	const current = Number(thresholds.current)
	const dormant = Number(thresholds.dormant)
	if (!Number.isInteger(current) || !Number.isInteger(dormant) || current < 1 || dormant < current) {
		thresholdsError.value = t('notenarchiv', 'Please enter whole numbers of years; "dormant" must be at least as long as "current".')
		return
	}
	thresholdsSaving.value = true
	thresholdsError.value = ''
	try {
		const saved = await saveRepertoireThresholds({ current, dormant })
		thresholds.current = String(saved.current)
		thresholds.dormant = String(saved.dormant)
		await loadEntries()
		showSuccess(t('notenarchiv', 'The repertoire thresholds have been saved.'))
	} catch (error) {
		thresholdsError.value = errorMessage(error)
	} finally {
		thresholdsSaving.value = false
	}
}

async function load(): Promise<void> {
	loading.value = true
	loadError.value = ''
	pendingFolder.value = null
	try {
		apply(await fetchSettings())
	} catch (error) {
		loadError.value = errorMessage(error)
	} finally {
		loading.value = false
	}
	await loadThresholds()
}

watch(settingsDialogOpen, (isOpen) => {
	if (isOpen) {
		load()
	}
}, { immediate: true })

const archive = computed(() => settings.value?.archive ?? meta.value?.archive ?? { id: 0, path: '', user: '' })

/** Where the focus goes after the file picker and after saving (WCAG 2.4.3) */
const folderButton = useTemplateRef('folderButton')
const saveFolderButton = useTemplateRef('saveFolderButton')

/** Forget the chosen folder; the button to choose one gets the focus. */
function cancelFolder(): void {
	pendingFolder.value = null
	focusAfterUpdate(folderButton)
}

async function chooseFolder(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('notenarchiv', 'Choose the archive folder'))
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
		await focusAfterDialog(folderButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid || node.mime !== 'httpd/unix-directory') {
		showError(t('notenarchiv', 'Please choose a folder.'))
		await focusAfterDialog(folderButton)
		return
	}
	pendingFolder.value = { id: node.fileid, path: node.path }
	// Next step: save the chosen folder
	await focusAfterDialog(saveFolderButton)
}

async function saveFolder(): Promise<void> {
	if (!pendingFolder.value) {
		return
	}
	folderSaving.value = true
	try {
		apply(await saveSettings({ archiveFolderId: pendingFolder.value.id }))
		pendingFolder.value = null
		await reloadMeta()
		showSuccess(t('notenarchiv', 'The archive folder has been saved.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		folderSaving.value = false
	}
	// The buttons to save or cancel are gone (or were disabled)
	await focusAfterUpdate(() => (pendingFolder.value ? saveFolderButton.value : folderButton.value))
}

async function saveRoles(): Promise<void> {
	rolesSaving.value = true
	try {
		apply(await saveSettings({ roles: { ...draftRoles } }))
		await reloadMeta()
		showSuccess(t('notenarchiv', 'The roles have been saved.'))
	} catch (error) {
		showError(errorMessage(error))
	} finally {
		rolesSaving.value = false
	}
}
</script>

<template>
	<NcAppSettingsDialog v-model:open="open"
		:name="t('notenarchiv', 'Settings')"
		show-navigation>
		<NcAppSettingsSection id="archive-folder" :name="t('notenarchiv', 'Archive folder')">
			<div class="settings-section">
				<p>
					{{ t('notenarchiv', 'The folder in Nextcloud that holds the score files (for example one subfolder per binder). The app only reads files. Exports and snapshots are stored in its subfolder "_notenarchiv-app".') }}
				</p>
				<p v-if="archive.id > 0">
					<strong>{{ t('notenarchiv', 'Current archive folder:') }}</strong>
					{{ archive.path }}
					<span v-if="archive.user" class="settings-section__muted">
						{{ t('notenarchiv', '(chosen by {user})', { user: archive.user }) }}
					</span>
				</p>
				<NcNoteCard v-else type="warning">
					{{ t('notenarchiv', 'No archive folder has been chosen yet.') }}
				</NcNoteCard>
				<p class="settings-section__muted">
					{{ t('notenarchiv', 'Everyone with a role needs access to this folder in their own Nextcloud, e.g. through a share or a group folder.') }}
				</p>

				<div class="settings-section__actions">
					<NcButton ref="folderButton" :disabled="folderSaving" @click="chooseFolder">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFolderOutline" />
						</template>
						{{ t('notenarchiv', 'Choose folder …') }}
					</NcButton>
				</div>
				<template v-if="pendingFolder">
					<p>
						<strong>{{ t('notenarchiv', 'New archive folder:') }}</strong>
						{{ pendingFolder.path }}
						<span class="settings-section__muted">{{ t('notenarchiv', '(not saved yet)') }}</span>
					</p>
					<div class="settings-section__actions">
						<NcButton :disabled="folderSaving" @click="cancelFolder">
							{{ t('notenarchiv', 'Cancel') }}
						</NcButton>
						<NcButton ref="saveFolderButton"
							variant="primary"
							:disabled="folderSaving"
							@click="saveFolder">
							<template v-if="folderSaving" #icon>
								<NcLoadingIcon />
							</template>
							{{ t('notenarchiv', 'Save archive folder') }}
						</NcButton>
					</div>
				</template>
			</div>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="roles" :name="t('notenarchiv', 'Roles and groups')">
			<div class="settings-section">
				<NcNoteCard type="info">
					{{ t('notenarchiv', 'Roles are linked to groups. Whoever has no role cannot see the app.') }}
				</NcNoteCard>

				<p v-if="loading" class="settings-section__busy">
					<NcLoadingIcon :size="20" />
					{{ t('notenarchiv', 'Loading …') }}
				</p>
				<NcNoteCard v-else-if="loadError" type="error">
					<p>{{ loadError }}</p>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<template v-else>
					<div v-for="role in roles" :key="role.key" class="settings-role">
						<!-- NcSettingsSelectGroup brings its own (hidden) label -->
						<span class="settings-role__label" aria-hidden="true">{{ role.label }}</span>
						<p v-if="role.description" class="settings-section__muted">
							{{ role.description }}
						</p>
						<NcSettingsSelectGroup :id="`settings-role-${role.key}`"
							v-model="draftRoles[role.key]"
							:label="role.label"
							:placeholder="t('notenarchiv', 'Choose groups')" />
					</div>
					<div class="settings-section__actions">
						<NcButton variant="primary" :disabled="rolesSaving" @click="saveRoles">
							<template v-if="rolesSaving" #icon>
								<NcLoadingIcon />
							</template>
							{{ t('notenarchiv', 'Save roles') }}
						</NcButton>
					</div>
				</template>
			</div>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="repertoire" :name="t('notenarchiv', 'Repertoire')">
			<p class="settings-repertoire__text">
				{{ t('notenarchiv', 'A work belongs to the current repertoire if it was sung within the last years given here, and is dormant up to the second limit. Earlier performances make it "earlier". Documented performances come from concerts confirmed as sung and from the imported history.') }}
			</p>
			<form class="settings-repertoire" @submit.prevent="saveThresholds">
				<NcTextField v-model="thresholds.current"
					type="number"
					min="1"
					max="50"
					:label="t('notenarchiv', 'Current: sung within (years)')" />
				<NcTextField v-model="thresholds.dormant"
					type="number"
					min="1"
					max="100"
					:label="t('notenarchiv', 'Dormant: sung within (years)')" />
				<NcButton type="submit" :disabled="thresholdsSaving">
					<template v-if="thresholdsSaving" #icon>
						<NcLoadingIcon />
					</template>
					{{ t('notenarchiv', 'Save thresholds') }}
				</NcButton>
			</form>
			<NcNoteCard v-if="thresholdsError" type="error" role="alert">
				{{ thresholdsError }}
			</NcNoteCard>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="backup" :name="t('notenarchiv', 'Complete backup and restore')">
			<BackupRestore />
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<style scoped>
.settings-section {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.settings-section p {
	overflow-wrap: anywhere;
}

.settings-section__muted {
	color: var(--color-text-maxcontrast);
}

.settings-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.settings-section__busy {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.settings-role {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.settings-role__label {
	font-weight: bold;
}

.settings-role p {
	margin: 0;
}

.settings-repertoire__text {
	max-width: 70ch;
}

.settings-repertoire {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
	margin-top: calc(2 * var(--default-grid-baseline));
}

.settings-repertoire > * {
	flex: 0 1 16em;
}
</style>
