<script setup lang="ts">
/**
 * Score view: renders the MusicXML of a copy with Verovio, shows a range of
 * measures in printed numbering, highlights a focus voice and plays the
 * score with a small built-in synthesizer. Practice files (WAV/MIDI) of the
 * same choice are made in PracticeAudioDialog.
 *
 * This component is loaded lazily (own chunk); Verovio is a further chunk
 * that is only fetched when a score is opened.
 */
import type { PlayMode, ScorePlayer as ScorePlayerType } from '../score/player.ts'
import type { MeasureRange } from '../score/range.ts'
import type { ScoreModel } from '../score/model.ts'
import type { Timeline } from '../score/timeline.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'
import type { ScoreRequest } from '../store.ts'
import type { Detail } from '../types.ts'

import {
	mdiAlertCircleOutline,
	mdiMagnifyMinusOutline,
	mdiMagnifyPlusOutline,
	mdiPause,
	mdiPlay,
	mdiStop,
	mdiClipboardTextOutline,
	mdiContentCopy,
	mdiFileMusicOutline,
	mdiLinkVariantPlus,
} from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import LinkDialog from './LinkDialog.vue'
import RightsBadge from './RightsBadge.vue'
import TaskDialog from './TaskDialog.vue'
import { fetchFileContent } from '../api.ts'
import { translateLabel } from '../format.ts'
import { playbackTimeline, renderPages, selectMeasures, loadScore } from '../score/model.ts'
import { markFocus } from '../score/emphasis.ts'
import { ArchiveLimitError, readScoreText } from '../score/mxl.ts'
import { ScorePlayer, voiceGains } from '../score/player.ts'
import { resolveRange } from '../score/range.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'
import { voiceLabel } from '../score/voices.ts'
import { copyText } from '../clipboard.ts'
import { focusAfterDialog } from '../focus.ts'
import { referenceWords } from '../labels.ts'
import { textReference } from '../references.ts'
import { canCreateLinks, canCreateTasks, fieldInfo, fieldLabel, isModuleActive } from '../store.ts'

/** Practice audio files: own chunk, loaded when the dialog is opened */
const PracticeAudioDialog = defineAsyncComponent(() => import('./PracticeAudioDialog.vue'))

const props = defineProps<{
	detail: Detail
	/** what to show first: range, voice, tempo, a notice (from a link or a task) */
	start?: ScoreRequest | null
}>()

const emit = defineEmits<{
	close: []
	/** a task or link was created */
	changed: []
}>()

/** Verovio scale per zoom step; ZOOM_DEFAULT is shown as 100 %. */
const ZOOM_STEPS = [16, 20, 24, 28, 32, 36, 40, 46, 52, 60, 70, 80]
const ZOOM_DEFAULT = 6

const headingId = useId()
const summaryId = useId()
const tempoId = useId()
const tempoHintId = useId()
const volumeId = useId()

const score = computed(() => props.detail.score)
/** Playback (module "playback"); the server also refuses it when switched off. */
const playbackOn = computed(() => isModuleActive('playback'))
const canPlay = computed(() => playbackOn.value && score.value?.canPlay === true)

// ---- header -----------------------------------------------------------------

const shelfmark = computed(() => String(props.detail.copy.shelfmark ?? ''))
const title = computed(() => String(props.detail.work.title ?? ''))
const composer = computed(() => String(props.detail.work.composer ?? ''))
const heading = computed(() => [shelfmark.value, title.value].filter((part) => part !== '').join(' · '))
const review = computed(() => String(props.detail.version.musicxml_review ?? '').trim())
const reviewLabel = computed(() => fieldInfo('version', 'musicxml_review') ? fieldLabel('version', 'musicxml_review') : t('notenarchiv', 'MusicXML review'))

// ---- loading ----------------------------------------------------------------

const status = ref<'loading' | 'ready' | 'error'>('loading')
const loadingText = ref('')
const errorText = ref('')
const model = shallowRef<ScoreModel | null>(null)
const pages = shallowRef<string[]>([])
const scoreElement = ref<HTMLElement | null>(null)
const pagesElement = ref<HTMLElement | null>(null)

let toolkit: VerovioToolkit | null = null
let player: ScorePlayerType | null = null
let disposed = false
let resizeObserver: ResizeObserver | null = null
let lastWidth = 0

