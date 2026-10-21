<template>
	<div class="score-file-tab">
		<div class="score-file-tab__file">
			<template v-if="fileName">
				<span class="score-file-tab__file-name" :title="fileName">{{ fileName }}</span>
				<NcButton
					v-if="config.downloadable && canAccessFile"
					variant="tertiary"
					:aria-label="config.downloadLabel"
					:title="config.downloadLabel"
					@click="onDownload">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
				</NcButton>
			</template>
			<span v-else class="score-file-tab__empty">{{ config.emptyText }}</span>
		</div>

		<div v-if="editable" class="score-file-tab__actions">
			<input
				ref="fileInput"
				type="file"
				:accept="config.accept"
				class="hidden-visually"
				@change="onFileSelected">
			<NcButton :disabled="busy" @click="fileInput?.click()">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<UploadIcon v-else :size="20" />
				</template>
				{{ fileName ? t('Replace file') : config.uploadLabel }}
			</NcButton>
			<NcButton
				v-if="fileName"
				variant="error"
				:disabled="busy"
				@click="onDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('Remove file') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="kind === 'musicxml' && fileName && isPublic(score)" type="info">
			{{ t('This score is released for publication and its rights allow it, so it is shown on the public page if that page is enabled.') }}
		</NcNoteCard>

		<NcNoteCard v-if="fileName && score.publicationStatus === 'internal_copy'" type="warning">
			{{ editable
				? t('This score is an internal preservation copy. Only editors can view and play it.')
				: t('This score is an internal preservation copy. It can only be viewed by the music librarians.') }}
		</NcNoteCard>

		<template v-if="fileName && canAccessFile">
			<MusicXmlPlayer
				v-if="kind === 'musicxml'"
				:key="`${score.id}-${fileName}-${uploadCount}`"
				:loadFile="loadFile" />
			<PdfViewer
				v-else
				:key="`${score.id}-${fileName}-${uploadCount}`"
				:loadFile="loadFile" />
		</template>
	</div>
</template>

<script setup lang="ts">
import type { Score } from '@/api/generated/openapi/data-contracts'
import type { ScoreFileKind } from '@/stores/scoresStore'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import MusicXmlPlayer from '@/components/MusicXmlPlayer.vue'
import PdfViewer from '@/components/PdfViewer.vue'
import { DeleteIcon, DownloadIcon, UploadIcon } from '@/icons/vue-material'
import { useScoreSidebarStore } from '@/stores/scoreSidebarStore'
import { useScoresStore } from '@/stores/scoresStore'
import { tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'
import { isPublic } from '@/utils/rightsStatus'

interface Props {
	score: Score
	editable: boolean
	kind: ScoreFileKind
}

const props = defineProps<Props>()

const scoresStore = useScoresStore()
const scoreSidebarStore = useScoreSidebarStore()

const fileInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const uploadCount = ref(0)

const config = computed(() => props.kind === 'musicxml'
	? {
			accept: '.musicxml,.xml,.mxl',
			maxSizeMb: 20,
			downloadable: true,
			downloadLabel: t('Download MusicXML'),
			emptyText: t('No MusicXML file uploaded yet.'),
			uploadLabel: t('Upload MusicXML'),
		}
	: {
			accept: '.pdf,application/pdf',
			maxSizeMb: 50,
			// PDFs are only shown, not offered for download
			downloadable: false,
			downloadLabel: '',
			emptyText: t('No PDF file uploaded yet.'),
			uploadLabel: t('Upload PDF'),
		})

const fileName = computed(() => (props.kind === 'musicxml' ? props.score.musicxmlFile : props.score.pdfFile) ?? null)

/**
 * Internal preservation copies are only shown to editors, the server enforces the same rule
 */
const canAccessFile = computed(() => props.editable || props.score.publicationStatus !== 'internal_copy')

/**
 * Load the file for the player or viewer
 */
const loadFile = () => scoresStore.fetchScoreFile(props.score.id, props.kind)

/**
 * Download the file under its original name
 */
async function onDownload(): Promise<void> {
	await tryShowError(
		async () => {
			const data = await scoresStore.fetchScoreFile(props.score.id, props.kind)
			const url = URL.createObjectURL(new Blob([data], { type: 'application/octet-stream' }))
			const link = document.createElement('a')
			link.href = url
			link.download = fileName.value ?? `score-${props.score.id}`
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
	if (file.size > config.value.maxSizeMb * 1024 * 1024) {
		showError(t('The file is too large (maximum {size} MB)', { size: config.value.maxSizeMb }))
		return
	}

	busy.value = true
	await tryShowError(
		async () => {
			const updated = await scoresStore.uploadScoreFile(props.score.id, props.kind, file)
			scoreSidebarStore.updateSelectedScore(updated)
			uploadCount.value++
			showSuccess(t('File uploaded'))
		},
		t('Uploading the file failed: '),
	)
	busy.value = false
}

/**
 * Remove the file
 */
async function onDelete(): Promise<void> {
	busy.value = true
	await tryShowError(
		async () => {
			const updated = await scoresStore.deleteScoreFile(props.score.id, props.kind)
			scoreSidebarStore.updateSelectedScore(updated)
			showSuccess(t('File removed'))
		},
		t('Removing the file failed: '),
	)
	busy.value = false
}
</script>

<style lang="scss" scoped>
.score-file-tab {
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
