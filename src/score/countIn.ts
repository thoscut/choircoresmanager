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
 * The number of bars is remembered per person in the browser.
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
	const index = slotAt(timeline.measures, start)
	const slot = timeline.measures[index]
	if (bars <= 0 || !slot) {
		return null
	}
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
	const anchor = short ? slot.end : slot.start

	// Beats: the beat unit of the signature, in compound time the dotted one
	const [beats, unit] = signature ?? [4, 4]
	const unitQuarters = beats % 3 === 0 && beats > 3 && unit >= 8 ? 12 / unit : 4 / unit
	const beatsPerBar = Math.max(1, Math.round(barQuarters / unitQuarters))
	const beatSeconds = barQuarters / beatsPerBar * quarterSeconds
	if (!Number.isFinite(beatSeconds) || beatSeconds <= 0.05) {
		return null
	}

	const first = anchor - bars * beatsPerBar * beatSeconds
	const clicks: Click[] = []
	for (let k = 0; first + k * beatSeconds < start - EPSILON && k < 64; k++) {
		clicks.push({ time: first + k * beatSeconds - start, accent: k % beatsPerBar === 0 })
	}
	if (clicks.length === 0) {
		return null
	}
	return { clicks, lead: -clicks[0].time, quarterSeconds, barQuarters }
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
