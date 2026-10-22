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
			</template>
			<span v-else class="musicxml-tab__empty">{{ t('No MusicXML file uploaded yet.') }}</span>
		</div>

		<div v-if="editable" class="musicxml-tab__actions">
			<input
				ref="fileInput"
				type="file"
				accept=".musicxml,.xml,.mxl"
				class="hidden-visually"
				@change="onFileSelected">
			<NcButton :disabled="busy" @click="fileInput?.click()">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<UploadIcon v-else :size="20" />
				</template>
				{{ score.musicxmlFile ? t('Replace file') : t('Upload MusicXML') }}
			</NcButton>
			<NcButton
				v-if="score.musicxmlFile"
				variant="error"
				:disabled="busy"
				@click="onDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('Remove file') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="isPreservationCopy" type="warning">
			{{ t('This score is an internal preservation copy. Its notes are neither displayed nor played back, and only members of the editing groups can download the file.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="score.musicxmlFile && isPublishable(score)" type="info">
			{{ t('This score is approved for publication and its rights are confirmed, so it is shown on the public page if that page is enabled.') }}
		</NcNoteCard>

		<MusicXmlPlayer
			v-if="score.musicxmlFile && !isPreservationCopy"
			:key="`${score.id}-${score.musicxmlFile}-${uploadCount}`"
			:loadFile="loadFile" />
	</div>
</template>

<script setup lang="ts">
import type { Score } from '@/api/generated/openapi/data-contracts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MusicXmlPlayer from '@/components/MusicXmlPlayer.vue'
import { DeleteIcon, DownloadIcon, UploadIcon } from '@/icons/vue-material'
import { useScoreSidebarStore } from '@/stores/scoreSidebarStore'
import { useScoresStore } from '@/stores/scoresStore'
import { tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'
import { isInternalPreservationCopy, isPublishable } from '@/utils/scoreStatus'

interface Props {
	score: Score
	editable: boolean
}

const props = defineProps<Props>()

/**
 * Internal preservation copies are never rendered or played back, and only editors may download them
 */
const isPreservationCopy = computed(() => isInternalPreservationCopy(props.score))
const canDownload = computed(() => props.editable || !isPreservationCopy.value)

const scoresStore = useScoresStore()
const scoreSidebarStore = useScoreSidebarStore()

const fileInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const uploadCount = ref(0)

const MAX_SIZE = 20 * 1024 * 1024

/**
 * Load the score's MusicXML file for the player
 */
const loadFile = () => scoresStore.fetchMusicXml(props.score.id)

/**
 * Download the MusicXML file under its original name
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
	)
}

/**
 * Upload the selected file
 *
 * @param event - The change event of the file input
 */
async function onFileSelected(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (!file) {
		return
	}
	if (file.size > MAX_SIZE) {
		showError(t('The file is too large (maximum {size} MB)', { size: 20 }))
		return
	}

	busy.value = true
	await tryShowError(
		async () => {
			const updated = await scoresStore.uploadMusicXml(props.score.id, file)
			scoreSidebarStore.updateSelectedScore(updated)
			uploadCount.value++
			showSuccess(t('MusicXML file uploaded'))
		},
		t('Uploading the file failed: '),
	)
	busy.value = false
}

/**
 * Remove the MusicXML file
 */
async function onDelete(): Promise<void> {
	busy.value = true
	await tryShowError(
		async () => {
			const updated = await scoresStore.deleteMusicXml(props.score.id)
			scoreSidebarStore.updateSelectedScore(updated)
			showSuccess(t('MusicXML file removed'))
		},
		t('Removing the file failed: '),
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

	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: calc(2 * var(--default-grid-baseline));
	}
}
</style>