/** Let the browser paint (e.g. the loading indicator) before heavy work. */
function nextFrame(): Promise<void> {
	return new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

async function load(): Promise<void> {
	const info = score.value
	if (!info) {
		return
	}
	status.value = 'loading'
	errorText.value = ''
	loadingText.value = t('notenarchiv', 'Loading the score …')
	// Fetch the file and load Verovio at the same time. A toolkit created after
	// the viewer was closed is destroyed right away.
	const toolkitPromise = toolkit ? Promise.resolve(toolkit) : createToolkit()
	toolkitPromise.then((created) => {
		if (disposed) {
			created.destroy()
		} else {
			toolkit = created
		}
	}, () => {})
	let content: ArrayBuffer
	try {
		content = await fetchFileContent(info.fileId)
		await toolkitPromise
	} catch (error) {
		status.value = 'error'
		errorText.value = error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'The score could not be loaded.')
		return
	}
	const engraver = toolkit
	if (disposed || !engraver) {
		return
	}
	try {
		loadingText.value = t('notenarchiv', 'Engraving the score …')
		const text = await readScoreText(content)
		await nextFrame()
		if (disposed) {
			return
		}
		const loaded = loadScore(engraver, text)
		model.value = loaded
		rangeFrom.value = loaded.measures[0]?.number ?? ''
		rangeTo.value = loaded.measures[loaded.measures.length - 1]?.number ?? ''
		range.value = null
		applyStart(loaded)
		status.value = 'ready'
		await nextTick()
		if (rangeStartPending) {
			rangeStartPending = false
			applyRange()
		} else {
			layout()
			updateTimeline()
		}
	} catch (error) {
		console.error('Score view: the file could not be read', error)
		status.value = 'error'
		errorText.value = error instanceof ArchiveLimitError
			? t('notenarchiv', 'The score file is too large to show.')
			: t('notenarchiv', 'The file could not be read as MusicXML.')
	}
}

let rangeStartPending = false

/**
 * Take over what a link or a task asks to show: range, voice, tempo.
 *
 * @param loaded - the loaded score
 */
function applyStart(loaded: ScoreModel): void {
	const start = props.start
	if (!start) {
		return
	}
	if (start.measureFrom || start.measureTo) {
		rangeFrom.value = start.measureFrom || start.measureTo || rangeFrom.value
		rangeTo.value = start.measureTo || start.measureFrom || rangeTo.value
		rangeStartPending = true
	}
	if (start.voice) {
		const wanted = start.voice.trim().toLowerCase()
		const voice = loaded.voices.find((candidate) => labelOf(candidate).toLowerCase() === wanted)
			?? loaded.voices.find((candidate) => (candidate.name ?? '').toLowerCase() === wanted)
		if (voice) {
			focusKey.value = voice.key
			mode.value = 'focus'
		}
	}
	if (start.tempo && start.tempo >= 25 && start.tempo <= 200) {
		tempoPercent.value = Math.round(start.tempo / 5) * 5
	}
}

// ---- sharing and tasks --------------------------------------------------------

const taskDialogOpen = ref(false)
const linkDialogOpen = ref(false)

/** The shown range in printed numbers, or null for the whole score. */
const rangeNumbers = computed(() => {
	const loaded = model.value
	if (!loaded || range.value === null) {
		return null
	}
	return { from: loaded.measures[range.value.start]?.number ?? '', to: loaded.measures[range.value.end]?.number ?? '' }
})

const focusLabel = computed(() => (focusVoice.value ? labelOf(focusVoice.value) : null))

/** Text reference of the shown place, in the language of the UI. */
const reference = computed(() => textReference({
	shelfmark: shelfmark.value,
	title: title.value,
	measureFrom: rangeNumbers.value?.from ?? null,
	measureTo: rangeNumbers.value?.to ?? null,
	voice: focusLabel.value,
}, referenceWords()))

async function copyReference(): Promise<void> {
	if (await copyText(reference.value)) {
		showSuccess(t('notenarchiv', 'Copied: {reference}', { reference: reference.value }))
	} else {
		showError(t('notenarchiv', 'The text could not be copied. Please select and copy it by hand.'))
	}
}

const placePrefill = computed(() => ({
	measureFrom: rangeNumbers.value?.from ?? null,
	measureTo: rangeNumbers.value?.to ?? null,
	voice: focusLabel.value,
	tempo: tempoPercent.value,
}))

function onTaskCreated(): void {
	taskDialogOpen.value = false
	emit('changed')
}

// ---- layout and rendering ---------------------------------------------------

const zoomIndex = ref(ZOOM_DEFAULT)
const zoomPercent = computed(() => Math.round(ZOOM_STEPS[zoomIndex.value] / ZOOM_STEPS[ZOOM_DEFAULT] * 100))

/** Render the current range at the current width and zoom. */
function layout(): void {
	const container = scoreElement.value
	const loaded = model.value
	if (!toolkit || !container || !loaded) {
		return
	}
	const scale = ZOOM_STEPS[zoomIndex.value]
	const width = Math.max(280, container.clientWidth - 16)
	lastWidth = container.clientWidth
	toolkit.setOptions({ scale, pageWidth: Math.min(100000, Math.max(100, Math.round(width * 100 / scale))) })
	selectMeasures(toolkit, loaded, range.value)
	// cleaned before it goes into the page with v-html
	pages.value = renderPages(toolkit).map(sanitizeSvg)
	nextTick(() => {
		indexElements()
		applyFocus()
		resetHighlights()
		if (player?.isPlaying) {
			updatePosition(player.currentTime())
		}
	})
}

/**
 * Zoom the score in or out.
 *
 * @param step - +1 bigger, -1 smaller
 */
function zoomBy(step: number): void {
	const next = Math.min(ZOOM_STEPS.length - 1, Math.max(0, zoomIndex.value + step))
	if (next !== zoomIndex.value) {
		zoomIndex.value = next
		layout()
	}
}

let wheelLocked = false

/**
 * Ctrl + mouse wheel zooms the score instead of the page.
 *
 * @param event - wheel event
 */
