<template>
	<div class="pdf-tab">
		<div class="pdf-tab__file">
			<template v-if="score.pdfFile">
				<span class="pdf-tab__file-name" :title="score.pdfFile">{{ score.pdfFile }}</span>
				<NcButton
					v-if="editable && score.pdfFileId !== null"
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
			<span v-else class="pdf-tab__empty">{{ t('No PDF file linked yet.') }}</span>
		</div>

		<template v-if="editable">
			<div class="pdf-tab__actions">
				<NcButton :disabled="busy" @click="onLink">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
						<LinkIcon v-else :size="20" />
					</template>
					{{ score.pdfFile ? t('Link another file') : t('Link PDF file') }}
				</NcButton>
				<NcButton
					v-if="score.pdfFile"
					variant="secondary"
					:disabled="busy"
					@click="onUnlink">
					<template #icon>
						<UnlinkIcon :size="20" />
					</template>
					{{ t('Unlink file') }}
				</NcButton>
			</div>
			<p class="pdf-tab__hint">
				{{ t('The file stays in Files, where it is uploaded, replaced and backed up. Unlinking does not delete it.') }}
			</p>
		</template>

		<NcNoteCard v-if="unavailableMessage" type="error">
			<p>{{ unavailableMessage }}</p>
			<p v-if="editable">
				{{ t('Check the file in Files or link it again.') }}
			</p>
		</NcNoteCard>

		<NcNoteCard v-if="score.pdfFile && isPreservationCopy" type="warning">
			{{ t('This score is an internal preservation copy. Its notes are neither displayed nor played back.') }}
		</NcNoteCard>

		<PdfViewer
			v-if="score.pdfFile && !isPreservationCopy && !unavailableMessage"
			:key="`${score.id}-${score.pdfFileId}-${linkCount}`"
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
import PdfViewer from '@/components/PdfViewer.vue'
import { LinkIcon, OpenExternalIcon, UnlinkIcon } from '@/icons/vue-material'
import { useScoresStore } from '@/stores/scoresStore'
import { extractErrorMessage, tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'
import { isInternalPreservationCopy } from '@/utils/scoreStatus'

interface Props {
	score: Score
	editable: boolean
}

const props = defineProps<Props>()

const PDF_FILE_NAME = /\.pdf$/i

/**
 * Internal preservation copies are never displayed. PDFs are only shown, never offered for download.
 */
const isPreservationCopy = computed(() => isInternalPreservationCopy(props.score))
const filesUrl = computed(() => generateUrl('/f/{fileId}', { fileId: props.score.pdfFileId ?? 0 }))

const scoresStore = useScoresStore()

const busy = ref(false)
const linkCount = ref(0)
/** Message of the server if the linked file is missing, not accessible or no longer valid */
const unavailableMessage = ref<string | null>(null)

watch(() => [props.score.id, props.score.pdfFileId], () => {
	unavailableMessage.value = null
})

/**
 * Load the score's PDF file for the viewer, and remember why it is unavailable if the server says so
 */
async function loadFile(): Promise<ArrayBuffer> {
	try {
		return await scoresStore.fetchPdf(props.score.id)
	} catch (error) {
		if ((error as { response?: { status?: number } }).response?.status === 404) {
			unavailableMessage.value = extractErrorMessage(error)
		}
		throw error
	}
}

/**
 * Let the user pick a PDF file in Files and link it to the score
 */
async function onLink(): Promise<void> {
	const picker = getFilePickerBuilder(t('Choose a PDF file'))
		.setMultiSelect(false)
		.allowDirectories(false)
		.setFilter((node) => node.type === 'folder' || PDF_FILE_NAME.test(node.basename))
		.setCanPick((node) => node.type !== 'folder' && PDF_FILE_NAME.test(node.basename))
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
			await scoresStore.linkPdf(props.score.id, fileId)
			unavailableMessage.value = null
			linkCount.value++
			showSuccess(t('PDF file linked'))
		},
		t('Linking the file failed: '),
	)
	busy.value = false
}

/**
 * Remove the link to the PDF file; the file itself stays in Files
 */
async function onUnlink(): Promise<void> {
	busy.value = true
	await tryShowError(
		async () => {
			await scoresStore.unlinkPdf(props.score.id)
			showSuccess(t('PDF file unlinked'))
		},
		t('Unlinking the file failed: '),
	)
	busy.value = false
}
</script>

<style lang="scss" scoped>
.pdf-tab {
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
