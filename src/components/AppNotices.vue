<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Notices about the whole app, under the heading of the current view: a
 * link from the URL that could not be opened, a missing or not shared
 * archive folder, an administrator without a role. Each says what to do.
 */
import { mdiCogOutline, mdiFolderAccountOutline } from '@mdi/js'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import GuideLink from './GuideLink.vue'
import { adminSettingsUrl } from '../api.ts'
import { ADMIN_GUIDE, adminGuideUrl, USER_GUIDE, userGuideUrl } from '../docs.ts'
import { t } from '../l10n.ts'
import { startNotice } from '../notices.ts'
import { SETTINGS_SECTIONS } from '../settings.ts'
import { archiveNotShared, canChangeSettings, hasArchiveFolder, isAdmin, meta } from '../store.ts'

const hasNoRoleAsAdmin = computed(() => isAdmin.value && (meta.value?.roles.length ?? 0) === 0)

const shown = computed(() => hasNoRoleAsAdmin.value || !hasArchiveFolder.value || archiveNotShared.value || startNotice.value !== null)

/** The settings are a page under Administration → Music archive. */
const settingsUrl = adminSettingsUrl()
/** The access check on that page, opened right away. */
const accessCheckUrl = adminSettingsUrl(SETTINGS_SECTIONS.access)
</script>

<template>
	<div v-if="shown" class="app-notices no-print">
		<NcNoteCard v-if="startNotice !== null" :type="startNotice.type" :role="startNotice.type === 'error' ? 'alert' : 'status'">
			<p>{{ startNotice.text }}</p>
			<NcButton @click="startNotice = null">
				{{ t('notenarchiv', 'Close') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-if="canChangeSettings && !hasArchiveFolder" type="warning">
			<p>{{ t('notenarchiv', 'No archive folder has been chosen yet. Without it files cannot be linked and exports cannot be stored.') }}</p>
			<NcButton :href="settingsUrl">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCogOutline" />
				</template>
				{{ t('notenarchiv', 'Open settings') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-else-if="!hasArchiveFolder" type="info">
			{{ t('notenarchiv', 'No archive folder has been chosen yet. An administrator sets it in the settings of the music archive.') }}
		</NcNoteCard>
		<NcNoteCard v-if="archiveNotShared" type="warning">
			<p>{{ t('notenarchiv', 'The archive folder is not shared with your account, so its files (scores, scans, practice files) do not open for you. Please ask the music librarians to share the archive folder with you or your group.') }}</p>
			<NcButton v-if="canChangeSettings" :href="accessCheckUrl">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderAccountOutline" />
				</template>
				{{ t('notenarchiv', 'Check access to the archive folder') }}
			</NcButton>
			<p>
				<GuideLink :href="userGuideUrl(USER_GUIDE.filesNotShared)" :label="t('notenarchiv', 'Files you cannot open (user guide)')" />
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="hasNoRoleAsAdmin" type="info">
			<p>{{ t('notenarchiv', 'You are an administrator without a role: you can read the music archive and change the settings. The proposals inbox and internal preservation copies are only open to the roles concerned. To edit the catalog, add yourself to a group with a role.') }}</p>
			<p>
				<GuideLink :href="adminGuideUrl(ADMIN_GUIDE.roles)" :label="t('notenarchiv', 'Roles and groups (administration guide)')" />
			</p>
		</NcNoteCard>
	</div>
</template>

<style scoped>
.app-notices {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(2 * var(--default-grid-baseline));
	padding-inline-start: calc(var(--default-clickable-area) + 3 * var(--default-grid-baseline));
}

.app-notices p {
	margin-bottom: var(--default-grid-baseline);
}
</style>
