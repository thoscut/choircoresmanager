// Composable rendering a MusicXML score with Verovio and playing it back with Tone.js

import type * as ToneNamespace from 'tone'
import type { VerovioToolkit } from 'verovio/esm'
import type { Ref } from 'vue'
import type { NoteEvent } from './scoreModel.ts'

import { ref, shallowRef } from 'vue'
import { buildNoteEvents, extractVoices, sanitizeSvg } from './scoreModel.ts'
import { t } from '@/utils/l10n'

type ToneModule = typeof ToneNamespace

export interface VoiceState {
	key: string
	label: string
	muted: boolean
	solo: boolean
	/** Volume in decibels */
	volume: number
}

export type PlayerStatus = 'idle' | 'loading' | 'ready' | 'error'

// Playback is scheduled in transport ticks at this tempo; tempo changes then only change the transport's bpm
const BASE_BPM = 120
const VEROVIO_SCALE = 40
const ZIP_MAGIC = [0x50, 0x4B, 0x03, 0x04]
const HIGHLIGHT_CLASS = 'score-player__note--playing'

let toolkitPromise: Promise<VerovioToolkit> | null = null
let tonePromise: Promise<ToneModule> | null = null

/**
 * Load the Verovio toolkit once. It is large, so it is only fetched when a player is shown.
 */
function loadToolkit(): Promise<VerovioToolkit> {
	if (!toolkitPromise) {
		toolkitPromise = (async () => {
			const [{ default: createVerovioModule }, { VerovioToolkit }] = await Promise.all([
				import('verovio/wasm'),
				import('verovio/esm'),
			])
			return new VerovioToolkit(await createVerovioModule())
		})()
		toolkitPromise.catch(() => {
			toolkitPromise = null
		})
	}
	return toolkitPromise
}

/**
 * Load Tone.js once
 */
function loadTone(): Promise<ToneModule> {
	if (!tonePromise) {
		tonePromise = import('tone')
	}
	return tonePromise
}

/**
 * @param data - File content
 */
function isZip(data: ArrayBuffer): boolean {
	const bytes = new Uint8Array(data, 0, Math.min(4, data.byteLength))
	return ZIP_MAGIC.every((byte, i) => bytes[i] === byte)
}

/**
 * Score player state and controls.
 *
 * @param container - Element the score is rendered into
 */