function onWheel(event: WheelEvent): void {
	if (!event.ctrlKey) {
		return
	}
	event.preventDefault()
	if (wheelLocked) {
		return
	}
	wheelLocked = true
	setTimeout(() => {
		wheelLocked = false
	}, 150)
	zoomBy(event.deltaY < 0 ? 1 : -1)
}

let resizeTimer: ReturnType<typeof setTimeout> | null = null

function onResize(): void {
	if (resizeTimer !== null) {
		clearTimeout(resizeTimer)
	}
	resizeTimer = setTimeout(() => {
		const width = scoreElement.value?.clientWidth ?? 0
		if (Math.abs(width - lastWidth) > 40) {
			layout()
		}
	}, 250)
}

// ---- elements in the SVG ----------------------------------------------------

const noteElements = new Map<string, Element>()
const measureElements = new Map<string, SVGGraphicsElement>()

function indexElements(): void {
	noteElements.clear()
	measureElements.clear()
	const root = pagesElement.value
	if (!root) {
		return
	}
	for (const element of root.querySelectorAll('g.note[data-id]')) {
		noteElements.set(element.getAttribute('data-id') ?? '', element)
	}
	for (const element of root.querySelectorAll<SVGGraphicsElement>('g.measure[data-id]')) {
		measureElements.set(element.getAttribute('data-id') ?? '', element)
	}
	measureMarker = null
}

// ---- measure range ----------------------------------------------------------

const rangeFrom = ref('')
const rangeTo = ref('')
const range = ref<MeasureRange | null>(null)
const rangeError = ref('')

const numberSpan = computed(() => {
	const measures = model.value?.measures ?? []
	return measures.length > 0 ? { first: measures[0].number, last: measures[measures.length - 1].number } : { first: '', last: '' }
})

const rangeHint = computed(() => {
	if (!model.value) {
		return ''
	}
	const hint = t('notenarchiv', 'Printed measure numbers {first} to {last}.', numberSpan.value)
	return model.value.pickup ? hint + ' ' + t('notenarchiv', 'The pickup is measure 0.') : hint
})

function applyRange(): void {
	const loaded = model.value
	if (!loaded) {
		return
	}
	const from = rangeFrom.value.trim() || numberSpan.value.first
	const to = rangeTo.value.trim() || numberSpan.value.last
	const result = resolveRange(loaded.measures, from, to)
	if ('error' in result) {
		rangeError.value = result.error === 'order'
			? t('notenarchiv', 'Measure {to} does not come after measure {from}.', { from, to })
			: t('notenarchiv', 'There is no measure {number} in this score.', { number: result.error === 'from' ? from : to })
		return
	}
	rangeError.value = ''
	const whole = result.start === 0 && result.end === loaded.measures.length - 1
	range.value = whole ? null : result
	layout()
	updateTimeline()
}

function showAllMeasures(): void {
	rangeFrom.value = numberSpan.value.first
	rangeTo.value = numberSpan.value.last
	rangeError.value = ''
	range.value = null
	layout()
	updateTimeline()
}

const rangeText = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	const first = loaded.measures[range.value?.start ?? 0]?.number ?? ''
	const last = loaded.measures[range.value?.end ?? loaded.measures.length - 1]?.number ?? ''
	return t('notenarchiv', 'Measures {from} to {to}', { from: first, to: last })
})

// ---- voices -----------------------------------------------------------------

const focusKey = ref('')

/**
 * Name of a voice for display.
 *
 * @param voice - the voice
 */
function labelOf(voice: Voice): string {
	return voiceLabel(voice, (part, number, staff) => part !== ''
		? t('notenarchiv', '{part}, voice {number}', { part, number })
		: t('notenarchiv', 'Staff {staff}, voice {number}', { staff, number }))
}

interface VoiceOption {
	id: string
	label: string
}

const voiceOptions = computed<VoiceOption[]>(() => [
	{ id: '', label: t('notenarchiv', 'No focus voice') },
	...(model.value?.voices ?? []).map((voice) => ({ id: voice.key, label: labelOf(voice) })),
])

const focusOption = computed<VoiceOption>({
	get: () => voiceOptions.value.find((option) => option.id === focusKey.value) ?? voiceOptions.value[0],
	set: (option) => {
		focusKey.value = option?.id ?? ''
	},
})

const focusVoice = computed(() => model.value?.voices.find((voice) => voice.key === focusKey.value) ?? null)

/** Focus voice in color with bolder notes, the other voices lighter (emphasis.ts). */
function applyFocus(): void {
	if (pagesElement.value) {
		markFocus(pagesElement.value, focusVoice.value?.members ?? [])
	}
}

// ---- playback ---------------------------------------------------------------

const playing = ref(false)
const mode = ref<PlayMode>('all')
const repeats = ref(true)
const follow = ref(true)
const tempoPercent = ref(100)
const volumePercent = ref(70)
const position = ref(0)
const timeline = shallowRef<Timeline>({ events: [], measures: [], duration: 0 })

// The server sends the reason translated; translateLabel also covers an English one
const playReason = computed(() => score.value?.reason
	? translateLabel(score.value.reason)
	: t('notenarchiv', 'Playback is not available for this version.'))

const BEAT_SYMBOLS: Record<string, string> = {
	whole: '𝅝',
	half: '𝅗𝅥',
	quarter: '♩',
	eighth: '♪',
	'16th': '𝅘𝅥𝅯',
}

