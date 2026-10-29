/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Measure ranges in printed numbering: the `number` attribute of the
 * MusicXML measure. A pickup is measure 0 (or the first, implicit measure);
 * measures without a printed number (e.g. "X1" after a repeat inside a
 * measure) belong to a range when they lie between its ends.
 */

export interface ScoreMeasure {
	index: number
	/** MEI/SVG id */
	id: string
	/** printed number as in the file */
	number: string
	/** not counted (pickup or a measure split by a repeat) */
	implicit: boolean
}

export interface MeasureRange {
	/** first measure index */
	start: number
	/** last measure index (inclusive) */
	end: number
}

export type RangeError = 'from' | 'to' | 'order'

/**
 * Whether two printed measure numbers are the same ("07" = "7").
 *
 * @param a - number from the file
 * @param b - number entered by the user
 */
export function sameNumber(a: string, b: string): boolean {
	const x = a.trim()
	const y = b.trim()
	if (x === y) {
		return true
	}
	return /^\d+$/.test(x) && /^\d+$/.test(y) && Number(x) === Number(y)
}

/**
 * Index of the first measure with a printed number, at or after `from`.
 *
 * @param measures - all measures
 * @param number - printed number
 * @param from - first index to look at
 */
export function findMeasure(measures: ScoreMeasure[], number: string, from = 0): number {
	for (let i = Math.max(0, from); i < measures.length; i++) {
		if (sameNumber(measures[i].number, number)) {
			return i
		}
	}
	return -1
}

/**
 * Resolve a range "from–to" in printed numbers to measure indices. If a
 * number occurs more than once, the first occurrence (of "to": the first one
 * after "from") is taken.
 *
 * @param measures - all measures
 * @param from - printed number of the first measure
 * @param to - printed number of the last measure
 */
export function resolveRange(measures: ScoreMeasure[], from: string, to: string): MeasureRange | { error: RangeError } {
	const start = findMeasure(measures, from)
	if (start === -1) {
		return { error: 'from' }
	}
	const end = findMeasure(measures, to, start)
	if (end === -1) {
		return findMeasure(measures, to) === -1 ? { error: 'to' } : { error: 'order' }
	}
	return { start, end }
}

/**
 * Whether the score begins with a pickup (Auftakt).
 *
 * @param measures - all measures
 */
export function hasPickup(measures: ScoreMeasure[]): boolean {
	const first = measures[0]
	return first !== undefined && (first.implicit || first.number === '0')
}

/**
 * Printed numbers that occur more than once (implicit measures not counted).
 *
 * @param measures - all measures
 */
export function duplicateNumbers(measures: ScoreMeasure[]): string[] {
	const seen = new Set<string>()
	const duplicates = new Set<string>()
	for (const measure of measures) {
		if (measure.implicit) {
			continue
		}
		if (seen.has(measure.number)) {
			duplicates.add(measure.number)
		}
		seen.add(measure.number)
	}
	return [...duplicates]
}

/**
 * Playing order limited to a range. With repeats, the full playing order is
 * filtered to the range; without, the measures of the range are played once
 * each, as written.
 *
 * @param fullOrder - playing order of the whole piece (with repeats)
 * @param range - the range
 * @param repeats - whether to take repeats inside the range
 */
export function orderInRange(fullOrder: number[], range: MeasureRange, repeats: boolean): number[] {
	if (repeats) {
		return fullOrder.filter((index) => index >= range.start && index <= range.end)
	}
	const order: number[] = []
	for (let i = range.start; i <= range.end; i++) {
		order.push(i)
	}
	return order
}
