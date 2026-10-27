<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The slim score view of a shared piece (milestone 0.7): for choir members
 * on the page "Shared with me" and for public links. The server sends the
 * measures of the link already cut, or the whole piece with its full
 * playing order for a link without measures; all pages of it are shown, as
 * in the score view of the archive. The voice of the link is the focus
 * voice; it can be played louder or alone. Choir members may also make a
 * practice file, when the parent passes `exportAudio` (the server checks
 * and records each one).
 *
 * Built from the same modules as the score view of the archive (src/score);
 * the text reference and the text description are the alternatives to the
 * notation (WCAG 1.1.1). As there: a count-in, showing and looping a range
 * of measures (From measure, To measure) and starting at a clicked note, or
 * with the arrow keys.
 */
import type { CountInBars } from '../score/countIn.ts'
import type { MemberPracticeAudioInput } from '../types.ts'
import type { PlayMode, ScorePlayer as ScorePlayerType } from '../score/player.ts'
import type { PracticePart } from '../score/practice.ts'
import type { MeasureRange } from '../score/range.ts'
import type { ScoreModel } from '../score/model.ts'
import type { Timeline } from '../score/timeline.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'

import { mdiAlertCircleOutline, mdiChevronUp, mdiDownload, mdiMagnifyMinusOutline, mdiMagnifyPlusOutline, mdiPause, mdiPlay, mdiStop, mdiTuneVariant } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { countInFor, slotAt, storeCountIn, storedCountIn } from '../score/countIn.ts'
import { markFocus } from '../score/emphasis.ts'
import { followMeasure } from '../score/follow.ts'
import { scoreTarget } from '../score/hit.ts'
import { loadScore, playbackTimeline, renderPages, selectMeasures } from '../score/model.ts'
import { ScorePlayer, voiceGains } from '../score/player.ts'
import { partGains, practiceFileName, practiceMidi, SAMPLE_RATE } from '../score/practice.ts'
import { RenderCancelled, renderTimeline } from '../score/render.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { measureStarts, nextPass, noteStarts } from '../score/timeline.ts'
import { createToolkit } from '../score/toolkit.ts'
import { voiceLabel } from '../score/voices.ts'
import { encodeWav } from '../score/wav.ts'
import { nextZoom, storedZoom, storeZoom, ZOOM_DEFAULT, ZOOM_STEPS } from '../score/zoom.ts'
import { resolveRange } from '../score/range.ts'
import { excerptTempo, TEMPO_MAX, TEMPO_MIN, voiceForServer, voiceOfLink } from './excerpt.ts'
import { scopeText } from './linkScope.ts'

const props = withDefaults(defineProps<{
	/** MusicXML of the excerpt, already cut by the server, or of the whole piece */
	xml: string
	title: string
	composer?: string
	/** measures of the link; null for the whole piece */
	measureFrom: string | null
	measureTo: string | null
	/** voice of the link: the first focus voice */
	voice?: string | null
	verse?: number | null
	/** tempo of the link in percent */
	tempo?: number | null
	canPlay: boolean
	/** translated reason why the excerpt cannot be played */
	playReason?: string | null
	/** asks the server before a practice file is made; without it there is no practice file */
	exportAudio?: ((input: MemberPracticeAudioInput) => Promise<void>) | null
}>(), {
	composer: '',
	voice: null,
	verse: null,
	tempo: null,
	playReason: null,
	exportAudio: null,
})

const summaryId = useId()
const tempoId = useId()
const tempoHintId = useId()
const exportStatusId = useId()
const scoreHintId = useId()
const settingsId = useId()

/**
 * The settings below the bar: shown on a large screen, folded on a phone,
 * so that the score starts high up (the bar with play and size stays).
 */
const settingsShown = ref(window.matchMedia?.('(min-width: 1024px)').matches ?? true)

// ---- loading ----------------------------------------------------------------

const status = ref<'loading' | 'ready' | 'error'>('loading')
const errorText = ref('')
const model = shallowRef<ScoreModel | null>(null)
const pages = shallowRef<string[]>([])
const scoreElement = ref<HTMLElement | null>(null)
const pagesElement = ref<HTMLElement | null>(null)
const barElement = ref<HTMLElement | null>(null)

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
	status.value = 'loading'
	errorText.value = ''
	try {
		const created = toolkit ?? await createToolkit()
		if (disposed) {
			created.destroy()
			return
		}
		toolkit = created
		await nextFrame()
		const loaded = loadScore(created, props.xml)
		model.value = loaded
		range.value = null
		rangeFrom.value = numberSpan.value.first
		rangeTo.value = numberSpan.value.last
		focusKey.value = voiceOfLink(loaded.voices, props.voice, labelOf)?.key ?? ''
		mode.value = focusKey.value !== '' ? 'focus' : 'all'
		status.value = 'ready'
		await nextTick()
		layout()
		updateTimeline()
	} catch (error) {
		console.error('Excerpt view: the score could not be shown', error)
		status.value = 'error'
		errorText.value = t('notenarchiv', 'The excerpt could not be shown in this browser. Please try another browser; the text reference above names the measures.')
	}
}

