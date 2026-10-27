<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Score view: renders the MusicXML of a copy with Verovio, shows a range of
 * measures in printed numbering, highlights a focus voice and plays the
 * score with a small built-in synthesizer. Practice files (WAV/MIDI) of the
 * same choice are made in PracticeAudioDialog.
 *
 * Milestone 0.8: a count-in, a loop over the shown measures and starting the
 * playback at a note or measure (click, or the arrow keys in the score).
 *
 * Milestone 0.6: the review of a transcription (ReviewPanel: review points
 * that set measures and focus voice), the scan beside the score (the
 * browser's own PDF viewer in a frame or a second window) and small
 * corrections in the app (CorrectionDialog, own chunk).
 *
 * This component is loaded lazily (own chunk); Verovio is a further chunk
 * that is only fetched when a score is opened.
 */
import type { CountInBars } from '../score/countIn.ts'
import type { PlayMode, ScorePlayer as ScorePlayerType } from '../score/player.ts'
import type { MeasureRange } from '../score/range.ts'
import type { ScoreModel } from '../score/model.ts'
import type { Timeline } from '../score/timeline.ts'
import type { VerovioToolkit } from '../score/toolkit.ts'
import type { Voice } from '../score/voices.ts'
import type { ScoreRequest } from '../store.ts'
import type { CorrectionResult, Detail, ReviewPoint, VersionReview } from '../types.ts'

import {
	mdiAlertCircleOutline,
	mdiChevronUp,
	mdiDotsHorizontal,
	mdiHelpCircleOutline,
	mdiMagnifyMinusOutline,
	mdiMagnifyPlusOutline,
	mdiPause,
	mdiPlay,
	mdiStop,
	mdiClipboardTextOutline,
	mdiContentCopy,
	mdiFileMusicOutline,
	mdiLinkVariantPlus,
	mdiOpenInNew,
	mdiPencilOutline,
	mdiTextBoxCheckOutline,
	mdiTuneVariant,
} from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
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
import GuideLink from './GuideLink.vue'
import LinkDialog from './LinkDialog.vue'
import ReviewPanel from './ReviewPanel.vue'
import RightsBadge from './RightsBadge.vue'
import TaskDialog from './TaskDialog.vue'
import { adminSettingsUrl, errorMessage, errorReason, fetchFileContent, fetchReview, isPermanentHttpError, REASON_NOT_SHARED, scanUrl } from '../api.ts'
import { translateLabel } from '../format.ts'
import { countInFor, slotAt, storeCountIn, storedCountIn } from '../score/countIn.ts'
import { playbackTimeline, renderPages, selectMeasures, loadScore } from '../score/model.ts'
import { markFocus } from '../score/emphasis.ts'
import { followMeasure } from '../score/follow.ts'
import { scoreTarget } from '../score/hit.ts'
import { ArchiveLimitError, readScoreText } from '../score/mxl.ts'
import { ScorePlayer, voiceGains } from '../score/player.ts'
import { resolveRange, sameNumber } from '../score/range.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { measureStarts, nextPass, noteStarts } from '../score/timeline.ts'
import { createToolkit } from '../score/toolkit.ts'
import { voiceLabel } from '../score/voices.ts'
import { copyText } from '../clipboard.ts'
import { focusAfterDialog } from '../focus.ts'
import { referenceWords } from '../labels.ts'
import { textReference } from '../references.ts'
import { revisionLine, shownRevision } from '../revisionLabels.ts'
import { canonicalVoice, matchVoice, pointPlace, pointRange } from '../review.ts'
import { parsePage, scanFiles, storedScanPage, storeScanPage } from '../scan.ts'
import { SETTINGS_SECTIONS } from '../settings.ts'
import { ADMIN_GUIDE, adminGuideUrl, USER_GUIDE, userGuideUrl } from '../docs.ts'
import { canChangeSettings, canCreateLinks, canCreateTasks, canShareOutside, fieldInfo, fieldLabel, hasAnyRole, isModuleActive } from '../store.ts'
import { showError } from '../toasts.ts'

/** Practice audio files: own chunk, loaded when the dialog is opened */
const PracticeAudioDialog = defineAsyncComponent(() => import('./PracticeAudioDialog.vue'))
/** Small corrections: own chunk, loaded when the dialog is opened */
const CorrectionDialog = defineAsyncComponent(() => import('./CorrectionDialog.vue'))

/** Most measures the correction form shows at once. */
const MAX_CORRECTION_MEASURES = 8

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
const scanSelectId = useId()
const settingsId = useId()
const placeId = useId()
const scoreHintId = useId()

const score = computed(() => props.detail.score)
/** Playback (module "playback"); the server also refuses it when switched off. */
const playbackOn = computed(() => isModuleActive('playback'))
const canPlay = computed(() => playbackOn.value && score.value?.canPlay === true)

// ---- header -----------------------------------------------------------------

const shelfmark = computed(() => String(props.detail.copy.shelfmark ?? ''))
const title = computed(() => String(props.detail.work.title ?? ''))
const composer = computed(() => String(props.detail.work.composer ?? ''))
const heading = computed(() => [shelfmark.value, title.value].filter((part) => part !== '').join(' · '))
const musicxmlReview = computed(() => String(props.detail.version.musicxml_review ?? '').trim())
/** Revision of the file shown (module "version_states"), with "valid since" when it is the current one */
const revision = computed(() => shownRevision(props.detail.revision))
/** The version line: shelf mark · version · revision · valid since */
const versionLineText = computed(() => (revision.value
	? revisionLine(shelfmark.value, props.detail.version, revision.value.number, revision.value.validSince)
	: ''))
const reviewLabel = computed(() => fieldInfo('version', 'musicxml_review') ? fieldLabel('version', 'musicxml_review') : t('notenarchiv', 'MusicXML review'))

// ---- loading ----------------------------------------------------------------

const status = ref<'loading' | 'ready' | 'error'>('loading')
const loadingText = ref('')
const errorText = ref('')
/** The archive folder is not shared with the account (the server says so) */
const notShared = ref(false)
/** The access check of the settings, for those who may change them */
const accessCheckUrl = adminSettingsUrl(SETTINGS_SECTIONS.access)
/** False when trying again cannot help (no access to the file, file gone). */
const retryable = ref(true)
const model = shallowRef<ScoreModel | null>(null)
/** MusicXML text of the score shown, for the correction form */
const scoreText = ref('')
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

/**
 * Load the score of the copy.
 *
 * @param keep - keep range and focus voice (the score was replaced, e.g. by a correction)
 */
async function load(keep = false): Promise<void> {
	const info = score.value
	if (!info) {
		return
	}
	const kept = keep ? { from: rangeFrom.value, to: rangeTo.value, ranged: range.value !== null, focus: focusKey.value } : null
	status.value = 'loading'
	errorText.value = ''
	notShared.value = false
	retryable.value = true
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
		notShared.value = errorReason(error) === REASON_NOT_SHARED
		errorText.value = error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'The score could not be loaded.')
		retryable.value = !isPermanentHttpError(error)
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
		scoreText.value = text
		model.value = loaded
		rangeFrom.value = loaded.measures[0]?.number ?? ''
		rangeTo.value = loaded.measures[loaded.measures.length - 1]?.number ?? ''
		range.value = null
		if (kept) {
			if (kept.ranged) {
				rangeFrom.value = kept.from
				rangeTo.value = kept.to
				rangeStartPending = true
			}
			focusKey.value = loaded.voices.some((voice) => voice.key === kept.focus) ? kept.focus : ''
		} else {
			applyStart(loaded)
		}
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
	revision: revision.value ? t('notenarchiv', 'Revision {number}', { number: revision.value.number }) : null,
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

// ---- review of the transcription (module "transcription_review") -------------

const reviewOn = computed(() => isModuleActive('transcription_review'))
const review = shallowRef<VersionReview | null>(null)
const reviewLoading = ref(false)
const reviewError = ref('')
const reviewShown = ref(props.start?.review === true)
const selectedPointId = ref<number | null>(null)

/** Points that wait for a decision (open or asked). */
const openPoints = computed(() => (review.value?.counts.open ?? 0) + (review.value?.counts.question ?? 0))

const reviewToggleLabel = computed(() => openPoints.value > 0
	? n('notenarchiv', 'Review: %n open point', 'Review: %n open points', openPoints.value)
	: t('notenarchiv', 'Review'))

async function loadReview(): Promise<void> {
	if (!reviewOn.value) {
		return
	}
	reviewLoading.value = true
	reviewError.value = ''
	try {
		review.value = await fetchReview(props.detail.version.id)
		if (selectedPointId.value !== null && !review.value.points.some((point) => point.id === selectedPointId.value)) {
			selectedPointId.value = null
		}
	} catch (error) {
		reviewError.value = errorMessage(error)
	} finally {
		reviewLoading.value = false
	}
}

/**
 * Show the place of a review point: its measures, its voice as focus voice
 * and its page of the scan; say it for screen readers.
 *
 * @param point - review point
 */
function selectPoint(point: ReviewPoint): void {
	selectedPointId.value = point.id
	if (point.page) {
		scanPage.value = point.page
	}
	const loaded = model.value
	if (!loaded || status.value !== 'ready') {
		return
	}
	const parts = [pointPlace(point)]
	const found = pointRange(point, loaded.measures)
	if (found !== null) {
		rangeFrom.value = loaded.measures[found.start].number
		rangeTo.value = loaded.measures[found.end].number
		applyRange()
	} else if (point.measure_from || point.measure_to || point.measures) {
		parts.push(t('notenarchiv', 'These measures are not in the score.'))
	}
	const voice = matchVoice(loaded.voices, point.voice, labelOf)
	focusKey.value = voice?.key ?? ''
	if (voice) {
		parts.push(t('notenarchiv', 'Focus voice: {voice}', { voice: labelOf(voice) }))
	} else if (canonicalVoice(point.voice) !== null) {
		parts.push(t('notenarchiv', 'The voice "{voice}" is not in the score; no focus voice.', { voice: point.voice ?? '' }))
	}
	announce(parts.join('. '))
}

/**
 * Play the measures of a review point.
 *
 * @param point - review point
 */
async function playPoint(point: ReviewPoint): Promise<void> {
	selectPoint(point)
	await nextTick()
	if (canPlay.value && !player?.isPlaying) {
		await togglePlay()
	}
}

function toggleReview(): void {
	reviewShown.value = !reviewShown.value
	if (reviewShown.value && review.value === null) {
		loadReview()
	}
}

async function onReviewChanged(): Promise<void> {
	await loadReview()
	emit('changed')
}

// ---- small corrections (module "corrections") ----------------------------------

const correctionsOn = computed(() => isModuleActive('corrections'))
/** The server checks again: module, roles, preservation copy, file permissions. */
const canCorrect = computed(() => correctionsOn.value && scoreText.value !== '' && (review.value
	? review.value.may.correct
	: hasAnyRole(['librarian', 'conductor']) && props.detail.version.preservation_copy !== true))
const correction = shallowRef<{ range: MeasureRange, voice: Voice, pointId: number | null } | null>(null)
const correctionHint = ref('')
const correctButton = ref(null)

/**
 * Open the correction form for the shown measures and the focus voice, or
 * for the place of a review point.
 *
 * @param point - review point the correction starts from
 */
function openCorrection(point: ReviewPoint | null = null): void {
	if (point) {
		selectPoint(point)
	}
	const loaded = model.value
	if (!loaded || scoreText.value === '') {
		return
	}
	const shown = range.value ?? { start: 0, end: loaded.measures.length - 1 }
	const voice = focusVoice.value
	if (!voice) {
		correctionHint.value = t('notenarchiv', 'Please choose a focus voice first: the form shows the notes of one voice.')
		return
	}
	if (shown.end - shown.start + 1 > MAX_CORRECTION_MEASURES) {
		correctionHint.value = t('notenarchiv', 'Please show at most {count} measures first (fields "From measure" and "To measure").', { count: MAX_CORRECTION_MEASURES })
		return
	}
	correctionHint.value = ''
	if (player?.isPlaying) {
		player.pause()
		playing.value = false
	}
	correction.value = { range: shown, voice, pointId: point?.id ?? null }
}

function closeCorrection(): void {
	correction.value = null
	focusAfterDialog(correctButton)
}

/**
 * A correction was saved as a new file: the copy and the review are loaded
 * again; the score follows its new file (watch below).
 *
 * @param result - the server's answer
 */
async function onCorrected(result: CorrectionResult): Promise<void> {
	correction.value = null
	focusAfterDialog(correctButton)
	announce(t('notenarchiv', 'Saved as a new file: {name}', { name: result.file.name }))
	await loadReview()
	emit('changed')
}

// The copy got another score file (a correction): show it, keeping measures and voice
watch(() => score.value?.fileId, (id, previous) => {
	if (id !== undefined && previous !== undefined && id !== previous) {
		load(true)
	}
})

// ---- scan beside the score ---------------------------------------------------------

const scans = computed(() => scanFiles(props.detail.files))
const scanFileId = ref<number | null>(scans.value[0]?.id ?? null)
const scanShown = ref(false)
const scanPage = ref(scanFileId.value !== null ? storedScanPage(props.detail.copy.id, scanFileId.value) : 1)
const scanPageInput = ref(String(scanPage.value))

watch(scanFileId, (id) => {
	if (id !== null) {
		scanPage.value = storedScanPage(props.detail.copy.id, id)
	}
})
watch(scanPage, (page) => {
	scanPageInput.value = String(page)
	if (scanFileId.value !== null) {
		storeScanPage(props.detail.copy.id, scanFileId.value, page)
	}
})
watch(scanPageInput, (value) => {
	const page = parsePage(value)
	if (page !== null && page !== scanPage.value) {
		scanPage.value = page
	}
})

const scanSrc = computed(() => (scanFileId.value === null ? '' : scanUrl(scanFileId.value, scanPage.value)))
const scanName = computed(() => scans.value.find((file) => file.id === scanFileId.value)?.name ?? '')

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
	const parts = [t('notenarchiv', 'Printed measure numbers {first} to {last}.', numberSpan.value)]
	if (model.value.pickup) {
		parts.push(t('notenarchiv', 'The pickup is measure 0.'))
	}
	if (canPlay.value) {
		parts.push(t('notenarchiv', 'Click a note to play from there.'))
	}
	return parts.join(' ')
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
/** Tempo, volume and the switches: behind a button, so that the score gets the room */
const settingsShown = ref(false)
/** The actions for the shown passage (text reference, link, task) */
const placeShown = ref(false)
const mode = ref<PlayMode>('all')
const repeats = ref(true)
const follow = ref(true)
/** Play the shown measures again and again */
const loop = ref(false)
/** Bars of count-in, remembered in this browser */
const countInBars = ref<CountInBars>(storedCountIn())
const countInChoice = computed<'0' | '1' | '2'>({
	get: () => String(countInBars.value) as '0' | '1' | '2',
	set: (value) => {
		countInBars.value = Number(value) as CountInBars
	},
})
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
		player.setLoop(loop.value)
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
		// The start message comes first; measures are announced 3 s later at the earliest
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
 * Play from a place: a clicked note or measure.
 *
 * @param time - position in seconds of the timeline
 */
async function playFrom(time: number): Promise<void> {
	const current = ensurePlayer()
	if (current.isPlaying) {
		moveTo(time)
		return
	}
	current.seek(time)
	await startPlaying(current)
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
	if (!target || !loaded || !canPlay.value || status.value !== 'ready') {
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
	if (time !== null) {
		playFrom(time)
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
watch(tempoPercent, (value) => player?.setRate(value / 100))
watch(volumePercent, (value) => player?.setVolume(value / 100))
watch(repeats, () => updateTimeline())
watch(countInBars, (bars) => storeCountIn(bars))
watch(loop, (on) => {
	// The loop takes the measures of the fields "From measure" and "To measure"
	const applied = rangeNumbers.value ?? { from: numberSpan.value.first, to: numberSpan.value.last }
	if (on && (!sameNumber(applied.from, rangeFrom.value || applied.from) || !sameNumber(applied.to, rangeTo.value || applied.to))) {
		applyRange()
	}
	player?.setLoop(on)
	const shown = rangeNumbers.value ?? { from: numberSpan.value.first, to: numberSpan.value.last }
	announce(on
		? t('notenarchiv', 'Loop on: measures {from} to {to} play again and again.', shown)
		: t('notenarchiv', 'Loop off.'))
})

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
	// The score scrolls in its box on a large screen, with the whole view on a small one
	if (scoreElement.value) {
		followMeasure(element, scoreElement.value)
	}
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
 * Space plays and pauses unless a control has the focus. While the score
 * has the focus, + and − zoom and the arrow keys left and right go to the
 * previous or next measure.
 *
 * @param event - key event inside the viewer
 */
function onKeydown(event: KeyboardEvent): void {
	const target = event.target instanceof HTMLElement ? event.target : null
	if (target?.closest(INTERACTIVE)) {
		return
	}
	const inScore = target === scoreElement.value
	const plain = !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
	if (event.key === ' ' && canPlay.value && status.value === 'ready') {
		event.preventDefault()
		togglePlay()
	} else if (inScore && (event.key === '+' || event.key === '-')) {
		event.preventDefault()
		zoomBy(event.key === '+' ? 1 : -1)
	} else if (inScore && plain && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && canPlay.value && status.value === 'ready') {
		event.preventDefault()
		stepMeasure(event.key === 'ArrowLeft' ? -1 : 1)
	}
}

// ---- lifecycle --------------------------------------------------------------

onMounted(() => {
	load()
	loadReview()
})

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
					<span v-if="versionLineText" class="score-viewer__file">{{ versionLineText }}</span>
				</p>
				<div class="score-viewer__state">
					<RightsBadge :rights-status="detail.version.rights_status"
						:rights-confirmed="detail.version.confirmed_at || detail.version.rights_confirmed"
						:preservation-copy="detail.version.preservation_copy" />
					<details v-if="musicxmlReview !== ''" class="score-viewer__review">
						<summary>{{ reviewLabel }}</summary>
						<p class="score-viewer__review-text">
							{{ musicxmlReview }}
						</p>
					</details>
				</div>
				<NcNoteCard v-if="start?.notice"
					:type="start.noticeType ?? 'info'"
					class="score-viewer__notice">
					{{ start.notice }}
				</NcNoteCard>
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
					<div class="score-viewer__error-actions">
						<!-- The archive folder is not shared with the account: the way to the access check -->
						<NcButton v-if="notShared && canChangeSettings" variant="primary" :href="accessCheckUrl">
							{{ t('notenarchiv', 'Check access to the archive folder') }}
						</NcButton>
						<NcButton v-if="retryable" @click="load()">
							{{ t('notenarchiv', 'Try again') }}
						</NcButton>
						<!-- Trying again cannot help: say where access to the files is explained -->
						<NcButton v-else-if="!notShared"
							variant="secondary"
							:href="adminGuideUrl(ADMIN_GUIDE.archive)"
							target="_blank">
							<template #icon>
								<NcIconSvgWrapper :path="mdiHelpCircleOutline" />
							</template>
							{{ t('notenarchiv', 'Help: access to the archive folder') }}
						</NcButton>
					</div>
					<p v-if="notShared" class="score-viewer__error-help">
						<GuideLink :href="userGuideUrl(USER_GUIDE.filesNotShared)" :label="t('notenarchiv', 'Files you cannot open (user guide)')" />
					</p>
				</template>
			</NcEmptyContent>

			<template v-else>
				<!-- Play and stop stay at the top while the view scrolls
				     (on a phone the score is part of the scrolling view) -->
				<div v-if="canPlay"
					class="score-viewer__buttons score-viewer__playbar"
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
				<!-- Three compact bars, so that the score itself starts high up:
				     playback, then measures and voice, then review and scan -->
				<div class="score-viewer__controls">
					<section v-if="canPlay"
						class="score-viewer__bar"
						:aria-label="t('notenarchiv', 'Playback')">
						<div v-if="canExportAudio" class="score-viewer__buttons">
							<NcButton ref="practiceButton"
								aria-haspopup="dialog"
								@click="openPractice">
								<template #icon>
									<NcIconSvgWrapper :path="mdiFileMusicOutline" />
								</template>
								{{ t('notenarchiv', 'Practice files …') }}
							</NcButton>
						</div>
						<!-- How the focus voice (chosen in the bar below) sounds -->
						<NcRadioGroup v-model="mode" :label="t('notenarchiv', 'Voices during playback')">
							<NcRadioGroupButton value="all" :label="t('notenarchiv', 'All equal')" />
							<NcRadioGroupButton value="focus"
								:label="t('notenarchiv', 'Focus voice louder')"
								:disabled="!focusVoice" />
							<NcRadioGroupButton value="solo"
								:label="t('notenarchiv', 'Focus voice only')"
								:disabled="!focusVoice" />
						</NcRadioGroup>
						<div class="score-viewer__buttons">
							<NcButton variant="tertiary"
								:aria-expanded="settingsShown ? 'true' : 'false'"
								:aria-controls="settingsId"
								@click="settingsShown = !settingsShown">
								<template #icon>
									<NcIconSvgWrapper :path="settingsShown ? mdiChevronUp : mdiTuneVariant" />
								</template>
								{{ t('notenarchiv', 'Tempo and volume') }}
							</NcButton>
						</div>
						<div v-show="settingsShown" :id="settingsId" class="score-viewer__settings">
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
							<NcRadioGroup v-model="countInChoice" :label="t('notenarchiv', 'Count-in')">
								<NcRadioGroupButton value="0" :label="t('notenarchiv', 'Off')" />
								<NcRadioGroupButton value="1" :label="t('notenarchiv', '1 bar')" />
								<NcRadioGroupButton value="2" :label="t('notenarchiv', '2 bars')" />
							</NcRadioGroup>
							<div class="score-viewer__switches">
								<NcCheckboxRadioSwitch v-model="repeats" type="switch">
									{{ t('notenarchiv', 'Play repeats') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-model="follow" type="switch">
									{{ t('notenarchiv', 'Scroll along') }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>
						<p v-if="!canExportAudio && exportReason" class="score-viewer__hint score-viewer__line">
							{{ exportReason }}
						</p>
					</section>
					<NcNoteCard v-else-if="playbackOn" type="info" class="score-viewer__no-playback">
						{{ playReason }}
					</NcNoteCard>

					<section class="score-viewer__bar" :aria-label="t('notenarchiv', 'Measures and voice')">
						<form class="score-viewer__range" @submit.prevent="applyRange">
							<!-- NcTextField puts a class on its input, not on its box: the width is set here -->
							<div class="score-viewer__measure-field">
								<NcTextField v-model="rangeFrom"
									:label="t('notenarchiv', 'From measure')"
									:error="rangeError !== ''" />
							</div>
							<div class="score-viewer__measure-field">
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
							<!-- A–B loop: the measures of the two fields again and again -->
							<NcCheckboxRadioSwitch v-if="canPlay"
								v-model="loop"
								class="score-viewer__loop"
								type="switch">
								{{ t('notenarchiv', 'Loop') }}
							</NcCheckboxRadioSwitch>
						</form>
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
						<!-- Share and ask: shown on request. Not a menu (NcActions): inside the
						     modal its Escape would close the whole score view as well -->
						<NcButton variant="secondary"
							:aria-expanded="placeShown ? 'true' : 'false'"
							:aria-controls="placeId"
							@click="placeShown = !placeShown">
							<template #icon>
								<NcIconSvgWrapper :path="placeShown ? mdiChevronUp : mdiDotsHorizontal" />
							</template>
							{{ t('notenarchiv', 'This passage') }}
						</NcButton>
						<div v-show="placeShown"
							:id="placeId"
							class="score-viewer__place score-viewer__line"
							role="group"
							:aria-label="t('notenarchiv', 'This passage')">
							<span class="score-viewer__hint score-viewer__reference">{{ reference }}</span>
							<NcButton variant="tertiary" @click="copyReference">
								<template #icon>
									<NcIconSvgWrapper :path="mdiContentCopy" />
								</template>
								{{ t('notenarchiv', 'Copy text reference') }}
							</NcButton>
							<NcButton v-if="canCreateLinks" variant="tertiary" @click="linkDialogOpen = true">
								<template #icon>
									<NcIconSvgWrapper :path="mdiLinkVariantPlus" />
								</template>
								{{ canShareOutside ? t('notenarchiv', 'Share …') : t('notenarchiv', 'Create internal link') }}
							</NcButton>
							<NcButton v-if="canCreateTasks" variant="tertiary" @click="taskDialogOpen = true">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClipboardTextOutline" />
								</template>
								{{ t('notenarchiv', 'New task for this place') }}
							</NcButton>
						</div>
						<p v-if="rangeError" class="score-viewer__error score-viewer__line" role="alert">
							{{ rangeError }}
						</p>
						<p class="score-viewer__hint score-viewer__line">
							{{ rangeHint }}
						</p>
					</section>

					<section v-if="reviewOn || canCorrect || scans.length > 0"
						class="score-viewer__bar"
						:aria-label="t('notenarchiv', 'Review and scan')">
						<NcButton v-if="reviewOn"
							variant="secondary"
							:aria-pressed="reviewShown ? 'true' : 'false'"
							@click="toggleReview">
							<template #icon>
								<NcIconSvgWrapper :path="mdiTextBoxCheckOutline" />
							</template>
							{{ reviewToggleLabel }}
						</NcButton>
						<NcButton v-if="canCorrect"
							ref="correctButton"
							variant="secondary"
							aria-haspopup="dialog"
							@click="openCorrection()">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPencilOutline" />
							</template>
							{{ t('notenarchiv', 'Correct notes …') }}
						</NcButton>
						<div v-if="scans.length > 0"
							class="score-viewer__scan-controls"
							role="group"
							:aria-label="t('notenarchiv', 'Scan')">
							<NcCheckboxRadioSwitch v-model="scanShown" type="switch">
								{{ t('notenarchiv', 'Show scan beside the score') }}
							</NcCheckboxRadioSwitch>
							<!-- file and page only while the scan is shown (a review point sets the page, too) -->
							<label v-if="scanShown && scans.length > 1" class="score-viewer__scan-choice" :for="scanSelectId">
								<span>{{ t('notenarchiv', 'Scan file') }}</span>
								<select :id="scanSelectId" v-model.number="scanFileId">
									<option v-for="file in scans" :key="file.id" :value="file.id">
										{{ file.name }}
									</option>
								</select>
							</label>
							<div v-if="scanShown" class="score-viewer__page-field">
								<NcTextField v-model="scanPageInput"
									type="number"
									min="1"
									:label="t('notenarchiv', 'Page of the scan')" />
							</div>
							<NcButton variant="tertiary"
								:href="scanSrc"
								target="_blank">
								<template #icon>
									<NcIconSvgWrapper :path="mdiOpenInNew" />
								</template>
								{{ t('notenarchiv', 'Open scan in a new window') }}
							</NcButton>
						</div>
						<p v-if="correctionHint" class="score-viewer__hint score-viewer__line" role="status">
							{{ correctionHint }}
						</p>
					</section>
				</div>

				<p :id="summaryId" class="score-viewer__summary">
					{{ summary }}
				</p>
				<p v-if="canPlay" :id="scoreHintId" class="hidden-visually">
					{{ t('notenarchiv', 'Space plays and pauses. The arrow keys left and right go to the previous or next measure.') }}
				</p>
				<div class="score-viewer__workspace">
					<!-- Scrollable score; focusable so it can be scrolled with the keyboard.
					     A click on a note plays from there; the keyboard alternative are the
					     arrow keys in the score (onKeydown on the whole viewer) -->
					<!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions, vuejs-accessibility/click-events-have-key-events -->
					<div ref="scoreElement"
						class="score-viewer__score"
						tabindex="0"
						role="region"
						:aria-label="t('notenarchiv', 'Score')"
						:aria-describedby="canPlay ? `${summaryId} ${scoreHintId}` : summaryId"
						@wheel="onWheel"
						@click="onScoreClick">
						<div ref="pagesElement"
							class="score-viewer__pages"
							:class="{ 'score-viewer__pages--playable': canPlay }"
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
					<!-- The browser's own PDF viewer; only present while switched on -->
					<div v-if="scanShown && scanSrc !== ''" class="score-viewer__scan">
						<!-- A new frame for every page: the PDF viewers of Chromium (Chrome, Edge)
						     ignore a change of only "#page=N" in the address of a frame -->
						<iframe :key="scanSrc"
							:src="scanSrc"
							class="score-viewer__scan-frame"
							:title="t('notenarchiv', 'Scan {name}, page {page}', { name: scanName, page: scanPage })" />
					</div>
					<ReviewPanel v-if="reviewOn && reviewShown"
						class="score-viewer__review-panel"
						:review="review"
						:loading="reviewLoading"
						:error="reviewError"
						:selected-id="selectedPointId"
						:can-play="canPlay"
						:can-correct="canCorrect"
						:tasks-active="isModuleActive('tasks')"
						@select="selectPoint"
						@play="playPoint"
						@correct="openCorrection"
						@changed="onReviewChanged"
						@retry="loadReview" />
				</div>
				<p class="hidden-visually" role="status">
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
					:count-in-bars="countInBars"
					:voice-name="labelOf"
					:version-line="versionLineText"
					@close="closePractice" />
				<CorrectionDialog v-if="correction && model"
					:detail="detail"
					:model="model"
					:score-text="scoreText"
					:range="correction.range"
					:voice="correction.voice"
					:voice-name="labelOf(correction.voice)"
					:points="review?.points ?? []"
					:point-id="correction.pointId"
					@saved="onCorrected"
					@close="closeCorrection" />
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

/* rights badge and the MusicXML review in one line */
.score-viewer__state {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));
}

.score-viewer__review[open] {
	flex-basis: 100%;
}

.score-viewer__review summary {
	/* a target of at least 24 pixels (WCAG 2.5.8) */
	padding-block: var(--default-grid-baseline);
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
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

/* Play and stop: stay at the top while the view scrolls, so that pausing
   is always at hand (room on the right for the close button of the modal) */
.score-viewer__playbar {
	position: sticky;
	z-index: 1;
	/* up into the padding of the view, so that nothing shows above the bar */
	top: calc(-3 * var(--default-grid-baseline));
	margin-block-start: calc(-2 * var(--default-grid-baseline));
	padding-block: calc(3 * var(--default-grid-baseline)) var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-clickable-area) + var(--default-grid-baseline));
	background-color: var(--color-main-background);
}

/* one bar of controls: wraps on narrow screens, hints take a line of their own */
.score-viewer__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	min-width: 0;
}

.score-viewer__buttons,
.score-viewer__range,
.score-viewer__settings,
.score-viewer__switches,
.score-viewer__zoom {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.score-viewer__settings {
	flex-basis: 100%;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
}

.score-viewer__settings .score-viewer__switches {
	align-self: center;
}

.score-viewer__line {
	flex-basis: 100%;
	margin: 0;
}

.score-viewer__place {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
}

.score-viewer__reference {
	overflow-wrap: anywhere;
}

.score-viewer__measure-field {
	width: 8em;
}

.score-viewer__loop {
	align-self: center;
}

.score-viewer__page-field {
	width: 10em;
}

/* wide enough for "No focus voice" and "Tenor 1, voice 2"; .nc-select sets
   min-width: 260px with three classes, one more wins */
.score-viewer__bar .score-viewer__voice.nc-select {
	min-width: min(100%, 20em);
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

.score-viewer__error {
	color: var(--color-error-text, var(--color-error));
}

.score-viewer__error-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.score-viewer__error-help {
	margin: calc(2 * var(--default-grid-baseline)) 0 0;
	text-align: center;
}

.score-viewer__zoom-value {
	min-width: 4em;
	align-self: center;
	text-align: center;
}

.score-viewer__notice {
	align-self: stretch;
}

.score-viewer__summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.score-viewer__workspace {
	display: flex;
	flex: 1 0 auto;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
}

/* As tall as the score: on a small screen a swipe moves the whole view, no
   box inside it catches the finger (a large screen: see the media query) */
.score-viewer__score {
	flex: 3 1 28em;
	box-sizing: border-box;
	min-width: 0;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.score-viewer__scan {
	display: flex;
	flex: 2 1 22em;
	height: max(16em, min(70vh, 40em));
	min-width: 0;
}

.score-viewer__scan-frame {
	flex: 1 1 auto;
	width: 100%;
	height: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.score-viewer__review-panel {
	flex: 1 1 20em;
	box-sizing: border-box;
	max-height: max(16em, min(85vh, 60em));
	overflow: auto;
}

.score-viewer__scan-controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.score-viewer__scan-choice {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.score-viewer__scan-choice select {
	min-height: var(--default-clickable-area);
	max-width: 20em;
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

/* A click on a note plays from there */
.score-viewer__pages--playable :deep(g.note) {
	cursor: pointer;
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

/* Wide and tall enough: score, scan and review fill the rest of the window
   side by side and scroll inside, instead of the whole view scrolling */
@media (min-width: 1024px) and (min-height: 640px) {
	.score-viewer__workspace {
		flex: 1 1 0;
		flex-wrap: nowrap;
		align-items: stretch;
		min-height: 26em;
	}

	.score-viewer__score,
	.score-viewer__scan {
		height: auto;
	}

	.score-viewer__review-panel {
		max-height: none;
	}
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.score-viewer {
		--default-clickable-area: 44px;
	}
}

@media (max-width: 512px) {
	.score-viewer {
		padding: calc(2 * var(--default-grid-baseline));
	}

	.score-viewer__slider,
	.score-viewer__bar .score-viewer__voice.nc-select {
		min-width: 100%;
	}

	.score-viewer__measure-field {
		width: 7em;
	}
}
</style>
