/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PlayEvent } from '../score/timeline.ts'

import { describe, expect, it } from 'vitest'
import { MIX_HEADROOM, mixLevel, scheduleEvents, voiceGain } from '../score/player.ts'
import { BUS_GAIN, envelopeAt, LIMIT_KNEE, LIMIT_RANGE, limiterCurve, RELEASE_TAIL, releaseStart } from '../score/synth.ts'

const RATE = 44100

/**
 * A played note.
 *
 * @param voice - voice member key
 * @param pitch - MIDI pitch
 * @param start - seconds
 * @param end - seconds
 * @param sound - false for a tied continuation
 */
function event(voice: string, pitch: number, start: number, end: number, sound = true): PlayEvent {
	return { id: `${voice}-${start}`, voice, pitch, start, end, sound, soundEnd: end, measure: 0 }
}

/**
 * Largest step of the envelope from one sample to the next.
 *
 * @param start - start of the note
 * @param end - end of the note
 * @param gain - loudness
 */
function largestStep(start: number, end: number, gain: number): number {
	let step = 0
	let previous = 0
	for (let i = 0; i < (end + RELEASE_TAIL + 0.1) * RATE; i++) {
		const value = envelopeAt(i / RATE, start, end, gain)
		step = Math.max(step, Math.abs(value - previous))
		previous = value
	}
	return step
}

describe('note envelope', () => {
	it('rises from 0, settles, and fades to silence without a jump', () => {
		const [start, end, gain] = [0.5, 1.5, 0.55]
		expect(envelopeAt(0.4, start, end, gain)).toBe(0)
		expect(envelopeAt(start, start, end, gain)).toBe(0)
		expect(envelopeAt(start + 0.035, start, end, gain)).toBeCloseTo(gain, 6)
		// settles below the peak, but keeps sounding until the release
		const held = envelopeAt(releaseStart(start, end) - 1e-6, start, end, gain)
		expect(held).toBeLessThan(gain)
		expect(held).toBeGreaterThan(gain * 0.7)
		// the release starts shortly before the end and is inaudible at the end of the tail
		expect(releaseStart(start, end)).toBeLessThan(end)
		expect(envelopeAt(releaseStart(start, end) + RELEASE_TAIL, start, end, gain)).toBeLessThan(gain * 0.001)
		// no step anywhere: the steepest part goes no faster than from 0 to the gain in 25 ms
		expect(largestStep(start, end, gain)).toBeLessThan(gain / (0.025 * RATE))
	})

	it('also for very short notes', () => {
		expect(releaseStart(0, 0.01)).toBeCloseTo(0.035)
		expect(largestStep(0, 0.01, 0.9)).toBeLessThan(0.9 / (0.025 * RATE))
	})
})

describe('soft limiter', () => {
	const curve = limiterCurve()
	const inputOf = (i: number) => (i / (curve.length - 1) * 2 - 1) * LIMIT_RANGE

	it('passes levels below the knee unchanged', () => {
		for (let i = 0; i < curve.length; i++) {
			if (Math.abs(inputOf(i)) <= LIMIT_KNEE) {
				expect(curve[i]).toBeCloseTo(inputOf(i), 6)
			}
		}
		expect(curve[(curve.length - 1) / 2]).toBe(0)
	})

	it('bends louder peaks smoothly below full scale', () => {
		for (let i = 1; i < curve.length; i++) {
			expect(curve[i]).toBeGreaterThan(curve[i - 1])
			expect(curve[i]).toBeCloseTo(-curve[curve.length - 1 - i], 6)
		}
		expect(curve[curve.length - 1]).toBeLessThan(1)
		// no kink at the knee: the slope just above it is still close to 1
		const index = curve.findIndex((value, i) => inputOf(i) > LIMIT_KNEE)
		expect((curve[index + 1] - curve[index]) / (inputOf(index + 1) - inputOf(index))).toBeGreaterThan(0.99)
	})
})

describe('headroom of the mix', () => {
	const chord = (voices: number, gain: number) => (voice: string) => Number(voice.split('.')[0]) <= voices ? gain : 0
	const voices = (count: number, length = 1, notes = 3) => Array.from({ length: notes }, (_, n) =>
		Array.from({ length: count }, (_, v) => event(`${v + 1}.1`, 60 + v, n * length, (n + 1) * length))).flat()

	it('keeps four voices at full level and turns denser scores down', () => {
		const all = voiceGain('all', false, false)
		expect(4 * all).toBeLessThanOrEqual(MIX_HEADROOM)
		expect(mixLevel(voices(4), chord(4, all))).toBe(1)
		expect(mixLevel(voices(8), chord(8, all))).toBeCloseTo(MIX_HEADROOM / (8 * all))
		// four voices at full level and the default volume (0.7) stay below the limiter's knee
		expect(4 * all * BUS_GAIN * 0.7).toBeLessThan(LIMIT_KNEE)
	})

	it('counts only notes that sound at the same time', () => {
		// back to back: 0.2 + 0.1 is a little more than 0.3, but the notes do not overlap
		const fast = [0, 0.1, 0.2, 0.3].map((start) => [1, 2, 3, 4, 5, 6].map((v) => event(`${v}.1`, 60, start, start + 0.1))).flat()
		expect(0.2 + 0.1).toBeGreaterThan(0.3)
		expect(mixLevel(fast, () => 0.4)).toBe(1)
		// tied continuations and silent voices do not count
		const tied = [...voices(4), ...voices(4).map((e) => ({ ...e, sound: false }))]
		expect(mixLevel(tied, chord(4, 0.55))).toBe(1)
		expect(mixLevel(voices(8), chord(4, 0.55))).toBe(1)
	})

	it('keeps the balance of the voices', () => {
		const events = voices(8)
		expect(mixLevel(events, (voice: string) => voiceGain('focus', voice === '1.1', true))).toBe(1)
		const dense = (voice: string) => (voice === '1.1' ? 0.9 : 0.5)
		const scaled: number[] = []
		scheduleEvents({ note: (_pitch, _start, _end, gain) => scaled.push(gain) }, events, 0, { startAudio: 0, startScore: 0, rate: 1 }, Infinity, 0, dense)
		expect(Math.max(...scaled) / Math.min(...scaled)).toBeCloseTo(0.9 / 0.5)
		expect(Math.max(...scaled)).toBeCloseTo(0.9 * MIX_HEADROOM / (0.9 + 7 * 0.5))
	})
})

describe('late scheduling', () => {
	it('leaves out notes that are already over and starts the others now', () => {
		const events = [event('1.1', 60, 0, 1), event('1.1', 62, 1, 3)]
		const notes: { pitch: number, start: number }[] = []
		scheduleEvents({ note: (pitch, start) => notes.push({ pitch, start }) }, events, 0, { startAudio: 0, startScore: 0, rate: 1 }, 5, 2, () => 0.55)
		expect(notes).toEqual([{ pitch: 62, start: 2 }])
	})
})
