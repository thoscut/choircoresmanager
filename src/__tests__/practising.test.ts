/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LinearTiming, PlayEvent, Timeline } from '../score/timeline.ts'
import type { Voice } from '../score/voices.ts'

import { describe, expect, it } from 'vitest'
import { metronomeClicks } from '../score/countIn.ts'
import { scheduleBeats, voiceGains } from '../score/player.ts'
import {
	midiPitchName,
	mixChanged,
	mixedGains,
	startingTones,
	toneTimes,
	transposedFifths,
	transposeTimeline,
	TONE_HOLD,
	TONE_STEP,
} from '../score/practising.ts'

const SOPRANO: Voice = { key: '1.1', name: 'Sopran', part: 'Sopran', number: 1, members: [{ staff: 1, layer: 1 }], notes: 2 }
const ALTO: Voice = { key: '1.2', name: 'Alt', part: 'Alt', number: 1, members: [{ staff: 1, layer: 2 }], notes: 2 }
const BASS: Voice = { key: '2.1+3.1', name: 'Bass', part: 'Bass', number: 1, members: [{ staff: 2, layer: 1 }, { staff: 3, layer: 1 }], notes: 2 }

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
	return { id: `${voice}-${pitch}-${start}`, voice, pitch, start, end, sound, soundEnd: end, measure: Math.floor(start) }
}

