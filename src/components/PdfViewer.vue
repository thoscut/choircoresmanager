<template>
	<div class="pdf-viewer">
		<div v-if="status === 'ready'" class="pdf-viewer__toolbar">
			<NcButton
				variant="tertiary"
				:disabled="zoom <= MIN_ZOOM"
				:aria-label="t('Zoom out')"
				:title="t('Zoom out')"
				@click="setZoom(zoom - ZOOM_STEP)">
				<template #icon>
					<ZoomOutIcon :size="20" />
				</template>
			</NcButton>
			<span class="pdf-viewer__zoom">{{ Math.round(zoom * 100) }}%</span>
			<NcButton
				variant="tertiary"
				:disabled="zoom >= MAX_ZOOM"
				:aria-label="t('Zoom in')"
				:title="t('Zoom in')"
				@click="setZoom(zoom + ZOOM_STEP)">
				<template #icon>
					<ZoomInIcon :size="20" />
				</template>
			</NcButton>
			<span class="pdf-viewer__pages">{{ n('%n page', '%n pages', pageCount) }}</span>
		</div>

		<div v-if="status === 'loading'" class="pdf-viewer__state">
			<NcLoadingIcon :size="32" />
			<span>{{ t('Loading PDF…') }}</span>
		</div>
		<NcNoteCard v-else-if="status === 'error'" type="error">
			{{ t('The PDF could not be displayed') }}
		</NcNoteCard>

		<!-- Pages are drawn into canvases by pdf.js; there is deliberately no download or print action -->
		<div
			ref="pagesContainer"
			class="pdf-viewer__pages-container"
			@contextmenu.prevent />
	</div>
</template>

<script setup lang="ts">
import type { PDFDocumentProxy } from 'pdfjs-dist'

import debounce from 'lodash.debounce'
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ZoomInIcon, ZoomOutIcon } from '@/icons/vue-material'
import { n, t } from '@/utils/l10n'

interface Props {
	/**
	 * Function loading the PDF file
	 */
	loadFile: () => Promise<ArrayBuffer>
}

const props = defineProps<Props>()

const MIN_ZOOM = 0.5
const MAX_ZOOM = 3
const ZOOM_STEP = 0.25

const status = ref<'loading' | 'ready' | 'error'>('loading')
const pageCount = ref(0)
const zoom = ref(1)
const pagesContainer = ref<HTMLElement | null>(null)
const pdf = shallowRef<PDFDocumentProxy | null>(null)
let renderGeneration = 0

/**
 * Load pdf.js once, with its worker served from the app's own scripts
 */
async function loadPdfJs() {
	const [pdfjs, { default: workerUrl }] = await Promise.all([
		import('pdfjs-dist'),
		import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
	])
	pdfjs.GlobalWorkerOptions.workerSrc = workerUrl
	return pdfjs
}

/**
 * Fetch and open the PDF
 */
async function loadPdf(): Promise<void> {
	status.value = 'loading'
	try {
		const [pdfjs, data] = await Promise.all([loadPdfJs(), props.loadFile()])
		await pdf.value?.destroy()
		pdf.value = await pdfjs.getDocument({
			data: new Uint8Array(data),
			// Scores never need form fields
			enableXfa: false,
		}).promise
		pageCount.value = pdf.value.numPages
		status.value = 'ready'
		await renderPages()
	} catch (error) {
		console.error('Failed to load PDF:', error)
		status.value = 'error'
	}
}

/**
 * Draw all pages at the container width times the zoom level
 */
async function renderPages(): Promise<void> {
	const doc = pdf.value
	const container = pagesContainer.value
	if (!doc || !container) {
		return
	}
	const generation = ++renderGeneration
	const width = (container.clientWidth || 800) * zoom.value
	const pixelRatio = window.devicePixelRatio || 1
	const canvases: HTMLCanvasElement[] = []

	for (let pageNumber = 1; pageNumber <= doc.numPages; pageNumber++) {
		const page = await doc.getPage(pageNumber)
		if (generation !== renderGeneration) {
			return
		}
		const unscaled = page.getViewport({ scale: 1 })
		const viewport = page.getViewport({ scale: width / unscaled.width })
		const canvas = document.createElement('canvas')
		canvas.className = 'pdf-viewer__page'
		canvas.width = Math.floor(viewport.width * pixelRatio)
		canvas.height = Math.floor(viewport.height * pixelRatio)
		canvas.style.width = `${Math.floor(viewport.width)}px`
		canvas.style.height = `${Math.floor(viewport.height)}px`
		canvas.setAttribute('aria-label', t('Page {page} of {count}', { page: pageNumber, count: doc.numPages }))
		await page.render({
			canvas,
			viewport,
			transform: pixelRatio !== 1 ? [pixelRatio, 0, 0, pixelRatio, 0, 0] : undefined,
		}).promise
		canvases.push(canvas)
		if (pageNumber === 1 && generation === renderGeneration) {
			// Show the first page as soon as it is ready
			container.replaceChildren(canvas)
		}
	}
	if (generation === renderGeneration) {
		container.replaceChildren(...canvases)
	}
}

/**
 * @param value - New zoom level
 */
function setZoom(value: number): void {
	zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value))
	renderPages()
}

const onResize = debounce(() => renderPages(), 300)
let resizeObserver: ResizeObserver | null = null
let lastWidth = 0

onMounted(() => {
	loadPdf()
	if (pagesContainer.value) {
		resizeObserver = new ResizeObserver((entries) => {
			const width = Math.round(entries[0].contentRect.width)
			if (width > 0 && Math.abs(width - lastWidth) > 20) {
				lastWidth = width
				onResize()
			}
		})
		resizeObserver.observe(pagesContainer.value)
	}
})

watch(() => props.loadFile, () => loadPdf())

onBeforeUnmount(() => {
	resizeObserver?.disconnect()
	onResize.cancel()
	renderGeneration++
	pdf.value?.destroy()
})
</script>

<style lang="scss" scoped>
.pdf-viewer {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));

	&__toolbar {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__zoom {
		min-width: 3.5em;
		text-align: center;
		font-variant-numeric: tabular-nums;
	}

	&__pages {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
	}

	&__state {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__pages-container {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: calc(2 * var(--default-grid-baseline));
		width: 100%;
		overflow-x: auto;
		user-select: none;

		:deep(.pdf-viewer__page) {
			display: block;
			background: #fff;
			box-shadow: 0 0 4px var(--color-box-shadow);
		}
	}
}
</style>
