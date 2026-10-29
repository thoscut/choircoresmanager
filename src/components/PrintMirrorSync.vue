<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The mirror folder of the print station (module "print_mirror") or the
 * hand-over folder for a choir system (module "choir_handover"): where it
 * is, whether the daily sync runs, and the report of the last sync. "Sync
 * now" always shows the preview first (what would be copied, replaced,
 * removed or skipped) and syncs only when it is confirmed. Used in the list
 * of the print station or of the portal and in the settings.
 */
import type { MirrorKind, MirrorReport, MirrorStatus } from '../printMirror.ts'

import { mdiFolderSyncOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { errorMessage } from '../api.ts'
import { formatDateTime } from '../format.ts'
import { fetchMirrorStatus, hasChanges, previewMirror, reportSections, syncMirror } from '../printMirror.ts'

const props = withDefaults(defineProps<{
	/** read the status through the settings (administrators) */
	admin?: boolean
	/** changes when the target folder was saved: load again */
	folderId?: number
	/** which folder */
	kind?: MirrorKind
}>(), {
	admin: false,
	folderId: 0,
	kind: 'print',
})

const status = ref<MirrorStatus | null>(null)
const error = ref('')
const busy = ref(false)
/** The preview waiting for confirmation */
const preview = ref<MirrorReport | null>(null)

async function load(): Promise<void> {
	error.value = ''
	try {
		status.value = await fetchMirrorStatus(props.admin, props.kind)
	} catch (caught) {
		error.value = errorMessage(caught)
	}
}

onMounted(load)
watch(() => props.folderId, load)

async function showPreview(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		preview.value = await previewMirror(props.kind)
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

async function runSync(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const report = await syncMirror(props.kind)
		preview.value = null
		showSuccess(props.kind === 'handover'
			? t('cantoria', 'The hand-over folder for the choir system has been synced.')
			: t('cantoria', 'The mirror folder of the print station has been synced.'))
		status.value = { folder: status.value?.folder ?? null, started: true, report }
	} catch (caught) {
		error.value = errorMessage(caught)
		preview.value = null
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<section class="mirror-sync" :aria-label="kind === 'handover' ? t('cantoria', 'Hand-over to the choir system') : t('cantoria', 'Mirror folder for the print station')">
		<template v-if="status">
			<p v-if="status.folder">
				{{ t('cantoria', 'Target folder: {path} (account {user})', { path: status.folder.path, user: status.folder.user }) }}
			</p>
			<NcNoteCard v-else type="info">
				{{ admin
					? t('cantoria', 'Choose a target folder above. The app then shows a preview before it writes anything.')
					: t('cantoria', 'No target folder has been chosen yet. An administrator chooses it in the settings of the music archive.') }}
			</NcNoteCard>
			<p v-if="status.folder" class="mirror-sync__muted">
				{{ status.started
					? t('cantoria', 'The sync runs daily in the background. The app writes only into this folder and removes only files it wrote itself.')
					: t('cantoria', 'The daily sync starts after the first sync by hand. Look at the preview first.') }}
			</p>
			<div v-if="status.folder" class="mirror-sync__actions">
				<NcButton variant="primary" :disabled="busy" @click="showPreview">
					<template #icon>
						<NcLoadingIcon v-if="busy" />
						<NcIconSvgWrapper v-else :path="mdiFolderSyncOutline" />
					</template>
					{{ status.started ? t('cantoria', 'Sync now …') : t('cantoria', 'Preview and first sync …') }}
				</NcButton>
			</div>
		</template>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<div v-if="status?.report" class="mirror-sync__report">
			<h4>
				{{ t('cantoria', 'Last sync: {time}', { time: formatDateTime(status.report.time) }) }}
			</h4>
			<p>
				{{ n('cantoria', '%n file in the folder.', '%n files in the folder.', status.report.files) }}
				<template v-if="!hasChanges(status.report) && status.report.skipped.length === 0">
					{{ t('cantoria', 'Nothing had to change.') }}
				</template>
			</p>
			<section v-for="section in reportSections(status.report)" :key="section.key" class="mirror-sync__section">
				<h5>{{ section.title }} ({{ section.entries.length }})</h5>
				<ul>
					<li v-for="entry in section.entries" :key="section.key + entry.name">
						{{ entry.name }}
						<span v-if="entry.reasonText" class="mirror-sync__muted">{{ entry.reasonText }}</span>
					</li>
				</ul>
			</section>
		</div>

		<NcDialog v-if="preview"
			:name="t('cantoria', 'Preview of the sync')"
			:open="true"
			size="normal"
			@update:open="preview = null">
			<div class="mirror-sync__preview">
				<NcNoteCard v-if="!preview.writable" type="error">
					{{ t('cantoria', 'The account of the target folder may not write into it. Give it write permission or choose another folder in the settings.') }}
				</NcNoteCard>
				<p v-if="!hasChanges(preview)">
					{{ t('cantoria', 'The folder is up to date: nothing would be copied, replaced or removed.') }}
				</p>
				<p v-else>
					{{ t('cantoria', 'This is what the sync would do now. Files that the app did not write stay as they are.') }}
				</p>
				<section v-for="section in reportSections(preview)" :key="section.key" class="mirror-sync__section">
					<h3>{{ section.title }} ({{ section.entries.length }})</h3>
					<ul>
						<li v-for="entry in section.entries" :key="section.key + entry.name">
							{{ entry.name }}
							<span v-if="entry.reasonText" class="mirror-sync__muted">{{ entry.reasonText }}</span>
						</li>
					</ul>
				</section>
			</div>
			<template #actions>
				<NcButton :disabled="busy" @click="preview = null">
					{{ t('cantoria', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !preview.writable" @click="runSync">
					<template v-if="busy" #icon>
						<NcLoadingIcon />
					</template>
					{{ t('cantoria', 'Sync now') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
</template>

<style scoped>
.mirror-sync {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.mirror-sync p {
	margin: 0;
	max-width: 80ch;
	overflow-wrap: anywhere;
}

.mirror-sync__muted {
	display: block;
	color: var(--color-text-maxcontrast);
}

.mirror-sync__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.mirror-sync__report h4,
.mirror-sync__section h3,
.mirror-sync__section h5 {
	margin: var(--default-grid-baseline) 0;
	font-size: 1em;
	font-weight: bold;
}

.mirror-sync__section ul {
	margin: 0;
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
}

.mirror-sync__preview {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}
</style>
