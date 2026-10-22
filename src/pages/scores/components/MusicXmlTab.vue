<template>
	<div class="musicxml-tab">
		<div class="musicxml-tab__file">
			<template v-if="score.musicxmlFile">
				<span class="musicxml-tab__file-name" :title="score.musicxmlFile">{{ score.musicxmlFile }}</span>
				<NcButton
					v-if="canDownload"
					variant="tertiary"
					:aria-label="t('Download MusicXML')"
					:title="t('Download MusicXML')"
					@click="onDownload">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
				</NcButton>
				<NcButton
					v-if="editable && score.musicxmlFileId !== null"
					variant="tertiary"
					:href="filesUrl"
					target="_blank"
					:aria-label="t('Show in Files')"
					:title="t('Show in Files')">
					<template #icon>
						<OpenExternalIcon :size="20" />
					</template>
				</NcButton>
			</template>
			<span v-else class="musicxml-tab__empty">{{ t('No MusicXML file linked yet.') }}</span>
		</div>

		<template v-if="editable">
			<div class="musicxml-tab__actions">
				<NcButton :disabled="busy" @click="onLink">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
						<LinkIcon v-else :size="20" />
					</template>
					{{ score.musicxmlFile ? t('Link another file') : t('Link MusicXML file') }}
				</NcButton>
				<NcButton
					v-if="score.musicxmlFile"
					variant="secondary"
					:disabled="busy"
					@click="onUnlink">
					<template #icon>
						<UnlinkIcon :size="20" />
					</template>
					{{ t('Unlink file') }}
				</NcButton>
			</div>
			<p class="musicxml-tab__hint">
				{{ t('The file stays in Files, where it is uploaded, replaced and backed up. Unlinking does not delete it.') }}
			</p>
		</template>

		<NcNoteCard v-if="unavailableMessage" type="error">
			<p>{{ unavailableMessage }}</p>
			<p v-if="editable">
				{{ t('Check the file in Files or link it again.') }}
			</p>
		</NcNoteCard>

		<NcNoteCard v-if="isPreservationCopy" type="warning">
			{{ t('This score is an internal preservation copy. Its notes are neither displayed nor played back, and only members of the editing groups can download the file.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="score.musicxmlFile && isPublishable(score)" type="info">
			{{ t('This score is approved for publication and its rights are confirmed, so it is shown on the public page if that page is enabled.') }}
		</NcNoteCard>

		<MusicXmlPlayer
			v-if="score.musicxmlFile && !isPreservationCopy && !unavailableMessage"
			:key="`${score.id}-${score.musicxmlFileId}-${linkCount}`"
			:loadFile="loadFile" />
	</div>
</template>

<script setup lang="ts">
import type { Score } from '@/api/generated/openapi/data-contracts'

import { FilePickerClosed, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MusicXmlPlayer from '@/components/MusicXmlPlayer.vue'
import { DownloadIcon, LinkIcon, OpenExternalIcon, UnlinkIcon } from '@/icons/vue-material'
import { useScoresStore } from '@/stores/scoresStore'
import { extractErrorMessage, tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'
import { isInternalPreservationCopy, isPublishable } from '@/utils/scoreStatus'

interface Props {
	score: Score
	editable: boolean
}

const props = defineProps<Props>()

const MUSICXML_FILE_NAME = /\.(musicxml|xml|mxl)$/i

/**
 * Internal preservation copies are never rendered or played back, and only editors may download them
 */
const isPreservationCopy = computed(() => isInternalPreservationCopy(props.score))
const canDownload = computed(() => props.editable || !isPreservationCopy.value)
const filesUrl = computed(() => generateUrl('/f/{fileId}', { fileId: props.score.musicxmlFileId ?? 0 }))

const scoresStore = useScoresStore()

const busy = ref(false)
const linkCount = ref(0)
/** Message of the server if the linked file is missing, not accessible or no longer valid */
const unavailableMessage = ref<string | null>(null)

watch(() => [props.score.id, props.score.musicxmlFileId], () => {
	unavailableMessage.value = null
})

/**
 * Remember why the linked file is unavailable, if the server says so
 *
 * @param error - The error of a failed request for the file content
 */
function rememberIfUnavailable(error: unknown): void {
	if ((error as { response?: { status?: number } }).response?.status === 404) {
		unavailableMessage.value = extractErrorMessage(error)
	}
}

/**
 * Load the score's MusicXML file for the player
 */
async function loadFile(): Promise<ArrayBuffer> {
	try {
		return await scoresStore.fetchMusicXml(props.score.id)
	} catch (error) {
		rememberIfUnavailable(error)
		throw error
	}
}

/**
 * Download the MusicXML file under its name in Files
 */
async function onDownload(): Promise<void> {
	await tryShowError(
		async () => {
			const data = await scoresStore.fetchMusicXml(props.score.id)
			const url = URL.createObjectURL(new Blob([data], { type: 'application/octet-stream' }))
			const link = document.createElement('a')
			link.href = url
			link.download = props.score.musicxmlFile ?? `score-${props.score.id}.musicxml`
			link.click()
			URL.revokeObjectURL(url)
		},
		t('Downloading the file failed: '),
		rememberIfUnavailable,
	)
}

/**
 * Let the user pick a MusicXML file in Files and link it to the score
 */
async function onLink(): Promise<void> {
	const picker = getFilePickerBuilder(t('Choose a MusicXML file'))
		.setMultiSelect(false)
		.allowDirectories(false)
		.setFilter((node) => node.type === 'folder' || MUSICXML_FILE_NAME.test(node.basename))
		.setCanPick((node) => node.type !== 'folder' && MUSICXML_FILE_NAME.test(node.basename))
		.addButton({
			label: t('Link file'),
			variant: 'primary',
			callback: () => {},
		})
		.build()

	let pickedFileId: number | undefined
	try {
		const nodes = await picker.pickNodes()
		pickedFileId = nodes[0]?.fileid
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			console.error('File picker failed:', error)
		}
		return
	}
	if (pickedFileId === undefined) {
		return
	}
	const fileId = pickedFileId

	busy.value = true
	await tryShowError(
		async () => {
			await scoresStore.linkMusicXml(props.score.id, fileId)
			unavailableMessage.value = null
			linkCount.value++
			showSuccess(t('MusicXML file linked'))
		},
		t('Linking the file failed: '),
	)
	busy.value = false
}

/**
 * Remove the link to the MusicXML file; the file itself stays in Files
 */
async function onUnlink(): Promise<void> {
	busy.value = true
	await tryShowError(
		async () => {
			await scoresStore.unlinkMusicXml(props.score.id)
			showSuccess(t('MusicXML file unlinked'))
		},
		t('Unlinking the file failed: '),
	)
	busy.value = false
}
</script>

<style lang="scss" scoped>
.musicxml-tab {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));

	&__file {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__file-name {
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__empty,
	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: calc(2 * var(--default-grid-baseline));
	}
}
</style>