/**
 * A score of measures with the given lengths in quarter notes, at a tempo.
 *
 * @param quarters - length of every measure in quarter notes
 * @param time - written time signature
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

describe('mixer', () => {
	it('scales the gain of the mode by the level of each voice', () => {
		const base = voiceGains([], 'all')
		const gainOf = mixedGains(base, [SOPRANO, ALTO, BASS], { 1.2: 50, '2.1+3.1': 0 })
		expect(gainOf('1.1')).toBeCloseTo(0.55)
		expect(gainOf('1.2')).toBeCloseTo(0.275)
		expect(gainOf('2.1')).toBe(0)
		expect(gainOf('3.1')).toBe(0)
		// a key without a voice keeps the gain of the mode
		expect(gainOf('4.1')).toBeCloseTo(0.55)
	})

	it('keeps the gain function of the mode when nothing is changed', () => {
		const base = voiceGains(SOPRANO.members, 'focus')
		expect(mixedGains(base, [SOPRANO, ALTO], {})).toBe(base)
		expect(mixedGains(base, [SOPRANO, ALTO], { 1.1: 100 })).toBe(base)
		expect(mixChanged({})).toBe(false)
		expect(mixChanged({ 1.1: 100 })).toBe(false)
		expect(mixChanged({ 1.1: 90 })).toBe(true)
	})

	it('works on top of "Focus voice only"', () => {
		const gainOf = mixedGains(voiceGains(ALTO.members, 'solo'), [SOPRANO, ALTO], { 1.2: 50 })
		expect(gainOf('1.1')).toBe(0)
		expect(gainOf('1.2')).toBeCloseTo(0.4)
	})
})

describe('transposing', () => {
	const timeline: Timeline = { events: [note('1.1', 72, 0, 1), note('1.1', 126, 1, 2)], measures: [{ index: 0, start: 0, end: 2 }], duration: 2 }

	it('moves every pitch and keeps the times', () => {
		const up = transposeTimeline(timeline, 2)
		expect(up.events.map((event) => event.pitch)).toEqual([74, 127])
		expect(up.events.map((event) => event.start)).toEqual([0, 1])
		expect(up.measures).toBe(timeline.measures)
		expect(transposeTimeline(timeline, -3).events[0].pitch).toBe(69)
		// the original stays as it is
		expect(timeline.events[0].pitch).toBe(72)
	})

	it('returns the same timeline for 0 and limits the steps', () => {
		expect(transposeTimeline(timeline, 0)).toBe(timeline)
		expect(transposeTimeline(timeline, 20).events[0].pitch).toBe(78)
	})

	it('moves the key signature', () => {
		expect(transposedFifths(0, 1)).toBe(-5)
		expect(transposedFifths(0, 2)).toBe(2)
		expect(transposedFifths(-1, -2)).toBe(-3)
		expect(transposedFifths(2, -2)).toBe(0)
		expect(transposedFifths(0, 6)).toBe(6)
		expect(transposedFifths(3, 0)).toBe(3)
	})
})

describe('names of pitches', () => {
	it('spells with sharps or flats after the key', () => {
		expect(midiPitchName(60, 0, false)).toBe('C4')
		expect(midiPitchName(61, 2, false)).toBe('C♯4')
		expect(midiPitchName(61, -3, false)).toBe('D♭4')
		expect(midiPitchName(70, null, false)).toBe('A♯4')
		expect(midiPitchName(47, 0, false)).toBe('B2')
	})

	it('uses the German names', () => {
		expect(midiPitchName(66, 1, true)).toBe('Fis4')
		expect(midiPitchName(63, -3, true)).toBe('Es4')
		expect(midiPitchName(70, -1, true)).toBe('B4')
		expect(midiPitchName(71, 0, true)).toBe('H4')
	})
})

describe('starting tones', () => {
	const timeline: Timeline = {
		events: [
			note('1.1', 74, 0, 1),
			note('1.1', 76, 1, 2),
			note('1.2', 69, 1, 2),
			note('2.1', 50, 0, 2),
			note('3.1', 45, 2, 3),
		],
		measures: [{ index: 0, start: 0, end: 3 }],
		duration: 3,
	}

	it('gives the first tone of every voice, lowest first, a resting voice with its next tone', () => {
		const tones = startingTones(timeline, [SOPRANO, ALTO, BASS], 0)
		expect(tones.map((tone) => [tone.voice.key, tone.pitch])).toEqual([['2.1+3.1', 50], ['1.2', 69], ['1.1', 74]])
	})

	it('counts a tone that still sounds at the place', () => {
		const tones = startingTones(timeline, [SOPRANO, BASS], 1.5)
		expect(tones.map((tone) => tone.pitch)).toEqual([50, 76])
	})

	it('leaves out voices that do not sing any more and tied continuations', () => {
		const tied: Timeline = { ...timeline, events: [note('1.1', 74, 0, 1), note('1.1', 74, 1, 2, false), note('1.1', 77, 2, 3)] }
		expect(startingTones(tied, [SOPRANO], 1).map((tone) => tone.pitch)).toEqual([77])
		expect(startingTones(timeline, [ALTO], 2.5)).toEqual([])
	})

	it('builds the chord up from the lowest tone', () => {
		expect(toneTimes(3)).toEqual({ starts: [0, TONE_STEP, 2 * TONE_STEP], end: 2 * TONE_STEP + TONE_HOLD })
		expect(toneTimes(0)).toEqual({ starts: [], end: 0 })
	})
})

describe('metronome', () => {
	/**
	 * Clicks as "seconds" with "!" on the first beat of a bar.
	 *
	 * @param list - the clicks
	 */
	function text(list: { time: number, accent: boolean }[]): string {
		return list.map((click) => `${click.accent ? '!' : ''}${click.time.toFixed(2)}`).join(' ')
	}

	it('clicks every beat of every measure', () => {
		const { model, timeline } = score([3, 3], '3/4', 60)
		expect(text(metronomeClicks(model, timeline))).toBe('!0.00 1.00 2.00 !3.00 4.00 5.00')
	})

	it('clicks the beats before the end of a pickup without an accent', () => {
		const { model, timeline } = score([1, 4], '4/4', 120)
		expect(text(metronomeClicks(model, timeline))).toBe('0.00 !0.50 1.00 1.50 2.00')
	})

	it('clicks the dotted beats of compound time', () => {
		const { model, timeline } = score([3], '6/8', 60)
		expect(text(metronomeClicks(model, timeline))).toBe('!0.00 1.50')
	})

	it('schedules the clicks up to the horizon and from the position on', () => {
		const clicks = [{ time: 0, accent: true }, { time: 1, accent: false }, { time: 2, accent: false }]
		const played: string[] = []
		const target = { click: (time: number, accent: boolean) => played.push(`${accent ? '!' : ''}${time.toFixed(2)}`) }
		const clock = { startAudio: 10, startScore: 0, rate: 2 }
		expect(scheduleBeats(target, clicks, 0, clock, 10.6, 10)).toBe(2)
		expect(played).toEqual(['!10.00', '10.50'])
		expect(scheduleBeats(target, clicks, 2, clock, 12, 10.7)).toBe(3)
		expect(played).toEqual(['!10.00', '10.50', '11.00'])
	})
})