/**
 * Tempo marking as text, e.g. "♩. = 72".
 *
 * @param factor - tempo factor (1 = as in the file)
 */
function tempoMark(factor: number): string {
	const tempo = model.value?.tempo
	if (tempo) {
		const symbol = (BEAT_SYMBOLS[tempo.beatUnit] ?? tempo.beatUnit) + '.'.repeat(tempo.dots)
		return `${symbol} = ${Math.round(tempo.perMinute * factor)}`
	}
	return `♩ = ${Math.round((model.value?.quarterTempo ?? 120) * factor)}`
}

const tempoLabel = computed(() => t('notenarchiv', 'Tempo: {percent} % ({mark})', { percent: tempoPercent.value, mark: tempoMark(tempoPercent.value / 100) }))

const tempoHint = computed(() => {
	const loaded = model.value
	if (loaded?.tempo?.printed) {
		return t('notenarchiv', 'Tempo in the score: {mark}', { mark: tempoMark(1) })
	}
	if (loaded?.tempoInFile) {
		return t('notenarchiv', 'Editorial tempo (not printed): {mark}', { mark: tempoMark(1) })
	}
	return t('notenarchiv', 'The file gives no tempo; {mark} is assumed.', { mark: tempoMark(1) })
})

function updateTimeline(): void {
	const loaded = model.value
	if (!loaded) {
		return
	}
	stop()
	timeline.value = playbackTimeline(loaded, range.value, repeats.value)
	player?.setTimeline(timeline.value)
}

function gains(): (voice: string) => number {
	return voiceGains(focusVoice.value?.members ?? [], mode.value)
}

function ensurePlayer(): ScorePlayerType {
	if (!player) {
		player = new ScorePlayer({
			onPosition: updatePosition,
			onEnd: () => {
				playing.value = false
				position.value = 0
				resetHighlights()
				announce(t('notenarchiv', 'End of playback.'))
			},
		})
		player.setTimeline(timeline.value)
		player.setRate(tempoPercent.value / 100)
		player.setVolume(volumePercent.value / 100)
		player.setGains(gains())
	}
	return player
}

async function togglePlay(): Promise<void> {
	if (!canPlay.value || status.value !== 'ready') {
		return
	}
	const current = ensurePlayer()
	if (current.isPlaying) {
		current.pause()
		playing.value = false
		announce(t('notenarchiv', 'Paused at measure {number}.', { number: currentMeasureNumber.value }))
		return
	}
	resetHighlights()
	await current.play()
	playing.value = current.isPlaying
	if (playing.value) {
		// The start message comes first; measures are announced 3 s later at the earliest
		lastMeasureAnnouncement = Date.now()
		updatePosition(current.currentTime())
		announce(t('notenarchiv', 'Playing from measure {number}.', { number: currentMeasureNumber.value }))
	}
}

function stop(): void {
	if (!player) {
		return
	}
	const wasActive = player.isPlaying || player.currentTime() > 0
	player.stop()
	playing.value = false
	position.value = 0
	resetHighlights()
	if (wasActive) {
		announce(t('notenarchiv', 'Stopped.'))
	}
}

watch([focusKey, mode], () => {
	applyFocus()
	player?.setGains(gains())
})
watch(tempoPercent, (value) => player?.setRate(value / 100))
watch(volumePercent, (value) => player?.setVolume(value / 100))
watch(repeats, () => updateTimeline())

// ---- practice audio files ------------------------------------------------------

const practiceOpen = ref(false)
const practiceButton = ref(null)
/** Practice audio files (module "practice_audio"); the server checks every export. */
const practiceAudioOn = computed(() => isModuleActive('practice_audio'))
const canExportAudio = computed(() => practiceAudioOn.value && score.value?.canExportAudio === true)
// Why the button is missing although playback is allowed (translated by the server);
// nothing when the function is switched off
const exportReason = computed(() => (practiceAudioOn.value && score.value?.exportReason ? translateLabel(score.value.exportReason) : ''))
const tempoValue = computed(() => t('notenarchiv', '{percent} % ({mark})', { percent: tempoPercent.value, mark: tempoMark(tempoPercent.value / 100) }))

function openPractice(): void {
	if (player?.isPlaying) {
		togglePlay()
	}
	practiceOpen.value = true
}

function closePractice(): void {
	practiceOpen.value = false
	focusAfterDialog(practiceButton)
}

// ---- following the playback -------------------------------------------------

let active: { id: string, end: number }[] = []
let nextHighlight = 0
let currentSlot = -1
let measureMarker: SVGRectElement | null = null
const currentMeasure = ref(-1)

const currentMeasureNumber = computed(() => {
	const loaded = model.value
	const index = currentMeasure.value >= 0 ? currentMeasure.value : (timeline.value.measures[0]?.index ?? 0)
	return loaded?.measures[index]?.number ?? ''
})

function resetHighlights(): void {
	for (const element of pagesElement.value?.querySelectorAll('.score-playing') ?? []) {
		element.classList.remove('score-playing')
	}
	active = []
	nextHighlight = 0
	currentSlot = -1
	currentMeasure.value = -1
	measureMarker?.remove()
	measureMarker = null
}

