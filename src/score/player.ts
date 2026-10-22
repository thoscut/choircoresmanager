/**
 * Plays a timeline with the synthesizer: look-ahead scheduling on the
 * AudioContext clock, pause/resume, tempo and voice balance changes while
 * playing. Browser only (Web Audio).
 */
import type { PlayEvent, Timeline } from './timeline.ts'

import { Synth } from './synth.ts'

export type PlayMode = 'all' | 'focus' | 'solo'

export interface PlayerCallbacks {
	/** current position in seconds of the timeline (at 100 % tempo), every frame */
	onPosition: (time: number) => void
	/** the end of the timeline was reached */
	onEnd: () => void
}

const LOOK_AHEAD = 0.25
const START_DELAY = 0.06

/**
 * Loudness of a voice in a playback mode.
 *
 * @param mode - playback mode
 * @param inFocus - whether the voice is the focus voice
 * @param hasFocus - whether a focus voice is chosen at all
 */
export function voiceGain(mode: PlayMode, inFocus: boolean, hasFocus: boolean): number {
	if (!hasFocus || mode === 'all') {
		return 0.55
	}
	if (mode === 'solo') {
		return inFocus ? 0.8 : 0
	}
	return inFocus ? 0.9 : 0.16
}

/**
 * Index of the first event that starts at or after a time.
 *
 * @param events - events sorted by start
 * @param time - seconds
 */
export function firstEventAt(events: PlayEvent[], time: number): number {
	let low = 0
	let high = events.length
	while (low < high) {
		const middle = (low + high) >> 1
		if (events[middle].start < time - 1e-6) {
			low = middle + 1
		} else {
			high = middle
		}
	}
	return low
}

export class ScorePlayer {

	private synth: Synth | null = null
	private timeline: Timeline = { events: [], measures: [], duration: 0 }
	private rate = 1
	private volume = 0.7
	private gainOf: (voice: string) => number = () => 0.55
	private playing = false
	private startAudio = 0
	private startScore = 0
	private position = 0
	private next = 0
	private timer: ReturnType<typeof setInterval> | null = null
	private frame = 0
	private readonly callbacks: PlayerCallbacks

	constructor(callbacks: PlayerCallbacks) {
		this.callbacks = callbacks
	}

	get isPlaying(): boolean {
		return this.playing
	}

	get duration(): number {
		return this.timeline.duration
	}

	/** Current position in seconds of the timeline. */
	currentTime(): number {
		if (!this.playing || !this.synth) {
			return this.position
		}
		const elapsed = this.synth.context.currentTime - this.startAudio
		return Math.max(this.startScore, this.startScore + elapsed * this.rate)
	}

	/**
	 * Use a new timeline (range, repeats changed); stops playback.
	 *
	 * @param timeline - events to play
	 */
	setTimeline(timeline: Timeline): void {
		this.stop()
		this.timeline = timeline
	}

	/**
	 * Tempo as a factor of the score's tempo.
	 *
	 * @param rate - e.g. 0.8 for 80 %
	 */
	setRate(rate: number): void {
		this.restartAfter(() => {
			this.rate = Math.max(0.1, rate)
		})
	}

	/**
	 * Loudness per voice member key (staff.layer).
	 *
	 * @param gainOf - gain 0 … 1 for a voice
	 */
	setGains(gainOf: (voice: string) => number): void {
		this.restartAfter(() => {
			this.gainOf = gainOf
		})
	}

	/**
	 * Overall volume.
	 *
	 * @param volume - 0 … 1
	 */
	setVolume(volume: number): void {
		this.volume = volume
		this.synth?.setVolume(volume)
	}

	async play(): Promise<void> {
		if (this.playing || this.timeline.events.length === 0) {
			return
		}
		if (!this.synth) {
			this.synth = new Synth(this.volume)
		}
		await this.synth.resume()
		if (this.position >= this.timeline.duration) {
			this.position = 0
		}
		this.startFrom(this.position)
		this.playing = true
		this.timer = setInterval(() => this.schedule(), 25)
		this.schedule()
		const tick = () => {
			if (!this.playing) {
				return
			}
			this.callbacks.onPosition(this.currentTime())
			this.frame = requestAnimationFrame(tick)
		}
		this.frame = requestAnimationFrame(tick)
	}

	pause(): void {
		if (!this.playing) {
			return
		}
		this.position = Math.min(this.currentTime(), this.timeline.duration)
		this.halt()
	}

	stop(): void {
		this.halt()
		this.position = 0
	}

	async destroy(): Promise<void> {
		this.halt()
		const synth = this.synth
		this.synth = null
		await synth?.close()
	}

	private halt(): void {
		this.playing = false
		if (this.timer !== null) {
			clearInterval(this.timer)
			this.timer = null
		}
		cancelAnimationFrame(this.frame)
		this.synth?.silence()
	}

	private startFrom(time: number): void {
		const synth = this.synth
		if (!synth) {
			return
		}
		this.startScore = time
		this.startAudio = synth.context.currentTime + START_DELAY
		this.next = firstEventAt(this.timeline.events, time)
	}

	/**
	 * Apply a change while playing: silence and schedule again from now.
	 *
	 * @param change - the change to apply
	 */
	private restartAfter(change: () => void): void {
		if (!this.playing) {
			change()
			return
		}
		const time = this.currentTime()
		this.synth?.silence()
		change()
		this.startFrom(time)
		this.schedule()
	}

	private schedule(): void {
		const synth = this.synth
		if (!synth || !this.playing) {
			return
		}
		const now = synth.context.currentTime
		const horizon = now + LOOK_AHEAD
		const events = this.timeline.events
		while (this.next < events.length) {
			const event = events[this.next]
			const at = this.startAudio + (event.start - this.startScore) / this.rate
			if (at > horizon) {
				break
			}
			this.next++
			if (!event.sound) {
				continue
			}
			const gain = this.gainOf(event.voice)
			if (gain <= 0) {
				continue
			}
			const end = this.startAudio + (event.soundEnd - this.startScore) / this.rate
			synth.note(event.pitch, Math.max(at, now), end, gain)
		}
		if (this.next >= events.length && this.currentTime() >= this.timeline.duration) {
			this.halt()
			this.position = 0
			this.callbacks.onEnd()
		}
	}

}
