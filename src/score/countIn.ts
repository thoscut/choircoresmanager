/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Count-in before the playback and at the start of practice files: one or
 * two bars of clicks in the time signature and tempo of the measure where
 * the playback starts. A pickup comes in on the last beats of the count-in;
 * starting in the middle of a measure, the clicks run on to that point.
 *
 * The number of bars is remembered per person in the browser. The same
 * beats drive the metronome during the playback (milestone 0.13).
 */
import type { ScoreModel } from './model.ts'
import type { MeasureSlot, Timeline } from './timeline.ts'

/** Bars of count-in; 0 = none. */
export type CountInBars = 0 | 1 | 2

export interface Click {
	/** seconds at 100 % tempo, relative to the start of the music (negative) */
	time: number
	/** first beat of a bar */
	accent: boolean
}

export interface CountIn {
	clicks: Click[]
	/** seconds at 100 % tempo from the first click to the start of the music */
	lead: number
	/** seconds of a quarter note at 100 % tempo */
	quarterSeconds: number
	/** length of a count-in bar in quarter notes */
	barQuarters: number
}

const EPSILON = 1e-6
const STORAGE_KEY = 'notenarchiv.countIn'

/**
 * Measure slot that contains a time.
 *
 * @param slots - measure slots of the timeline
 * @param time - seconds at 100 % tempo
 */
export function slotAt(slots: MeasureSlot[], time: number): number {
	let found = -1
	for (let i = 0; i < slots.length && slots[i].start <= time + EPSILON; i++) {
		found = i
	}
	return found
}

/**
 * The written time signature, e.g. "6/8" → [6, 8].
 *
 * @param time - from the score's signatures
 */
function written(time: string): [number, number] | null {
	const match = time.match(/^(\d+)\/(\d+)$/)
	const beats = Number(match?.[1])
	const unit = Number(match?.[2])
	return match && beats >= 1 && unit >= 1 ? [beats, unit] : null
}

/** Beats of one measure: the grid the count-in and the metronome click on. */
export interface BeatGrid {
	/** seconds of a quarter note at 100 % tempo */
	quarterSeconds: number
	/** length of a full bar in quarter notes */
	barQuarters: number
	beatsPerBar: number
	/** seconds of a beat at 100 % tempo */
	beatSeconds: number
	/** a measure shorter than the time signature (pickup): its beats are counted towards its end */
	short: boolean
}

/**
 * The beats of a measure slot in its time signature and tempo.
 *
 * @param model - the score (timing, time signature, tempo)
 * @param slot - the measure
 * @return the grid, or null when the beats would be too fast to click
 */
export function beatGrid(model: Pick<ScoreModel, 'timing' | 'signatures' | 'quarterTempo'>, slot: MeasureSlot): BeatGrid | null {
	const seconds = slot.end - slot.start
	const span = model.timing.quarters?.[slot.index]
	const tempo = model.timing.tempo ?? model.quarterTempo ?? 120
	const quarters = span ? span.end - span.start : seconds * tempo / 60
	const quarterSeconds = quarters > EPSILON && seconds > EPSILON ? seconds / quarters : 60 / tempo
	const signature = written(model.signatures.time)
	const signatureQuarters = signature ? signature[0] * 4 / signature[1] : null

	// A short measure (pickup, or the rest of a measure split by a repeat)
	// ends on a downbeat: the count-in is counted towards its end
	const short = signatureQuarters !== null && quarters < signatureQuarters - EPSILON
	const barQuarters = short || quarters <= EPSILON ? (signatureQuarters ?? 4) : quarters

	// Beats: the beat unit of the signature, in compound time the dotted one
	const [beats, unit] = signature ?? [4, 4]
	const unitQuarters = beats % 3 === 0 && beats > 3 && unit >= 8 ? 12 / unit : 4 / unit
	const beatsPerBar = Math.max(1, Math.round(barQuarters / unitQuarters))
	const beatSeconds = barQuarters / beatsPerBar * quarterSeconds
	if (!Number.isFinite(beatSeconds) || beatSeconds <= 0.05) {
		return null
	}
	return { quarterSeconds, barQuarters, beatsPerBar, beatSeconds, short }
}

/**
 * Clicks before the playback starts at a time of the timeline.
 *
 * @param model - the score (timing, time signature, tempo)
 * @param timeline - playback events
 * @param start - seconds at 100 % tempo where the music starts
 * @param bars - bars of count-in
 * @return the count-in, or null for none
 */
export function countInFor(model: Pick<ScoreModel, 'timing' | 'signatures' | 'quarterTempo'>, timeline: Timeline, start: number, bars: number): CountIn | null {
	const slot = timeline.measures[slotAt(timeline.measures, start)]
	const grid = bars > 0 && slot ? beatGrid(model, slot) : null
	if (!slot || !grid) {
		return null
	}
	const anchor = grid.short ? slot.end : slot.start
	const first = anchor - bars * grid.beatsPerBar * grid.beatSeconds
	const clicks: Click[] = []
	for (let k = 0; first + k * grid.beatSeconds < start - EPSILON && k < 64; k++) {
		clicks.push({ time: first + k * grid.beatSeconds - start, accent: k % grid.beatsPerBar === 0 })
	}
	if (clicks.length === 0) {
		return null
	}
	return { clicks, lead: -clicks[0].time, quarterSeconds: grid.quarterSeconds, barQuarters: grid.barQuarters }
}

/**
 * The clicks of a metronome along the whole timeline, in playing order:
 * every beat of every measure, the first beat of a bar higher. A short
 * measure (pickup) gets the beats before its end, without an accent.
 *
 * @param model - the score (timing, time signature, tempo)
 * @param timeline - playback events
 * @return clicks in seconds of the timeline at 100 % tempo
 */
export function metronomeClicks(model: Pick<ScoreModel, 'timing' | 'signatures' | 'quarterTempo'>, timeline: Timeline): Click[] {
	const clicks: Click[] = []
	for (const slot of timeline.measures) {
		const grid = beatGrid(model, slot)
		if (!grid || slot.end - slot.start <= EPSILON) {
			continue
		}
		if (grid.short) {
			const beats: number[] = []
			for (let time = slot.end - grid.beatSeconds; time >= slot.start - EPSILON && beats.length < 64; time -= grid.beatSeconds) {
				beats.unshift(Math.max(slot.start, time))
			}
			clicks.push(...beats.map((time) => ({ time, accent: false })))
			continue
		}
		for (let k = 0; slot.start + k * grid.beatSeconds < slot.end - EPSILON && k < 64; k++) {
			clicks.push({ time: slot.start + k * grid.beatSeconds, accent: k % grid.beatsPerBar === 0 })
		}
	}
	return clicks
}

/**
 * The count-in chosen last in this browser.
 */
export function storedCountIn(): CountInBars {
	try {
		const value = window.localStorage.getItem(STORAGE_KEY)
		return value === '1' ? 1 : (value === '2' ? 2 : 0)
	} catch {
		return 0
	}
}

/**
 * Remember the count-in in this browser.
 *
 * @param bars - bars of count-in
 */
export function storeCountIn(bars: CountInBars): void {
	try {
		window.localStorage.setItem(STORAGE_KEY, String(bars))
	} catch {
		// private window or blocked storage: the choice is simply not remembered
	}
}
