/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PlayEvent, Timeline } from '../score/timeline.ts'
import type { Voice } from '../score/voices.ts'

import { describe, expect, it } from 'vitest'
import { detectPitch, frequencyToMidi, listeningProblem, nearOctave, pitchRange, targetAt, targetNotes, tuningOf } from '../score/pitch.ts'

const RATE = 48000

/**
 * A sung tone: a fundamental with some overtones.
 *
 * @param hz - frequency of the tone
 * @param level - loudness of the fundamental
 */
function tone(hz: number, level = 0.3): Float32Array {
	const samples = new Float32Array(2048)
	for (let i = 0; i < samples.length; i++) {
		const phase = 2 * Math.PI * hz * i / RATE
		samples[i] = level * (Math.sin(phase) + 0.5 * Math.sin(2 * phase) + 0.25 * Math.sin(3 * phase))
	}
	return samples
}

/**
 * A playback event.
 *
 * @param voice - voice member key
 * @param pitch - MIDI pitch
 * @param start - seconds
 * @param end - seconds
 * @param sound - false for a tied continuation
 */
function note(voice: string, pitch: number, start: number, end: number, sound = true): PlayEvent {
	return { id: `${voice}-${pitch}-${start}`, voice, pitch, start, end, sound, soundEnd: end, measure: 0 }
}

const ALTO: Voice = { key: '1.2', name: 'Alt', part: 'Alt', number: 1, members: [{ staff: 1, layer: 2 }], notes: 3 }

describe('finding the pitch', () => {
	it('finds the tones of singing voices', () => {
		for (const hz of [98, 220, 261.63, 440, 880]) {
			const found = detectPitch(tone(hz), RATE)
			expect(found).not.toBeNull()
			expect(Math.abs(frequencyToMidi(found!) - frequencyToMidi(hz))).toBeLessThan(0.1)
		}
	})

	it('gives nothing for silence or noise', () => {
		expect(detectPitch(new Float32Array(2048), RATE)).toBeNull()
		expect(detectPitch(tone(440, 0.001), RATE)).toBeNull()
		let seed = 1
		const noise = new Float32Array(2048).map(() => {
			seed = (seed * 16807) % 2147483647
			return seed / 2147483647 - 0.5
		})
		expect(detectPitch(noise, RATE)).toBeNull()
	})

	it('turns frequencies into MIDI pitches', () => {
		expect(frequencyToMidi(440)).toBe(69)
		expect(frequencyToMidi(880)).toBe(81)
		expect(frequencyToMidi(261.63)).toBeCloseTo(60, 2)
	})
})

describe('the tones of the voice', () => {
	const timeline: Timeline = {
		events: [
			note('1.2', 64, 0, 1),
			note('1.2', 64, 1, 2, false),
			note('1.1', 72, 0, 2),
			note('1.2', 67, 2, 3),
			note('1.2', 60, 2, 3),
		],
		measures: [{ index: 0, start: 0, end: 3 }],
		duration: 3,
	}

	it('takes the voice, a tie as one tone', () => {
		expect(targetNotes(timeline, ALTO)).toEqual([
			{ start: 0, end: 2, pitch: 64 },
			{ start: 2, end: 3, pitch: 67 },
			{ start: 2, end: 3, pitch: 60 },
		])
	})

	it('gives the tone at a time, the highest of a chord, none in a rest', () => {
		const notes = targetNotes(timeline, ALTO)
		expect(targetAt(notes, 1.5)).toBe(64)
		expect(targetAt(notes, 2.5)).toBe(67)
		expect(targetAt(notes, 3.5)).toBeNull()
		expect(pitchRange(notes)).toEqual({ low: 57, high: 70 })
		expect(pitchRange([])).toEqual({ low: 55, high: 79 })
	})
})

describe('how it is sung', () => {
	it('compares in the octave of the target', () => {
		expect(nearOctave(57.2, 69)).toBeCloseTo(69.2)
		expect(nearOctave(75, 69)).toBe(63)
		expect(tuningOf(57.2, 69)).toEqual({ cents: 20, tuning: 'in-tune' })
		expect(tuningOf(68.5, 69)).toEqual({ cents: -50, tuning: 'bit-low' })
		expect(tuningOf(69.8, 69)).toEqual({ cents: 80, tuning: 'bit-high' })
		expect(tuningOf(71, 69)).toEqual({ cents: 200, tuning: 'high' })
		expect(tuningOf(66, 69)).toEqual({ cents: -300, tuning: 'low' })
	})

	it('needs a secure page, the microphone and Web Audio', () => {
		expect(listeningProblem({ isSecureContext: false })).toBe('insecure')
		expect(listeningProblem({ isSecureContext: true, navigator: {} })).toBe('unsupported')
		expect(listeningProblem({ isSecureContext: true, navigator: { mediaDevices: {} }, AudioContext: class {} })).toBeNull()
	})
})