// ---- layout and rendering ---------------------------------------------------

const zoomIndex = ref(storedZoom())
const zoomPercent = computed(() => Math.round(ZOOM_STEPS[zoomIndex.value] / ZOOM_STEPS[ZOOM_DEFAULT] * 100))

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
	})
}

// ---- measures shown and played ------------------------------------------------

const rangeFrom = ref('')
const rangeTo = ref('')
/** The shown measures; null for all measures that were sent */
const range = shallowRef<MeasureRange | null>(null)
const rangeError = ref('')

const numberSpan = computed(() => {
	const measures = model.value?.measures ?? []
	return { first: measures[0]?.number ?? '', last: measures[measures.length - 1]?.number ?? '' }
})

/** Printed numbers of the shown measures */
const shownNumbers = computed(() => {
	const measures = model.value?.measures ?? []
	const shown = range.value
	return shown === null
		? numberSpan.value
		: { first: measures[shown.start]?.number ?? '', last: measures[shown.end]?.number ?? '' }
})

/** Show (and play, and loop) only the measures of the two fields, as in the score view. */
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
	range.value = result.start === 0 && result.end === loaded.measures.length - 1 ? null : result
	layout()
	updateTimeline()
	announce(t('notenarchiv', 'Measures {from} to {to}', { from: shownNumbers.value.first, to: shownNumbers.value.last }))
}

function showAllMeasures(): void {
	rangeFrom.value = numberSpan.value.first
	rangeTo.value = numberSpan.value.last
	rangeError.value = ''
	range.value = null
	layout()
	updateTimeline()
}

/**
 * @param step - +1 bigger, -1 smaller
 */
function zoomBy(step: number): void {
	const next = nextZoom(zoomIndex.value, step)
	if (next !== zoomIndex.value) {
		zoomIndex.value = next
		storeZoom(next)
		layout()
	}
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

const noteElements = new Map<string, Element>()
const measureElements = new Map<string, SVGGraphicsElement>()

function indexElements(): void {
	noteElements.clear()
	measureElements.clear()
	for (const element of pagesElement.value?.querySelectorAll('g.note[data-id]') ?? []) {
		noteElements.set(element.getAttribute('data-id') ?? '', element)
	}
	for (const element of pagesElement.value?.querySelectorAll<SVGGraphicsElement>('g.measure[data-id]') ?? []) {
		measureElements.set(element.getAttribute('data-id') ?? '', element)
	}
}

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

function applyFocus(): void {
	if (pagesElement.value) {
		markFocus(pagesElement.value, focusVoice.value?.members ?? [])
	}
}

// ---- playback ---------------------------------------------------------------

const playing = ref(false)
const mode = ref<PlayMode>('all')
const repeats = ref(true)
/** Play the shown measures again and again */
const loop = ref(false)
/** Bars of count-in, remembered in this browser (as in the score view) */
const countInBars = ref<CountInBars>(storedCountIn())
const countInChoice = computed<'0' | '1' | '2'>({
	get: () => String(countInBars.value) as '0' | '1' | '2',
	set: (value) => {
		countInBars.value = Number(value) as CountInBars
	},
})
const tempoPercent = ref(excerptTempo(props.tempo))
const position = ref(0)
const timeline = shallowRef<Timeline>({ events: [], measures: [], duration: 0 })

const tempoText = computed(() => t('notenarchiv', 'Tempo: {percent} %', { percent: tempoPercent.value }))

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
		player.setGains(gains())
		player.setLoop(loop.value)
	}
	return player
}

async function togglePlay(): Promise<void> {
	if (!props.canPlay || status.value !== 'ready') {
		return
	}
	const current = ensurePlayer()
	if (current.isPlaying) {
		current.pause()
		playing.value = false
		announce(t('notenarchiv', 'Paused at measure {number}.', { number: currentMeasureNumber.value }))
		return
	}
	await startPlaying(current)
}

/**
 * Start playing from the player's position, with the count-in.
 *
 * @param current - the player
 */
async function startPlaying(current: ScorePlayerType): Promise<void> {
	resetHighlights()
	const loaded = model.value
	const bars = countInBars.value
	await current.play(loaded && bars > 0 ? (time) => countInFor(loaded, timeline.value, time, bars) : undefined)
	playing.value = current.isPlaying
	if (playing.value) {
		lastMeasureAnnouncement = Date.now()
		updatePosition(current.currentTime())
		const number = currentMeasureNumber.value
		announce(bars > 0
			? t('notenarchiv', 'Count-in, then playing from measure {number}.', { number })
			: t('notenarchiv', 'Playing from measure {number}.', { number }))
	}
}

