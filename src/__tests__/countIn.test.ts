/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { CountIn } from '../score/countIn.ts'
import type { LinearTiming, Timeline } from '../score/timeline.ts'

import { afterEach, describe, expect, it, vi } from 'vitest'
import { countInFor, slotAt, storeCountIn, storedCountIn } from '../score/countIn.ts'
import { scheduleClicks } from '../score/player.ts'
import { renderedSeconds } from '../score/practice.ts'
import { measureStarts, nextPass, noteStarts } from '../score/timeline.ts'

/**
 * A score of measures with the given lengths in quarter notes, at a tempo,
 * played straight through.
 *
 * @param quarters - length of every measure in quarter notes
 * @param time - written time signature, e.g. "3/4"
 * @param tempo - quarter notes per minute
 */
function score(quarters: number[], time: string, tempo: number): { model: { timing: LinearTiming, signatures: { fifths: null, mode: string, time: string }, quarterTempo: number }, timeline: Timeline } {
	const seconds = 60 / tempo
	let at = 0
	let quarter = 0
	const measures = quarters.map((length, index) => {
		const slot = { index, start: at, end: at + length * seconds }
		at = slot.end
		return slot
	})
	const spans = quarters.map((length) => {
		const span = { start: quarter, end: quarter + length }
		quarter = span.end
		return span
	})
	return {
		model: {
			timing: { measures: measures.map(({ start, end }) => ({ start: start * 1000, end: end * 1000 })), quarters: spans, notes: new Map(), tempo },
			signatures: { fifths: null, mode: '', time },
			quarterTempo: tempo,
		},
		timeline: { events: [], measures, duration: at },
	}
}

/**
 * Clicks as "beat@seconds" with "!" on the first beat of a bar.
 *
 * @param countIn - the count-in
 */
function clicks(countIn: CountIn | null): string {
	return (countIn?.clicks ?? []).map((click) => `${click.accent ? '!' : ''}${click.time.toFixed(2)}`).join(' ')
}

describe('count-in', () => {
	it('counts one or two bars of 4/4 in the tempo of the start', () => {
		const { model, timeline } = score([4, 4, 4], '4/4', 120)
		expect(clicks(countInFor(model, timeline, 0, 1))).toBe('!-2.00 -1.50 -1.00 -0.50')
		const two = countInFor(model, timeline, 0, 2)
		expect(two?.clicks).toHaveLength(8)
		expect(two?.lead).toBeCloseTo(4)
		expect(two?.quarterSeconds).toBeCloseTo(0.5)
		expect(two?.barQuarters).toBe(4)
		expect(countInFor(model, timeline, 0, 0)).toBeNull()
	})

	it('counts the dotted beats of compound time and the beats of 3/8', () => {
		const sixEight = score([3, 3], '6/8', 60)
		expect(clicks(countInFor(sixEight.model, sixEight.timeline, 0, 1))).toBe('!-3.00 -1.50')
		const threeEight = score([1.5, 1.5], '3/8', 60)
		expect(clicks(countInFor(threeEight.model, threeEight.timeline, 0, 1))).toBe('!-1.50 -1.00 -0.50')
	})

	it('lets a pickup come in on the last beats of the count-in', () => {
		const { model, timeline } = score([1, 3, 3], '3/4', 60)
		const countIn = countInFor(model, timeline, 0, 1)
		expect(clicks(countIn)).toBe('!-2.00 -1.00')
		expect(countIn?.barQuarters).toBe(3)
	})

	it('counts in the measure where the playback starts, also in its middle', () => {
		const { model, timeline } = score([4, 4, 4], '4/4', 120)
		// measure 2 starts at 2 s
		expect(clicks(countInFor(model, timeline, 2, 1))).toBe('!-2.00 -1.50 -1.00 -0.50')
		// resumed on the third beat of measure 2: one bar, then the first two beats
		expect(clicks(countInFor(model, timeline, 3, 1))).toBe('!-3.00 -2.50 -2.00 -1.50 !-1.00 -0.50')
	})

	it('counts towards the end of a short measure, as for a pickup', () => {
		const { model, timeline } = score([4, 2, 4], '4/4', 60)
		// the second measure is half a bar long (e.g. split by a repeat sign)
		expect(clicks(countInFor(model, timeline, 4, 1))).toBe('!-2.00 -1.00')
	})

	it('is played before the music at the chosen tempo', () => {
		const { model, timeline } = score([4], '4/4', 120)
		const countIn = countInFor(model, timeline, 0, 1)
		expect(countIn).not.toBeNull()
		const heard: string[] = []
		scheduleClicks({ click: (time, accent) => heard.push(`${accent ? '!' : ''}${time.toFixed(2)}`) }, countIn as CountIn, 10, 0.5)
		expect(heard.join(' ')).toBe('!6.00 7.00 8.00 9.00')
		// a rendered file is longer by the count-in at its tempo
		expect(renderedSeconds(timeline, 0.5, countIn?.lead) - renderedSeconds(timeline, 0.5)).toBeCloseTo(4)
	})

	it('finds the measure of a time', () => {
		const { timeline } = score([4, 4], '4/4', 120)
		expect(slotAt(timeline.measures, 0)).toBe(0)
		expect(slotAt(timeline.measures, 2)).toBe(1)
		expect(slotAt(timeline.measures, 1.99)).toBe(0)
		expect(slotAt(timeline.measures, -1)).toBe(-1)
	})
})

describe('count-in setting', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		window.localStorage.clear()
	})

	it('is remembered in the browser, off by default', () => {
		expect(storedCountIn()).toBe(0)
		storeCountIn(2)
		expect(storedCountIn()).toBe(2)
		window.localStorage.setItem('notenarchiv.countIn', 'nonsense')
		expect(storedCountIn()).toBe(0)
	})

	it('works without storage', () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
			throw new Error('blocked')
		})
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('blocked')
		})
		expect(() => storeCountIn(1)).not.toThrow()
		expect(storedCountIn()).toBe(0)
	})
})

describe('start at a note or a measure', () => {
	const timeline: Timeline = {
		events: [
			{ id: 'n1', voice: '1.1', pitch: 60, start: 0, end: 1, sound: true, soundEnd: 1, measure: 0 },
			{ id: 'n2', voice: '1.1', pitch: 62, start: 1, end: 2, sound: true, soundEnd: 2, measure: 1 },
			{ id: 'n2', voice: '1.1', pitch: 62, start: 3, end: 4, sound: true, soundEnd: 4, measure: 1 },
		],
		// measure 1 is played twice
		measures: [{ index: 0, start: 0, end: 1 }, { index: 1, start: 1, end: 2 }, { index: 2, start: 2, end: 3 }, { index: 1, start: 3, end: 4 }],
		duration: 4,
	}

	it('starts at the next pass from the current position, otherwise at the first', () => {
		expect(nextPass(noteStarts(timeline, 'n2'), 0)).toBe(1)
		expect(nextPass(noteStarts(timeline, 'n2'), 1.5)).toBe(3)
		expect(nextPass(noteStarts(timeline, 'n2'), 3.5)).toBe(1)
		expect(nextPass(measureStarts(timeline, 1), 2.5)).toBe(3)
		expect(nextPass(noteStarts(timeline, 'missing'), 0)).toBeNull()
	})
})