export function useScorePlayer(container: Ref<HTMLElement | null>) {
	const status = ref<PlayerStatus>('idle')
	const errorMessage = ref('')
	const pageCount = ref(0)
	const currentPage = ref(1)
	const voices = ref<VoiceState[]>([])
	const isPlaying = ref(false)
	const tempo = ref(100)
	const position = ref(0)
	const duration = ref(0)

	const toolkit = shallowRef<VerovioToolkit | null>(null)
	let tone: ToneModule | null = null
	let events: NoteEvent[] = []
	let scheduled = false
	let highlighted: Element[] = []
	let animationFrame: number | null = null
	const channels = new Map<string, InstanceType<ToneModule['Channel']>>()
	const synths = new Map<string, InstanceType<ToneModule['PolySynth']>>()
	let transportEventIds: number[] = []

	/**
	 * Load a MusicXML file (uncompressed or compressed .mxl)
	 *
	 * @param data - File content
	 */
	async function load(data: ArrayBuffer): Promise<void> {
		stop()
		status.value = 'loading'
		errorMessage.value = ''
		try {
			const tk = await loadToolkit()
			tk.setOptions({
				scale: VEROVIO_SCALE,
				pageWidth: getPageWidth(),
				pageHeight: 60000,
				adjustPageHeight: true,
				breaks: 'auto',
				footer: 'none',
				svgViewBox: true,
			})
			const loaded = isZip(data) ? tk.loadZipDataBuffer(data) : tk.loadData(new TextDecoder().decode(data))
			if (!loaded || tk.getPageCount() === 0) {
				throw new Error(t('The file could not be read as MusicXML'))
			}
			toolkit.value = tk

			const { voices: scoreVoices, noteVoices } = extractVoices(tk.getMEI(), (n) => t('Voice {number}', { number: n }))
			const timemap = tk.renderToTimemap({ includeRests: false })
			events = buildNoteEvents(timemap, noteVoices, (id) => {
				const values = tk.getMIDIValuesForElement(id)
				return values && typeof values.pitch === 'number' ? values : null
			})
			duration.value = events.reduce((max, e) => Math.max(max, e.start + e.duration), 0)
			voices.value = scoreVoices.map((voice) => ({ ...voice, muted: false, solo: false, volume: 0 }))
			scheduled = false
			position.value = 0

			pageCount.value = tk.getPageCount()
			renderPage(1)
			status.value = 'ready'
		} catch (error) {
			console.error('Failed to load score:', error)
			errorMessage.value = error instanceof Error && error.message ? error.message : t('The score could not be displayed')
			status.value = 'error'
		}
	}

	/**
	 * Page width in Verovio units, matching the container width
	 */
	function getPageWidth(): number {
		const width = container.value?.clientWidth || 800
		return Math.max(400, Math.round(width * 100 / VEROVIO_SCALE))
	}

	/**
	 * @param page - Page number, starting at 1
	 */
	function renderPage(page: number): void {
		const tk = toolkit.value
		if (!tk || !container.value) {
			return
		}
		const target = Math.min(Math.max(1, page), pageCount.value)
		const svg = sanitizeSvg(tk.renderToSVG(target))
		if (!svg) {
			return
		}
		container.value.replaceChildren(svg)
		currentPage.value = target
		highlighted = []
	}

	/**
	 * Re-flow the score after the container was resized
	 */
	function relayout(): void {
		const tk = toolkit.value
		if (!tk || status.value !== 'ready') {
			return
		}
		tk.setOptions({ pageWidth: getPageWidth() })
		tk.redoLayout()
		pageCount.value = tk.getPageCount()
		renderPage(Math.min(currentPage.value, pageCount.value))
	}

	/**
	 * @param ms - Time in milliseconds at the original tempo
	 */
	function msToTicks(ms: number): number {
		return Math.round(ms / 1000 * (BASE_BPM / 60) * tone!.getTransport().PPQ)
	}

	/**
	 * @param ticks - Transport ticks
	 */
	function ticksToMs(ticks: number): number {
		return ticks / tone!.getTransport().PPQ / (BASE_BPM / 60) * 1000
	}

	/**
	 * Create synthesizers and schedule all notes on the transport
	 */
	function schedule(): void {
		const Tone = tone!
		const transport = Tone.getTransport()
		for (const id of transportEventIds) {
			transport.clear(id)
		}
		transportEventIds = []
		for (const synth of synths.values()) {
			synth.dispose()
		}
		for (const channel of channels.values()) {
			channel.dispose()
		}
		synths.clear()
		channels.clear()

		for (const voice of voices.value) {
			const channel = new Tone.Channel({ volume: voice.volume, mute: voice.muted, solo: voice.solo }).toDestination()
			const synth = new Tone.PolySynth(Tone.Synth, {
				oscillator: { type: 'triangle' },
				envelope: { attack: 0.04, decay: 0.1, sustain: 0.8, release: 0.25 },
				volume: -12,
			}).connect(channel)
			channels.set(voice.key, channel)
			synths.set(voice.key, synth)
		}

		for (const event of events) {
			const synth = synths.get(event.voiceKey)
			if (!synth) {
				continue
			}
			const frequency = Tone.Frequency(event.pitch, 'midi').toFrequency()
			const durationTicks = Math.max(1, msToTicks(event.duration) - 2)
			transportEventIds.push(transport.schedule((time) => {
				synth.triggerAttackRelease(frequency, Tone.Ticks(durationTicks).toSeconds(), time)
			}, `${msToTicks(event.start)}i`))
		}
		scheduled = true
	}

	/**
	 * Start or resume playback
	 */
	async function play(): Promise<void> {
		if (status.value !== 'ready' || isPlaying.value) {
			return
		}
		tone = await loadTone()
		await tone.start()
		if (!scheduled) {
			schedule()
		}
		const transport = tone.getTransport()
		transport.bpm.value = BASE_BPM * tempo.value / 100
		if (position.value >= duration.value) {
			position.value = 0
		}
		transport.ticks = msToTicks(position.value)
		transport.start()
		isPlaying.value = true
		animationFrame = requestAnimationFrame(tick)
	}

	/**
	 * Pause playback, keeping the position
	 */
	function pause(): void {
		if (!tone || !isPlaying.value) {
			return
		}
		tone.getTransport().pause()
		releaseAll()
		isPlaying.value = false
		cancelTick()
	}

	/**
	 * Stop playback and go back to the beginning
	 */
	function stop(): void {
		if (tone) {
			tone.getTransport().stop()
			releaseAll()
		}
		isPlaying.value = false
		cancelTick()
		position.value = 0
		clearHighlight()
		if (status.value === 'ready' && currentPage.value !== 1) {
			renderPage(1)
		}
	}

	/**
	 * Jump to a position
	 *
	 * @param ms - Time in milliseconds at the original tempo
	 */
	function seek(ms: number): void {
		position.value = Math.min(Math.max(0, ms), duration.value)
		if (tone && isPlaying.value) {
			releaseAll()
			tone.getTransport().ticks = msToTicks(position.value)
		} else {
			updateHighlight(position.value)
		}
	}

	/**
	 * Jump to a note of the rendered score, e.g. after it was clicked
	 *
	 * @param id - The note's element id
	 */
	function seekToElement(id: string): void {
		const tk = toolkit.value
		if (!tk) {
			return
		}
		const time = tk.getTimeForElement(id)
		if (typeof time === 'number' && time >= 0) {
			seek(time)
		}
	}

	/**
	 * @param percent - Tempo relative to the score's tempo
	 */
	function setTempo(percent: number): void {
		tempo.value = percent
		if (tone) {
			tone.getTransport().bpm.value = BASE_BPM * percent / 100
		}
	}

	/**
	 * Apply mute, solo and volume of a voice to its audio channel
	 *
	 * @param key - The voice key
	 */
	function updateVoice(key: string): void {
		const voice = voices.value.find((v) => v.key === key)
		const channel = channels.get(key)
		if (!voice || !channel) {
			return
		}
		channel.mute = voice.muted
		channel.solo = voice.solo
		channel.volume.value = voice.volume
	}

	/**
	 * Release all sounding notes
	 */
	function releaseAll(): void {
		for (const synth of synths.values()) {
			synth.releaseAll()
		}
	}

	/**
	 * Animation frame callback following the playback position
	 */
	function tick(): void {
		if (!tone || !isPlaying.value) {
			return
		}
		position.value = ticksToMs(tone.getTransport().ticks)
		if (position.value >= duration.value) {
			stop()
			return
		}
		updateHighlight(position.value)
		animationFrame = requestAnimationFrame(tick)
	}

	/**
	 * Cancel the animation frame callback
	 */
	function cancelTick(): void {
		if (animationFrame !== null) {
			cancelAnimationFrame(animationFrame)
			animationFrame = null
		}
	}

	/**
	 * Highlight the notes sounding at a given time, turning pages as needed
	 *
	 * @param ms - Time in milliseconds at the original tempo
	 */
	function updateHighlight(ms: number): void {
		const tk = toolkit.value
		if (!tk || !container.value) {
			return
		}
		const elements = tk.getElementsAtTime(Math.round(ms))
		if (elements.page > 0 && elements.page !== currentPage.value) {
			renderPage(elements.page)
		}
		const ids = elements.notes ?? []
		const isSame = ids.length === highlighted.length && highlighted.every((el) => ids.includes(el.id))
		if (isSame) {
			return
		}
		clearHighlight()
		highlighted = ids
			.map((id) => container.value!.querySelector(`#${CSS.escape(id)}`))
			.filter((el): el is Element => el !== null)
		highlighted.forEach((el) => el.classList.add(HIGHLIGHT_CLASS))
		scrollIntoViewIfNeeded(highlighted[0])
	}

	/**
	 * Remove the highlight from all notes
	 */
	function clearHighlight(): void {
		highlighted.forEach((el) => el.classList.remove(HIGHLIGHT_CLASS))
		highlighted = []
	}

	/**
	 * @param element - Element that should be visible
	 */
	function scrollIntoViewIfNeeded(element: Element | undefined): void {
		if (!element) {
			return
		}
		const rect = element.getBoundingClientRect()
		if (rect.top < 0 || rect.bottom > window.innerHeight) {
			element.scrollIntoView({ block: 'center', behavior: 'smooth' })
		}
	}

	/**
	 * Stop playback and free audio resources
	 */
	function dispose(): void {
		stop()
		if (tone) {
			const transport = tone.getTransport()
			for (const id of transportEventIds) {
				transport.clear(id)
			}
		}
		transportEventIds = []
		for (const synth of synths.values()) {
			synth.dispose()
		}
		for (const channel of channels.values()) {
			channel.dispose()
		}
		synths.clear()
		channels.clear()
		scheduled = false
	}

	return {
		status,
		errorMessage,
		pageCount,
		currentPage,
		voices,
		isPlaying,
		tempo,
		position,
		duration,
		load,
		renderPage,
		relayout,
		play,
		pause,
		stop,
		seek,
		seekToElement,
		setTempo,
		updateVoice,
		dispose,
	}
}