/**
 * Highlight what sounds at a time, mark the measure and follow it.
 *
 * @param time - position in seconds of the timeline
 */
function updatePosition(time: number): void {
	position.value = time
	const events = timeline.value.events
	while (nextHighlight < events.length && events[nextHighlight].start <= time) {
		const event = events[nextHighlight++]
		if (event.end > time) {
			noteElements.get(event.id)?.classList.add('score-playing')
			active.push({ id: event.id, end: event.end })
		}
	}
	active = active.filter((item) => {
		if (item.end > time) {
			return true
		}
		if (!active.some((other) => other !== item && other.id === item.id && other.end > time)) {
			noteElements.get(item.id)?.classList.remove('score-playing')
		}
		return false
	})

	const slots = timeline.value.measures
	let slot = currentSlot >= 0 && currentSlot < slots.length && slots[currentSlot].start <= time ? currentSlot : 0
	while (slot + 1 < slots.length && slots[slot + 1].start <= time) {
		slot++
	}
	if (slot !== currentSlot && slots[slot]) {
		currentSlot = slot
		onMeasureChange(slots[slot].index)
	}
}

/**
 * The playback reached another measure.
 *
 * @param index - measure index
 */
function onMeasureChange(index: number): void {
	const loaded = model.value
	const measure = loaded?.measures[index]
	if (!loaded || !measure) {
		return
	}
	const previous = currentMeasure.value
	currentMeasure.value = index
	const element = measureElements.get(measure.id)
	if (element) {
		markMeasure(element)
		if (follow.value) {
			scrollToMeasure(element)
		}
	}
	// Announce counted measures only, and not more often than every 3 s
	if (!measure.implicit && previous !== index) {
		announceMeasure(measure.number)
	}
}

function markMeasure(element: SVGGraphicsElement): void {
	try {
		const box = element.getBBox()
		if (!measureMarker) {
			measureMarker = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
			measureMarker.setAttribute('class', 'score-measure-marker')
		}
		const margin = 60
		measureMarker.setAttribute('x', String(box.x - margin))
		measureMarker.setAttribute('y', String(box.y - margin))
		measureMarker.setAttribute('width', String(box.width + 2 * margin))
		measureMarker.setAttribute('height', String(box.height + 2 * margin))
		element.insertBefore(measureMarker, element.firstChild)
	} catch {
		// getBBox fails for elements that are not rendered
	}
}

function scrollToMeasure(element: Element): void {
	const container = scoreElement.value
	if (!container) {
		return
	}
	const box = element.getBoundingClientRect()
	const view = container.getBoundingClientRect()
	const margin = Math.min(80, view.height / 5)
	if (box.top >= view.top + margin && box.bottom <= view.bottom - margin) {
		return
	}
	const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
	container.scrollTo({
		top: container.scrollTop + box.top - view.top - Math.max(margin, (view.height - box.height) / 4),
		behavior: reduceMotion ? 'auto' : 'smooth',
	})
}

// ---- announcements for screen readers --------------------------------------

const announcement = ref('')
let lastMeasureAnnouncement = 0

/**
 * Say something in the live region.
 *
 * @param text - message
 */
function announce(text: string): void {
	// Changing the text is what makes screen readers read it
	announcement.value = announcement.value === text ? text + ' ' : text
}

/**
 * Announce the current measure, throttled.
 *
 * @param number - printed measure number
 */
function announceMeasure(number: string): void {
	const now = Date.now()
	if (now - lastMeasureAnnouncement < 3000) {
		return
	}
	lastMeasureAnnouncement = now
	announce(t('notenarchiv', 'Measure {number}', { number }))
}

// ---- text alternative -------------------------------------------------------

const keyText = computed(() => {
	const signatures = model.value?.signatures
	if (!signatures || signatures.fifths === null) {
		return ''
	}
	const fifths = signatures.fifths
	let text = fifths === 0
		? t('notenarchiv', 'no sharps or flats')
		: (fifths > 0 ? n('notenarchiv', '%n sharp', '%n sharps', fifths) : n('notenarchiv', '%n flat', '%n flats', -fifths))
	if (signatures.mode === 'major') {
		text += ', ' + t('notenarchiv', 'major')
	} else if (signatures.mode === 'minor') {
		text += ', ' + t('notenarchiv', 'minor')
	}
	return t('notenarchiv', 'Key signature: {key}', { key: text })
})

const voiceList = computed(() => (model.value?.voices ?? []).map(labelOf).join(', '))

const summary = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	const parts = [rangeText.value, n('notenarchiv', '%n voice', '%n voices', loaded.voices.length)]
	parts.push(focusVoice.value ? t('notenarchiv', 'Focus voice: {voice}', { voice: labelOf(focusVoice.value) }) : t('notenarchiv', 'No focus voice'))
	return parts.join(' · ')
})

const description = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	const sentences = [
		t('notenarchiv', 'Score of {shelfmark} {title}', { shelfmark: shelfmark.value, title: title.value }),
		rangeText.value,
		n('notenarchiv', '%n voice: {voices}', '%n voices: {voices}', loaded.voices.length, { voices: voiceList.value }),
		focusVoice.value
			? t('notenarchiv', 'The focus voice {voice} is shown in color with bold note heads and stems, the other voices in a lighter gray.', { voice: labelOf(focusVoice.value) })
			: t('notenarchiv', 'No focus voice'),
	]
	if (keyText.value !== '') {
		sentences.push(keyText.value)
	}
	if (loaded.signatures.time !== '') {
		sentences.push(t('notenarchiv', 'Time signature: {time}', { time: loaded.signatures.time }))
	}
	return sentences.join('. ') + '.'
})