/**
 * Go to a place of the playback: while playing, it continues there;
 * otherwise the next start begins there.
 *
 * @param time - position in seconds of the timeline
 */
function moveTo(time: number): void {
	const current = ensurePlayer()
	current.seek(time)
	resetHighlights()
	updatePosition(time)
	lastMeasureAnnouncement = Date.now()
	const number = currentMeasureNumber.value
	announce(current.isPlaying
		? t('notenarchiv', 'Playing from measure {number}.', { number })
		: t('notenarchiv', 'Start at measure {number}.', { number }))
}

/**
 * A click or a tap into the score: on a note or a measure (see
 * scoreTarget), the playback starts there (at its next pass from the
 * current position). One handler on the
 * container; the SVG itself carries no handlers.
 *
 * @param event - click event
 */
function onScoreClick(event: MouseEvent): void {
	const target = scoreTarget(event, pagesElement.value)
	const loaded = model.value
	if (!target || !loaded || !props.canPlay || status.value !== 'ready') {
		return
	}
	const after = player?.currentTime() ?? 0
	const note = target.closest('g.note[data-id]')
	let time = note ? nextPass(noteStarts(timeline.value, note.getAttribute('data-id') ?? ''), after) : null
	if (time === null) {
		const measure = target.closest('g.measure[data-id]')
		const index = measure ? loaded.measures.findIndex((candidate) => candidate.id === measure.getAttribute('data-id')) : -1
		time = index === -1 ? null : nextPass(measureStarts(timeline.value, index), after)
	}
	if (time === null) {
		return
	}
	const current = ensurePlayer()
	if (current.isPlaying) {
		moveTo(time)
	} else {
		current.seek(time)
		startPlaying(current)
	}
}

/**
 * The keyboard alternative to clicking a note: arrow left and right in the
 * score go to the previous or next measure in playing order.
 *
 * @param step - -1 back, +1 forward
 */
function stepMeasure(step: number): void {
	const slots = timeline.value.measures
	if (slots.length === 0) {
		return
	}
	const current = Math.max(0, slotAt(slots, player?.currentTime() ?? 0))
	moveTo(slots[Math.min(slots.length - 1, Math.max(0, current + step))].start)
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
watch(focusKey, (key) => {
	if (key === '') {
		mode.value = 'all'
	}
})
watch(tempoPercent, (value) => player?.setRate(value / 100))
watch(repeats, () => updateTimeline())
watch(countInBars, (bars) => storeCountIn(bars))
watch(loop, (on) => {
	// The loop takes the measures of the fields "From measure" and "To measure"
	const shown = shownNumbers.value
	if (on && (rangeFrom.value.trim() !== shown.first || rangeTo.value.trim() !== shown.last)) {
		applyRange()
	}
	player?.setLoop(on)
	announce(on
		? t('notenarchiv', 'Loop on: measures {from} to {to} play again and again.', { from: shownNumbers.value.first, to: shownNumbers.value.last })
		: t('notenarchiv', 'Loop off.'))
})

// ---- following the playback -------------------------------------------------

let active: { id: string, end: number }[] = []
let nextHighlight = 0
let currentSlot = -1
const currentMeasure = ref(-1)

const currentMeasureNumber = computed(() => {
	const index = currentMeasure.value >= 0 ? currentMeasure.value : (timeline.value.measures[0]?.index ?? 0)
	return model.value?.measures[index]?.number ?? ''
})

function resetHighlights(): void {
	for (const element of pagesElement.value?.querySelectorAll('.score-playing') ?? []) {
		element.classList.remove('score-playing')
	}
	active = []
	nextHighlight = 0
	currentSlot = -1
	currentMeasure.value = -1
}

/**
 * Highlight what sounds at a time and follow the measure.
 *
 * @param time - position in seconds of the timeline
 */
function updatePosition(time: number): void {
	if (time < position.value - 0.05) {
		// The loop started again
		resetHighlights()
	}
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
 * @param index - measure index
 */
function onMeasureChange(index: number): void {
	const measure = model.value?.measures[index]
	if (!measure) {
		return
	}
	const previous = currentMeasure.value
	currentMeasure.value = index
	const element = measureElements.get(measure.id)
	if (element) {
		scrollToMeasure(element)
	}
	if (!measure.implicit && previous !== index) {
		announceMeasure(measure.number)
	}
}

function scrollToMeasure(element: Element): void {
	// The score scrolls in its box on a large screen, with the page on a
	// phone; the bar at the top stays and covers part of the view
	if (scoreElement.value) {
		followMeasure(element, scoreElement.value, barElement.value)
	}
}

// ---- announcements for screen readers --------------------------------------

const announcement = ref('')
let lastMeasureAnnouncement = 0

/**
 * @param text - message for the live region
 */
function announce(text: string): void {
	announcement.value = announcement.value === text ? text + ' ' : text
}

/**
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

// ---- text reference and description -----------------------------------------

/** Words of a text reference (the same texts as in the archive). */
const WORDS = {
	page: (page: string) => t('notenarchiv', 'p. {page}', { page }),
	measure: (measure: string) => t('notenarchiv', 'm. {measure}', { measure }),
	measures: (from: string, to: string) => t('notenarchiv', 'm. {from}–{to}', { from, to }),
	verse: (verse: string) => t('notenarchiv', 'verse {verse}', { verse }),
}

/** A link without measures: the whole piece */
const whole = computed(() => props.measureFrom === null || props.measureTo === null)

/** Text reference of the link: title, measures or "Whole piece", voice of the link, verse. */
const reference = computed(() => [props.title, scopeText({
	measureFrom: props.measureFrom,
	measureTo: props.measureTo,
	voice: props.voice,
	verse: props.verse,
}, WORDS, t('notenarchiv', 'Whole piece'))].filter((part) => part.trim() !== '').join(' · '))

/** The shown measures as text */
const rangeText = computed(() => (whole.value && range.value === null
	? t('notenarchiv', 'Whole piece')
	: t('notenarchiv', 'Measures {from} to {to}', { from: shownNumbers.value.first, to: shownNumbers.value.last })))

const rangeHint = computed(() => {
	const parts = [t('notenarchiv', 'Printed measure numbers {first} to {last}.', numberSpan.value)]
	if (model.value?.pickup) {
		parts.push(t('notenarchiv', 'The pickup is measure 0.'))
	}
	return parts.join(' ')
})

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

const summary = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	return [
		rangeText.value,
		n('notenarchiv', '%n voice', '%n voices', loaded.voices.length),
		focusVoice.value ? t('notenarchiv', 'Focus voice: {voice}', { voice: labelOf(focusVoice.value) }) : t('notenarchiv', 'No focus voice'),
	].join(' · ')
})

