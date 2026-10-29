/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Tools for practising in the score view and in shared pieces (milestone
 * 0.13): a mixer with a level per voice, transposing the playback and the
 * starting tones of the voices. Everything happens in the browser on the
 * playback events; nothing about the practising goes to the server.
 */
import type { PlayEvent, Timeline } from './timeline.ts'
import type { Voice } from './voices.ts'

import { pitchName } from '../states.ts'
import { memberKey } from './voices.ts'

/** Level of every voice in percent (key: Voice.key); missing voices are at 100 %. */
export type Mix = Record<string, number>

/** Most semitones up or down */
export const TRANSPOSE_MAX = 6

/**
 * Loudness of every voice member key with the mixer on top of a playback
 * mode: the gain of the mode times the level of the voice.
 *
 * @param base - gain per voice member key from the mode (player.voiceGains)
 * @param voices - voices of the score
 * @param mix - level per voice in percent
 */
export function mixedGains(base: (voice: string) => number, voices: Voice[], mix: Mix): (voice: string) => number {
	const levels = new Map<string, number>()
	for (const voice of voices) {
		const level = Math.max(0, Math.min(100, mix[voice.key] ?? 100)) / 100
		for (const member of voice.members) {
			levels.set(memberKey(member.staff, member.layer), level)
		}
	}
	if ([...levels.values()].every((level) => level === 1)) {
		return base
	}
	return (voice: string) => base(voice) * (levels.get(voice) ?? 1)
}

/**
 * Whether the mixer changes anything.
 *
 * @param mix - level per voice in percent
 */
export function mixChanged(mix: Mix): boolean {
	return Object.values(mix).some((level) => level !== 100)
}

/**
 * The playback events a number of semitones higher or lower. The notation
 * stays as it is; only the sound moves.
 *
 * @param timeline - playback events
 * @param semitones - -TRANSPOSE_MAX … TRANSPOSE_MAX
 */
export function transposeTimeline(timeline: Timeline, semitones: number): Timeline {
	const shift = Math.round(Math.max(-TRANSPOSE_MAX, Math.min(TRANSPOSE_MAX, semitones)))
	if (shift === 0) {
		return timeline
	}
	return {
		...timeline,
		events: timeline.events.map((event) => ({ ...event, pitch: Math.max(0, Math.min(127, event.pitch + shift)) })),
	}
}

/**
 * The key signature after transposing, in fifths from -6 to 6 (e.g. one
 * semitone up from C major: D flat major, -5).
 *
 * @param fifths - key signature of the score
 * @param semitones - semitones up (negative: down)
 */
export function transposedFifths(fifths: number, semitones: number): number {
	const moved = (((fifths + 7 * semitones) % 12) + 12) % 12
	return moved > 6 ? moved - 12 : moved
}

const SHARP_SPELLING: [string, number][] = [['C', 0], ['C', 1], ['D', 0], ['D', 1], ['E', 0], ['F', 0], ['F', 1], ['G', 0], ['G', 1], ['A', 0], ['A', 1], ['B', 0]]
const FLAT_SPELLING: [string, number][] = [['C', 0], ['D', -1], ['D', 0], ['E', -1], ['E', 0], ['F', 0], ['G', -1], ['G', 0], ['A', -1], ['A', 0], ['B', -1], ['B', 0]]

/**
 * Name of a MIDI pitch, spelled with sharps in sharp keys and with flats in
 * flat keys, e.g. 61 → "C♯4" or "Des4" (German).
 *
 * @param pitch - MIDI pitch, 60 = C4
 * @param fifths - key signature in fifths; null: sharps
 * @param german - German note names (H, Fis, Es)
 */
export function midiPitchName(pitch: number, fifths: number | null, german: boolean): string {
	const [step, alter] = ((fifths ?? 0) < 0 ? FLAT_SPELLING : SHARP_SPELLING)[((pitch % 12) + 12) % 12]
	return pitchName(step, alter, Math.floor(pitch / 12) - 1, german)
}

/** The first tone of a voice from a place of the playback. */
export interface StartingTone {
	voice: Voice
	pitch: number
}

/**
 * The first struck tone of every voice from a time on, lowest first; a
 * voice that rests there gives its next tone. Voices that do not sing any
 * more are left out.
 *
 * @param timeline - playback events (already transposed)
 * @param voices - voices to give the tones of
 * @param from - seconds of the timeline at 100 % tempo
 */
export function startingTones(timeline: Timeline, voices: Voice[], from: number): StartingTone[] {
	const voiceOf = new Map<string, Voice>()
	for (const voice of voices) {
		for (const member of voice.members) {
			voiceOf.set(memberKey(member.staff, member.layer), voice)
		}
	}
	const first = new Map<Voice, PlayEvent>()
	for (const event of timeline.events) {
		const voice = voiceOf.get(event.voice)
		if (!voice || !event.sound || event.soundEnd <= from + 1e-6) {
			continue
		}
		const known = first.get(voice)
		// A note that still sounds at `from` counts as starting there
		const start = Math.max(event.start, from)
		const knownStart = known ? Math.max(known.start, from) : Infinity
		if (start < knownStart - 1e-6 || (Math.abs(start - knownStart) <= 1e-6 && known && event.pitch > known.pitch)) {
			first.set(voice, event)
		}
	}
	return [...first.entries()]
		.map(([voice, event]) => ({ voice, pitch: event.pitch }))
		.sort((a, b) => a.pitch - b.pitch)
}

/** Seconds between the tones and how long the chord holds after the last one. */
export const TONE_STEP = 0.7
export const TONE_HOLD = 1.6

/**
 * When each starting tone sounds: one after the other from the lowest,
 * each held until the end, so the chord builds up.
 *
 * @param count - number of tones
 * @return start of each tone and the common end, in seconds from now
 */
export function toneTimes(count: number): { starts: number[], end: number } {
	const starts = Array.from({ length: count }, (_, index) => index * TONE_STEP)
	return { starts, end: count === 0 ? 0 : (count - 1) * TONE_STEP + TONE_HOLD }
}
