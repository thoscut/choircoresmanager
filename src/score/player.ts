/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Plays a timeline with the synthesizer: look-ahead scheduling on the
 * AudioContext clock, pause/resume, tempo and voice balance changes while
 * playing. Browser only (Web Audio).
 */
import type { PlayEvent, Timeline } from './timeline.ts'
import type { VoiceMember } from './voices.ts'

import { Synth } from './synth.ts'
import { memberKey } from './voices.ts'

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
 * Loudness of every voice member key (staff.layer) for a focus voice and a
 * playback mode, as in the score view.
 *
 * @param members - staff/layer pairs of the focus voice; empty for none
 * @param mode - playback mode
 */
export function voiceGains(members: VoiceMember[], mode: PlayMode): (voice: string) => number {
	const keys = new Set(members.map((member) => memberKey(member.staff, member.layer)))
	const hasFocus = keys.size > 0
	return (voice: string) => voiceGain(mode, keys.has(voice), hasFocus)
}

/** Whatever plays notes: the synthesizer, or a recorder in tests. */
export interface NoteTarget {
	note(pitch: number, start: number, end: number, gain: number): void
}

/** Where the timeline lies on the clock of an audio context. */
export interface Clock {
	/** context time at which `startScore` sounds */
	startAudio: number
	/** position in the timeline (seconds at 100 % tempo) */
	startScore: number
	/** tempo factor */
	rate: number
}

/**
 * Hand the events that start up to a horizon to the synthesizer, as the
 * playback and the practice audio files both do: tied continuations are
 * not struck again, voices with gain 0 stay silent.
 *
 * @param target - synthesizer
 * @param events - events sorted by start
 * @param next - index of the first event not yet scheduled
 * @param clock - where the timeline lies on the context's clock
 * @param horizon - schedule events that start up to this context time
 * @param now - current context time; nothing starts earlier
 * @param gainOf - gain 0 … 1 per voice member key
 * @return the index of the first event not yet scheduled
 */
export function scheduleEvents(target: NoteTarget, events: PlayEvent[], next: number, clock: Clock, horizon: number, now: number, gainOf: (voice: string) => number): number {
	let index = next
	while (index < events.length) {
		const event = events[index]
		const at = clock.startAudio + (event.start - clock.startScore) / clock.rate
		if (at > horizon) {
			break
		}
		index++
		if (!event.sound) {
			continue
		}
		const gain = gainOf(event.voice)
		if (gain <= 0) {
			continue
		}
		const end = clock.startAudio + (event.soundEnd - clock.startScore) / clock.rate
		target.note(event.pitch, Math.max(at, now), end, gain)
	}
	return index
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
		const events = this.timeline.events
		const clock = { startAudio: this.startAudio, startScore: this.startScore, rate: this.rate }
		this.next = scheduleEvents(synth, events, this.next, clock, now + LOOK_AHEAD, now, this.gainOf)
		if (this.next >= events.length && this.currentTime() >= this.timeline.duration) {
			this.halt()
			this.position = 0
			this.callbacks.onEnd()
		}
	}

}