const description = computed(() => {
	const loaded = model.value
	if (!loaded) {
		return ''
	}
	const sentences = [
		whole.value ? t('notenarchiv', 'Score of {title}', { title: props.title }) : t('notenarchiv', 'Excerpt of {title}', { title: props.title }),
		rangeText.value,
		n('notenarchiv', '%n voice: {voices}', '%n voices: {voices}', loaded.voices.length, { voices: loaded.voices.map(labelOf).join(', ') }),
		focusVoice.value
			? t('notenarchiv', 'The focus voice {voice} is shown in color with bold note heads and stems, the other voices in a lighter gray.', { voice: labelOf(focusVoice.value) })
			: t('notenarchiv', 'No focus voice'),
	]
	if (props.verse) {
		sentences.push(t('notenarchiv', 'Verse {verse}', { verse: props.verse }))
	}
	if (keyText.value !== '') {
		sentences.push(keyText.value)
	}
	if (loaded.signatures.time !== '') {
		sentences.push(t('notenarchiv', 'Time signature: {time}', { time: loaded.signatures.time }))
	}
	return sentences.join('. ') + '.'
})

// ---- practice file (choir members) -------------------------------------------------

const canExport = computed(() => props.exportAudio !== null && props.canPlay)
const format = ref<'wav' | 'midi'>('wav')
const exporting = ref(false)
const exportStatus = ref('')
const exportError = ref('')
const download = shallowRef<{ name: string, url: string } | null>(null)
let exportController: AbortController | null = null

/** One file: the focus voice louder or alone, or all voices equal. */
const exportPart = computed<PracticePart>(() => {
	const voice = focusVoice.value
	return voice && mode.value !== 'all'
		? { voice, mode: mode.value, label: labelOf(voice) }
		: { voice: null, mode: 'all', label: t('notenarchiv', 'Tutti') }
})

const exportChoice = computed(() => {
	const part = exportPart.value
	// the count-in of the playback is at the start of the file, too
	const tempo = countInBars.value > 0
		? t('notenarchiv', '{tempo}, count-in {bars}', { tempo: tempoText.value, bars: countInBars.value === 1 ? t('notenarchiv', '1 bar') : t('notenarchiv', '2 bars') })
		: tempoText.value
	if (part.voice === null) {
		return t('notenarchiv', 'All voices equal, {tempo}', { tempo })
	}
	return part.mode === 'solo'
		? t('notenarchiv', '{voice} alone, {tempo}', { voice: part.label, tempo })
		: t('notenarchiv', '{voice} louder, {tempo}', { voice: part.label, tempo })
})