// ---- keyboard ---------------------------------------------------------------

const INTERACTIVE = 'input, textarea, select, button, a, summary, [role="button"], [role="combobox"], [role="option"], [role="radio"], [role="checkbox"], [role="switch"], [contenteditable="true"]'

/**
 * Space plays and pauses unless a control has the focus; + and − zoom
 * while the score has the focus.
 *
 * @param event - key event inside the viewer
 */
function onKeydown(event: KeyboardEvent): void {
	const target = event.target instanceof HTMLElement ? event.target : null
	if (target?.closest(INTERACTIVE)) {
		return
	}
	if (event.key === ' ' && canPlay.value && status.value === 'ready') {
		event.preventDefault()
		togglePlay()
	} else if (target === scoreElement.value && (event.key === '+' || event.key === '-')) {
		event.preventDefault()
		zoomBy(event.key === '+' ? 1 : -1)
	}
}

// ---- lifecycle --------------------------------------------------------------

onMounted(load)

// Lay out again when the width changes (window, phone rotation)
watch(scoreElement, (element) => {
	resizeObserver?.disconnect()
	resizeObserver = null
	if (element && typeof ResizeObserver !== 'undefined') {
		resizeObserver = new ResizeObserver(onResize)
		resizeObserver.observe(element)
	}
})

onBeforeUnmount(() => {
	disposed = true
	resizeObserver?.disconnect()
	if (resizeTimer !== null) {
		clearTimeout(resizeTimer)
	}
	player?.destroy()
	player = null
	toolkit?.destroy()
	toolkit = null
})
</script>