function clearDownload(): void {
	if (download.value) {
		URL.revokeObjectURL(download.value.url)
		download.value = null
	}
}

/**
 * Offer a made file as a download.
 *
 * @param data - file content
 * @param name - file name
 * @param type - MIME type
 */
function offer(data: Uint8Array, name: string, type: string): void {
	clearDownload()
	const url = URL.createObjectURL(new Blob([data as Uint8Array<ArrayBuffer>], { type }))
	download.value = { name, url }
	const link = document.createElement('a')
	link.href = url
	link.download = name
	link.rel = 'noopener'
	document.body.appendChild(link)
	link.click()
	link.remove()
}

async function makePracticeFile(): Promise<void> {
	const loaded = model.value
	const authorize = props.exportAudio
	if (!loaded || !authorize || exporting.value) {
		return
	}
	if (player?.isPlaying) {
		player.pause()
		playing.value = false
	}
	const part = exportPart.value
	const rate = tempoPercent.value / 100
	const ext = format.value === 'wav' ? 'wav' : 'mid'
	const shown = range.value
	const { first, last } = shownNumbers.value
	const name = practiceFileName({ shelfmark: '', title: props.title, label: part.label, from: first, to: last, ext })
	exporting.value = true
	exportError.value = ''
	exportStatus.value = t('notenarchiv', 'Checking the rights …')
	exportController = new AbortController()
	const signal = exportController.signal
	try {
		await authorize({
			voice: part.voice ? voiceForServer(part.label) : null,
			mode: part.mode,
			format: format.value,
			tempo: tempoPercent.value,
			repeats: repeats.value,
			measure_from: first,
			measure_to: last,
		})
		exportStatus.value = t('notenarchiv', 'Making the file …')
		await nextFrame()
		const events = playbackTimeline(loaded, shown, repeats.value)
		const countIn = countInFor(loaded, events, 0, countInBars.value)
		let data: Uint8Array
		if (format.value === 'midi') {
			data = practiceMidi(loaded, events, part, { rate, title: props.title, labelOf, countIn })
		} else {
			const samples = await renderTimeline(events, partGains(part), {
				rate,
				sampleRate: SAMPLE_RATE,
				signal,
				countIn,
				onProgress: (done) => {
					exportStatus.value = t('notenarchiv', 'Making the file … {percent} %', { percent: Math.floor(done * 10) * 10 })
				},
			})
			data = encodeWav(samples, SAMPLE_RATE, 1)
		}
		offer(data, name, format.value === 'wav' ? 'audio/wav' : 'audio/midi')
		exportStatus.value = t('notenarchiv', 'The practice file {name} is ready.', { name })
	} catch (error) {
		if (error instanceof RenderCancelled || signal.aborted) {
			exportStatus.value = t('notenarchiv', 'Cancelled. No file was made.')
		} else {
			exportStatus.value = ''
			exportError.value = error instanceof RangeError
				? t('notenarchiv', 'The file is too large for this browser. Please try MIDI instead of WAV.')
				: (error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'The file could not be made in this browser.'))
		}
	} finally {
		exporting.value = false
		exportController = null
	}
}

function cancelExport(): void {
	exportController?.abort()
}

// ---- keyboard ---------------------------------------------------------------

const INTERACTIVE = 'input, textarea, select, button, a, summary, [role="button"], [role="combobox"], [role="option"], [role="radio"], [role="checkbox"], [role="switch"], [contenteditable="true"]'

/**
 * Space plays and pauses unless a control has the focus. While the score
 * has the focus, + and − zoom and the arrow keys left and right go to the
 * previous or next measure.
 *
 * @param event - key event inside the view
 */
function onKeydown(event: KeyboardEvent): void {
	const target = event.target instanceof HTMLElement ? event.target : null
	if (target?.closest(INTERACTIVE)) {
		return
	}
	const inScore = target === scoreElement.value
	const plain = !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
	if (event.key === ' ' && props.canPlay && status.value === 'ready') {
		event.preventDefault()
		togglePlay()
	} else if (inScore && (event.key === '+' || event.key === '-')) {
		event.preventDefault()
		zoomBy(event.key === '+' ? 1 : -1)
	} else if (inScore && plain && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && props.canPlay && status.value === 'ready') {
		event.preventDefault()
		stepMeasure(event.key === 'ArrowLeft' ? -1 : 1)
	}
}

// ---- lifecycle --------------------------------------------------------------

onMounted(load)

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
	exportController?.abort()
	clearDownload()
	player?.destroy()
	player = null
	toolkit?.destroy()
	toolkit = null
})
</script>

<template>
	<!-- Key handling for the whole view: Space plays/pauses (see onKeydown) -->
	<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -->
	<div class="excerpt-viewer" @keydown="onKeydown">
		<p class="excerpt-viewer__reference">
			<span class="excerpt-viewer__label">{{ t('notenarchiv', 'Text reference') }}:</span>
			{{ reference }}
		</p>

		<NcEmptyContent v-if="status === 'loading'" :name="t('notenarchiv', 'Engraving the score …')">
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
			<!-- The bar stays at the top while the score scrolls past (position: sticky) -->
			<div ref="barElement" class="excerpt-viewer__bar">
				<div v-if="canPlay"
					class="excerpt-viewer__buttons"
					role="group"
					:aria-label="t('notenarchiv', 'Playback')">
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
				</div>
				<div class="excerpt-viewer__buttons" role="group" :aria-label="t('notenarchiv', 'Size of the score')">
					<NcButton variant="tertiary"
						:aria-label="t('notenarchiv', 'Smaller')"
						:disabled="zoomIndex === 0"
						@click="zoomBy(-1)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMagnifyMinusOutline" />
						</template>
					</NcButton>
					<span class="excerpt-viewer__zoom-value">{{ zoomPercent }} %</span>
					<NcButton variant="tertiary"
						:aria-label="t('notenarchiv', 'Larger')"
						:disabled="zoomIndex === ZOOM_STEPS.length - 1"
						@click="zoomBy(1)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiMagnifyPlusOutline" />
						</template>
					</NcButton>
				</div>
				<NcButton variant="tertiary"
					:aria-expanded="settingsShown ? 'true' : 'false'"
					:aria-controls="settingsId"
					@click="settingsShown = !settingsShown">
					<template #icon>
						<NcIconSvgWrapper :path="settingsShown ? mdiChevronUp : mdiTuneVariant" />
					</template>
					<span class="excerpt-viewer__settings-label">{{ t('notenarchiv', 'Settings') }}</span>
				</NcButton>
			</div>
			<NcNoteCard v-if="!canPlay" type="info" class="excerpt-viewer__note">
				{{ playReason || t('notenarchiv', 'Playback is not available for this excerpt.') }}
			</NcNoteCard>

			<div v-show="settingsShown" :id="settingsId" class="excerpt-viewer__controls">
				<section v-if="canPlay" class="excerpt-viewer__group" :aria-label="t('notenarchiv', 'Playback')">
					<NcRadioGroup v-model="mode" :label="t('notenarchiv', 'Voices during playback')">
						<NcRadioGroupButton value="all" :label="t('notenarchiv', 'All equal')" />
						<NcRadioGroupButton value="focus"
							:label="t('notenarchiv', 'Focus voice louder')"
							:disabled="!focusVoice" />
						<NcRadioGroupButton value="solo"
							:label="t('notenarchiv', 'Focus voice only')"
							:disabled="!focusVoice" />
					</NcRadioGroup>
					<label class="excerpt-viewer__slider" :for="tempoId">
						<span>{{ tempoText }}</span>
						<input :id="tempoId"
							v-model.number="tempoPercent"
							type="range"
							:min="TEMPO_MIN"
							:max="TEMPO_MAX"
							step="5"
							:aria-describedby="tempoHintId"
							:aria-valuetext="t('notenarchiv', '{percent} % of the tempo', { percent: tempoPercent })">
						<span :id="tempoHintId" class="excerpt-viewer__hint">{{ t('notenarchiv', 'Percent of the tempo in the score.') }}</span>
					</label>
					<NcRadioGroup v-model="countInChoice" :label="t('notenarchiv', 'Count-in')">
						<NcRadioGroupButton value="0" :label="t('notenarchiv', 'Off')" />
						<NcRadioGroupButton value="1" :label="t('notenarchiv', '1 bar')" />
						<NcRadioGroupButton value="2" :label="t('notenarchiv', '2 bars')" />
					</NcRadioGroup>
					<div class="excerpt-viewer__switches">
						<NcCheckboxRadioSwitch v-model="repeats" type="switch">
							{{ t('notenarchiv', 'Play repeats') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="loop" type="switch">
							{{ t('notenarchiv', 'Loop') }}
						</NcCheckboxRadioSwitch>
					</div>
				</section>

				<NcSelect v-model="focusOption"
					class="excerpt-viewer__voice"
					:input-label="t('notenarchiv', 'Focus voice')"
					:options="voiceOptions"
					:clearable="false"
					:searchable="false" />

				<section class="excerpt-viewer__group" :aria-label="t('notenarchiv', 'Measures')">
					<form class="excerpt-viewer__buttons" @submit.prevent="applyRange">
						<!-- NcTextField puts a class on its input, not on its box: the width is set here -->
						<div class="excerpt-viewer__measure-field">
							<NcTextField v-model="rangeFrom"
								:label="t('notenarchiv', 'From measure')"
								:error="rangeError !== ''" />
						</div>
						<div class="excerpt-viewer__measure-field">
							<NcTextField v-model="rangeTo"
								:label="t('notenarchiv', 'To measure')"
								:error="rangeError !== ''" />
						</div>
						<NcButton type="submit">
							{{ t('notenarchiv', 'Show') }}
						</NcButton>
						<NcButton v-if="range" variant="tertiary" @click="showAllMeasures">
							{{ t('notenarchiv', 'All measures') }}
						</NcButton>
					</form>
				</section>
			</div>
			<p v-if="rangeError" class="excerpt-viewer__error" role="alert">
				{{ rangeError }}
			</p>
			<p class="excerpt-viewer__hint">
				{{ rangeHint }}
				<template v-if="canPlay">
					{{ t('notenarchiv', '"Loop" plays the shown measures again and again.') }}
				</template>
			</p>

			<p :id="summaryId" class="excerpt-viewer__summary">
				{{ summary }}
				<template v-if="canPlay">
					· {{ t('notenarchiv', 'Click a note to play from there.') }}
				</template>
			</p>
			<p v-if="canPlay" :id="scoreHintId" class="hidden-visually">
				{{ t('notenarchiv', 'Space plays and pauses. The arrow keys left and right go to the previous or next measure.') }}
			</p>
			<!-- Scrollable score; focusable so it can be scrolled with the keyboard.
			     A click on a note plays from there; the keyboard alternative are the
			     arrow keys in the score (onKeydown on the whole view) -->
			<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions, vuejs-accessibility/click-events-have-key-events -->
			<div ref="scoreElement"
				class="excerpt-viewer__score"
				tabindex="0"
				role="region"
				:aria-label="t('notenarchiv', 'Score')"
				:aria-describedby="canPlay ? `${summaryId} ${scoreHintId}` : summaryId"
				@click="onScoreClick">
				<div ref="pagesElement"
					class="excerpt-viewer__pages"
					:class="{ 'excerpt-viewer__pages--playable': canPlay }"
					role="img"
					:aria-label="description">
					<!-- SVG rendered by Verovio in the browser from the MusicXML and cleaned by sanitizeSvg() -->
					<!-- eslint-disable vue/no-v-html -->
					<div v-for="(svg, index) in pages"
						:key="index"
						class="excerpt-viewer__page"
						v-html="svg" />
					<!-- eslint-enable vue/no-v-html -->
				</div>
			</div>
			<details class="excerpt-viewer__description">
				<summary>{{ whole ? t('notenarchiv', 'Description of the piece') : t('notenarchiv', 'Description of the excerpt') }}</summary>
				<p>{{ description }}</p>
			</details>

			<section v-if="canExport" class="excerpt-viewer__export" :aria-label="t('notenarchiv', 'Practice file')">
				<h3 class="excerpt-viewer__export-title">
					{{ t('notenarchiv', 'Practice file') }}
				</h3>
				<p class="excerpt-viewer__hint">
					{{ t('notenarchiv', 'A practice file of the shown measures ({measures}) for your own practising, made in your browser from the playback above: {choice}. Choose the voice and "Focus voice louder" or "Focus voice only" above. Every practice file is recorded in the audit log of the music archive.', { measures: rangeText, choice: exportChoice }) }}
				</p>
				<div class="excerpt-viewer__buttons">
					<NcRadioGroup v-model="format" :label="t('notenarchiv', 'Format')">
						<NcRadioGroupButton value="wav" :label="t('notenarchiv', 'WAV (audio)')" />
						<NcRadioGroupButton value="midi" :label="t('notenarchiv', 'MIDI')" />
					</NcRadioGroup>
					<NcButton :disabled="exporting" :aria-describedby="exportStatusId" @click="makePracticeFile">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<NcIconSvgWrapper v-else :path="mdiDownload" />
						</template>
						{{ t('notenarchiv', 'Make practice file') }}
					</NcButton>
					<NcButton v-if="exporting" variant="tertiary" @click="cancelExport">
						{{ t('notenarchiv', 'Cancel') }}
					</NcButton>
				</div>
				<p :id="exportStatusId" class="excerpt-viewer__hint" role="status">
					{{ exportStatus }}
					<a v-if="download && !exporting" :href="download.url" :download="download.name">{{ t('notenarchiv', 'Download again') }}</a>
				</p>
				<NcNoteCard v-if="exportError" type="error" role="alert">
					{{ exportError }}
				</NcNoteCard>
			</section>

			<p class="hidden-visually" role="status">
				{{ announcement }}
			</p>
		</template>
	</div>
</template>

<style scoped>
.excerpt-viewer {
	/* as in the score view of the archive (ScoreViewer.vue) */
	--score-focus: color-mix(in srgb, var(--color-info-text, #00679e) 75%, var(--color-main-text, #222));
	--score-muted: var(--color-text-maxcontrast, #6b6b6b);
	--score-playing: var(--color-error-text, #c9302c);
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.excerpt-viewer p {
	margin: 0;
}

.excerpt-viewer__reference {
	overflow-wrap: anywhere;
}

.excerpt-viewer__label {
	font-weight: bold;
}

/* Play, size and settings: stays at the top of the scrolling page (or
   box) while the score scrolls past, so that pausing is always at hand */
.excerpt-viewer__bar {
	position: sticky;
	z-index: 1;
	top: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.excerpt-viewer__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
}

.excerpt-viewer__group,
.excerpt-viewer__buttons,
.excerpt-viewer__switches {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

.excerpt-viewer__group {
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
}

.excerpt-viewer__note {
	flex: 1 1 20em;
}

.excerpt-viewer__voice {
	min-width: 14em;
}

.excerpt-viewer__slider {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 12em;
}

.excerpt-viewer__slider input[type='range'] {
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	accent-color: var(--color-primary-element);
}

.excerpt-viewer__slider input[type='range']:focus-visible,
.excerpt-viewer__score:focus-visible,
.excerpt-viewer__description summary:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.excerpt-viewer__hint,
.excerpt-viewer__summary {
	color: var(--color-text-maxcontrast);
}

.excerpt-viewer__measure-field {
	width: 8em;
}

.excerpt-viewer__error {
	color: var(--color-error-text, var(--color-error));
}

.excerpt-viewer__zoom-value {
	min-width: 3em;
	align-self: center;
	text-align: center;
}

/* As tall as the score: a swipe on it moves the page, no box inside the
   page catches the finger. A large screen: a box of its own, see below */
.excerpt-viewer__score {
	box-sizing: border-box;
	min-width: 0;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.excerpt-viewer__description summary {
	/* a target of at least 24 pixels (WCAG 2.5.8) */
	padding-block: var(--default-grid-baseline);
	cursor: pointer;
	font-weight: bold;
}

.excerpt-viewer__description p {
	max-width: 80ch;
	margin-top: var(--default-grid-baseline);
}

.excerpt-viewer__export {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-top: calc(2 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.excerpt-viewer__export-title {
	margin: 0;
	font-size: 1.1em;
}

.excerpt-viewer__page :deep(svg) {
	display: block;
	max-width: none;
	margin: 0 auto;
}

/* Ink follows the theme (Verovio writes color="black") */
.excerpt-viewer__page :deep(svg.definition-scale) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* Focus voice: color plus bolder note heads and stems, the other voices
   lighter, so that it does not stand out by its hue alone (WCAG 1.4.1) */
.excerpt-viewer__page :deep(g.layer.score-focus) {
	color: var(--score-focus);
	fill: var(--score-focus);
}

.excerpt-viewer__page :deep(g.layer.score-focus g.notehead) {
	stroke: currentColor;
	stroke-width: 36px;
}

.excerpt-viewer__page :deep(g.layer.score-focus g.stem path) {
	stroke-width: 30px;
}

.excerpt-viewer__page :deep(.score-muted) {
	color: var(--score-muted);
	fill: var(--score-muted);
}

.excerpt-viewer__page :deep(.score-muted .score-shared) {
	color: var(--color-main-text);
	fill: var(--color-main-text);
}

/* A click on a note plays from there */
.excerpt-viewer__pages--playable :deep(g.note) {
	cursor: pointer;
}

/* Sounding notes: color, outline and larger note heads */
.excerpt-viewer__page :deep(g.note.score-playing) {
	color: var(--score-playing);
	fill: var(--score-playing);
}

.excerpt-viewer__page :deep(g.note.score-playing g.notehead) {
	stroke: currentColor;
	stroke-width: 50px;
	transform: scale(1.3);
	transform-box: fill-box;
	transform-origin: center;
}

@media (min-width: 1024px) and (min-height: 640px) {
	.excerpt-viewer__score {
		height: max(16em, min(65vh, 40em));
	}
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.excerpt-viewer {
		--default-clickable-area: 44px;
	}
}

/* The narrowest phones: the bar in two lines, "Settings" named by its icon
   (the word stays for screen readers) */
@media (max-width: 359px) {
	.excerpt-viewer__settings-label {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
}

@media (max-width: 512px) {
	.excerpt-viewer__slider,
	.excerpt-viewer__voice {
		min-width: 100%;
	}
}
</style>