<template>
	<NcModal size="full"
		:label-id="headingId"
		@close="emit('close')">
		<!-- Key handling for the whole viewer: Space plays/pauses (see onKeydown) -->
		<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -->
		<div class="score-viewer" @keydown="onKeydown">
			<!-- not a <header>: outside <main> that would be a second banner landmark -->
			<div class="score-viewer__header">
				<h2 :id="headingId" class="score-viewer__title">
					{{ heading || t('notenarchiv', 'Score') }}
				</h2>
				<p v-if="composer || score" class="score-viewer__subtitle">
					<span v-if="composer">{{ composer }}</span>
					<span v-if="score" class="score-viewer__file">{{ t('notenarchiv', 'File: {name}', { name: score.fileName }) }}</span>
				</p>
				<RightsBadge :rights-status="detail.version.rights_status"
					:rights-confirmed="detail.version.confirmed_at || detail.version.rights_confirmed"
					:preservation-copy="detail.version.preservation_copy" />
				<NcNoteCard v-if="start?.notice"
					:type="start.noticeType ?? 'info'"
					class="score-viewer__notice">
					{{ start.notice }}
				</NcNoteCard>
				<details v-if="review !== ''" class="score-viewer__review">
					<summary>{{ reviewLabel }}</summary>
					<p class="score-viewer__review-text">
						{{ review }}
					</p>
				</details>
			</div>

			<NcEmptyContent v-if="status === 'loading'" :name="loadingText">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="status === 'error'"
				:name="t('notenarchiv', 'The score cannot be shown')"
				:description="errorText">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<div class="score-viewer__controls">
					<section v-if="canPlay"
						class="score-viewer__group score-viewer__group--playback"
						:aria-label="t('notenarchiv', 'Playback')">
						<div class="score-viewer__buttons">
							<NcButton variant="primary" @click="togglePlay">
								<template #icon>
									<NcIconSvgWrapper :path="playing ? mdiPause : mdiPlay" />
								</template>
								{{ playing ? t('notenarchiv', 'Pause') : t('notenarchiv', 'Play') }}
							</NcButton>
							<NcButton :disabled="!playing && position === 0" @click="stop">
								<template #icon>
									<NcIconSvgWrapper :path="mdiStop" />
								</template>
								{{ t('notenarchiv', 'Stop') }}
							</NcButton>
							<NcButton v-if="canExportAudio"
								ref="practiceButton"
								aria-haspopup="dialog"
								@click="openPractice">
								<template #icon>
									<NcIconSvgWrapper :path="mdiFileMusicOutline" />
								</template>
								{{ t('notenarchiv', 'Practice files …') }}
							</NcButton>
						</div>
						<NcRadioGroup v-model="mode" :label="t('notenarchiv', 'Voices during playback')">
							<NcRadioGroupButton value="all" :label="t('notenarchiv', 'All equal')" />
							<NcRadioGroupButton value="focus"
								:label="t('notenarchiv', 'Focus voice louder')"
								:disabled="!focusVoice" />
							<NcRadioGroupButton value="solo"
								:label="t('notenarchiv', 'Focus voice only')"
								:disabled="!focusVoice" />
						</NcRadioGroup>
						<label class="score-viewer__slider" :for="tempoId">
							<span>{{ tempoLabel }}</span>
							<input :id="tempoId"
								v-model.number="tempoPercent"
								type="range"
								min="25"
								max="200"
								step="5"
								:aria-describedby="tempoHintId"
								:aria-valuetext="t('notenarchiv', '{percent} % of the tempo', { percent: tempoPercent })">
							<span :id="tempoHintId" class="score-viewer__hint">{{ tempoHint }}</span>
						</label>
						<label class="score-viewer__slider" :for="volumeId">
							<span>{{ t('notenarchiv', 'Volume: {percent} %', { percent: volumePercent }) }}</span>
							<input :id="volumeId"
								v-model.number="volumePercent"
								type="range"
								min="0"
								max="100"
								step="5"
								:aria-valuetext="t('notenarchiv', '{percent} % volume', { percent: volumePercent })">
						</label>
						<div class="score-viewer__switches">
							<NcCheckboxRadioSwitch v-model="repeats" type="switch">
								{{ t('notenarchiv', 'Play repeats') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch v-model="follow" type="switch">
								{{ t('notenarchiv', 'Scroll along') }}
							</NcCheckboxRadioSwitch>
						</div>
						<p v-if="!canExportAudio && exportReason" class="score-viewer__hint">
							{{ exportReason }}
						</p>
					</section>
					<NcNoteCard v-else-if="playbackOn" type="info" class="score-viewer__no-playback">
						{{ playReason }}
					</NcNoteCard>

					<section class="score-viewer__group" :aria-label="t('notenarchiv', 'Measures and voice')">
						<form class="score-viewer__range" @submit.prevent="applyRange">
							<NcTextField v-model="rangeFrom"
								class="score-viewer__measure-field"
								:label="t('notenarchiv', 'From measure')"
								:error="rangeError !== ''" />
							<NcTextField v-model="rangeTo"
								class="score-viewer__measure-field"
								:label="t('notenarchiv', 'To measure')"
								:error="rangeError !== ''" />
							<NcButton type="submit">
								{{ t('notenarchiv', 'Show') }}
							</NcButton>
							<NcButton v-if="range" variant="tertiary" @click="showAllMeasures">
								{{ t('notenarchiv', 'All measures') }}
							</NcButton>
						</form>
						<p v-if="rangeError" class="score-viewer__error" role="alert">
							{{ rangeError }}
						</p>
						<p class="score-viewer__hint">
							{{ rangeHint }}
						</p>
						<NcSelect v-model="focusOption"
							class="score-viewer__voice"
							:input-label="t('notenarchiv', 'Focus voice')"
							:options="voiceOptions"
							:clearable="false"
							:searchable="false" />
						<div class="score-viewer__zoom" role="group" :aria-label="t('notenarchiv', 'Size of the score')">
							<NcButton variant="tertiary"
								:aria-label="t('notenarchiv', 'Smaller')"
								:disabled="zoomIndex === 0"
								@click="zoomBy(-1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiMagnifyMinusOutline" />
								</template>
							</NcButton>
							<span class="score-viewer__zoom-value">{{ zoomPercent }} %</span>
							<NcButton variant="tertiary"
								:aria-label="t('notenarchiv', 'Larger')"
								:disabled="zoomIndex === ZOOM_STEPS.length - 1"
								@click="zoomBy(1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiMagnifyPlusOutline" />
								</template>
							</NcButton>
						</div>
					</section>
				</div>

				<section class="score-viewer__share" :aria-label="t('notenarchiv', 'Share and ask')">
					<NcButton variant="secondary" @click="copyReference">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentCopy" />
						</template>
						{{ t('notenarchiv', 'Copy text reference') }}
					</NcButton>
					<NcButton v-if="canCreateLinks" variant="secondary" @click="linkDialogOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiLinkVariantPlus" />
						</template>
						{{ t('notenarchiv', 'Create internal link') }}
					</NcButton>
					<NcButton v-if="canCreateTasks" variant="secondary" @click="taskDialogOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClipboardTextOutline" />
						</template>
						{{ t('notenarchiv', 'New task for this place') }}
					</NcButton>
					<p class="score-viewer__hint score-viewer__reference">
						{{ reference }}
					</p>
				</section>

				<p :id="summaryId" class="score-viewer__summary">
					{{ summary }}
				</p>
				<!-- Scrollable score; focusable so it can be scrolled with the keyboard -->
				<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -->
				<div ref="scoreElement"
					class="score-viewer__score"
					tabindex="0"
					role="region"
					:aria-label="t('notenarchiv', 'Score')"
					:aria-describedby="summaryId"
					@wheel="onWheel">
					<div ref="pagesElement"
						class="score-viewer__pages"
						role="img"
						:aria-label="description">
						<!-- SVG rendered by Verovio in the browser from the MusicXML file and cleaned by sanitizeSvg() -->
						<!-- eslint-disable vue/no-v-html -->
						<div v-for="(svg, index) in pages"
							:key="index"
							class="score-viewer__page"
							v-html="svg" />
						<!-- eslint-enable vue/no-v-html -->
					</div>
				</div>
				<p class="hidden-visually" aria-live="polite">
					{{ announcement }}
				</p>
				<TaskDialog v-if="taskDialogOpen"
					:copy-id="detail.copy.id"
					:shelfmark="shelfmark"
					:prefill="placePrefill"
					@created="onTaskCreated"
					@close="taskDialogOpen = false" />
				<LinkDialog v-if="linkDialogOpen"
					:copy-id="detail.copy.id"
					:shelfmark="shelfmark"
					:prefill="placePrefill"
					@created="emit('changed')"
					@close="linkDialogOpen = false" />
				<PracticeAudioDialog v-if="practiceOpen && model"
					:detail="detail"
					:model="model"
					:range="range"
					:repeats="repeats"
					:tempo-percent="tempoPercent"
					:tempo-text="tempoValue"
					:focus-key="focusKey"
					:mode="mode"
					:voice-name="labelOf"
					@close="closePractice" />
			</template>
		</div>
	</NcModal>
</template>

<style scoped>
.score-viewer {
	/* a quarter of the text color: darker in a light theme, lighter in a dark
	   one, so that the focus voice also differs in lightness from the others */
	--score-focus: color-mix(in srgb, var(--color-info-text, #00679e) 75%, var(--color-main-text, #222));
	/* the other voices while a focus voice is chosen: lighter, but like all
	   secondary text at least 4.5:1 against the background in every theme */
	--score-muted: var(--color-text-maxcontrast, #6b6b6b);
	--score-playing: var(--color-error-text, #c9302c);
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	box-sizing: border-box;
	height: 100%;
	padding: calc(3 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	overflow: auto;
}

.score-viewer__header {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	/* room for the close button of the modal */
	padding-inline-end: calc(var(--default-clickable-area) + var(--default-grid-baseline));
}

.score-viewer__title {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.score-viewer__subtitle {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(3 * var(--default-grid-baseline));
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.score-viewer__file {
	overflow-wrap: anywhere;
}

.score-viewer__review summary {
	cursor: pointer;
	font-weight: bold;
}

.score-viewer__review summary:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
	border-radius: var(--border-radius-small, 4px);
}

.score-viewer__review-text {
	max-width: 80ch;
	margin: var(--default-grid-baseline) 0 0;
	white-space: pre-wrap;
}

.score-viewer__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
}

.score-viewer__group {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	min-width: 0;
}

.score-viewer__group--playback {
	flex: 1 1 30em;
}

.score-viewer__buttons,
.score-viewer__range,
.score-viewer__switches,
.score-viewer__zoom {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.score-viewer__measure-field {
	width: 9em;
}

.score-viewer__voice {
	min-width: 14em;
}

.score-viewer__slider {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 12em;
}

.score-viewer__slider input[type='range'] {
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	accent-color: var(--color-primary-element);
}

.score-viewer__slider input[type='range']:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.score-viewer__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.score-viewer__group .score-viewer__hint,
.score-viewer__error {
	flex-basis: 100%;
	margin: 0;
}

.score-viewer__error {
	color: var(--color-error-text, var(--color-error));
}

.score-viewer__zoom-value {
	min-width: 4em;
	align-self: center;
	text-align: center;
}

.score-viewer__share {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.score-viewer__reference {
	flex-basis: 100%;
	margin: 0;
	overflow-wrap: anywhere;
}

.score-viewer__notice {
	align-self: stretch;
}

.score-viewer__summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.score-viewer__score {
	flex: 1 0 min(60vh, 30em);
	min-height: 16em;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.score-viewer__score:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.score-viewer__page :deep(svg) {
	display: block;
	max-width: none;
	margin: 0 auto;
}

/* Ink follows the theme (Verovio writes color="black") */
.score-viewer__page :deep(svg.definition-scale) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* Focus voice: color plus bolder note heads and stems, and the other voices
   lighter, so that it does not stand out by its hue alone (WCAG 1.4.1) */
.score-viewer__page :deep(g.layer.score-focus) {
	color: var(--score-focus);
	fill: var(--score-focus);
}

.score-viewer__page :deep(g.layer.score-focus g.notehead) {
	stroke: currentColor;
	stroke-width: 36px;
}

.score-viewer__page :deep(g.layer.score-focus g.stem path) {
	stroke-width: 30px;
}

.score-viewer__page :deep(.score-muted) {
	color: var(--score-muted);
	fill: var(--score-muted);
}

/* Lyrics on the staff of the focus voice stay in the normal ink (emphasis.ts) */
.score-viewer__page :deep(.score-muted .score-shared) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* Sounding notes: color, outline and larger note heads */
.score-viewer__page :deep(g.note.score-playing) {
	color: var(--score-playing);
	fill: var(--score-playing);
}

.score-viewer__page :deep(g.note.score-playing g.notehead) {
	stroke: currentColor;
	stroke-width: 50px;
	transform: scale(1.3);
	transform-box: fill-box;
	transform-origin: center;
}

/* Current measure while playing */
.score-viewer__page :deep(rect.score-measure-marker) {
	fill: var(--color-primary-element-light, rgba(0, 103, 158, 0.12));
	stroke: var(--color-primary-element, #00679e);
	stroke-width: 18px;
	stroke-dasharray: 60 40;
	rx: 40px;
}

@media (max-width: 512px) {
	.score-viewer {
		padding: calc(2 * var(--default-grid-baseline));
	}

	.score-viewer__slider,
	.score-viewer__voice {
		min-width: 100%;
	}
}
</style>
