/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ScoreMeasure } from '../score/range.ts'
import type { ReviewPermissions, ReviewPoint } from '../types.ts'

import { describe, expect, it } from 'vitest'
import {
	allowedDecisions,
	canonicalVoice,
	filterPoints,
	isOpen,
	isUnresolved,
	matchVoice,
	mayDecide,
	parseMeasures,
	pointInRange,
	pointMeasures,
	pointPlace,
	pointRange,
	sortPoints,
} from '../review.ts'
import { buildVoices } from '../score/voices.ts'

/**
 * A review point with defaults.
 *
 * @param values - fields that differ from the defaults
 */
function point(values: Partial<ReviewPoint>): ReviewPoint {
	return {
		id: 1,
		version_id: 3,
		copy_id: 5,
		report_id: 4,
		file_id: 12,
		file_sha256: null,
		category: 'uncertain',
		measures: null,
		measure_from: null,
		measure_to: null,
		voice: null,
		verse: null,
		page: null,
		text: 'd or c',
		role: 'conductor',
		status: 'open',
		correction: null,
		decided_by: null,
		decided_by_name: null,
		decided: null,
		decision_reason: null,
		task_id: null,
		resolved_report_id: null,
		created_by: null,
		created: 0,
		...values,
	}
}

/** Measures 0 (pickup) to 16, plus an unnumbered measure after 8. */
const MEASURES: ScoreMeasure[] = [
	{ index: 0, id: 'm0', number: '0', implicit: true },
	...Array.from({ length: 8 }, (_, i) => ({ index: i + 1, id: `m${i + 1}`, number: String(i + 1), implicit: false })),
	{ index: 9, id: 'mx', number: 'X1', implicit: true },
	...Array.from({ length: 8 }, (_, i) => ({ index: i + 10, id: `m${i + 9}`, number: String(i + 9), implicit: false })),
]

/** Tenor I/II on one staff, Bass on a second staff (male choir). */
const TTB = buildVoices(
	[{ name: 'Tenor I/II', staves: [1] }, { name: 'Bass', staves: [2] }],
	new Map([['1.1', 10], ['1.2', 9], ['2.1', 12]]),
)

/** Soprano, alto, tenor and two basses. */
const SATBB = buildVoices(
	[{ name: 'Sopran', staves: [1] }, { name: 'Alt', staves: [2] }, { name: 'Tenor', staves: [3] }, { name: 'Bass 1/2', staves: [4] }],
	new Map([['1.1', 1], ['2.1', 1], ['3.1', 1], ['4.1', 1], ['4.2', 1]]),
)

const LIBRARIAN: ReviewPermissions = { decideMusical: true, decideMaterial: true, ingest: true, correct: true }
const CONDUCTOR: ReviewPermissions = { decideMusical: true, decideMaterial: false, ingest: false, correct: true }
const READER: ReviewPermissions = { decideMusical: false, decideMaterial: false, ingest: false, correct: false }

describe('voices of review points', () => {
	it('normalizes the ways reports name a voice', () => {
		for (const text of ['T1', 'T I', 'T 1', 'Tenor 1', 'Tenor I', '1. Tenor', 'TI', 'tenor i.']) {
			expect(canonicalVoice(text), text).toBe('tenor 1')
		}
		expect(canonicalVoice('T II')).toBe('tenor 2')
		expect(canonicalVoice('B')).toBe('bass')
		expect(canonicalVoice('Bass')).toBe('bass')
		expect(canonicalVoice('Bar')).toBe('bariton')
		expect(canonicalVoice('S')).toBe('sopran')
		expect(canonicalVoice('Alto')).toBe('alt')
	})

	it('reads "all voices" and nothing as no voice', () => {
		for (const text of ['alle', 'Alle', 'all', 'tutti', '', '  ', null, undefined]) {
			expect(canonicalVoice(text), String(text)).toBeNull()
		}
	})

	it('finds the voice of the score, also in "Tenor I/II" on one staff', () => {
		expect(matchVoice(TTB, 'T2')?.key).toBe('1.2')
		expect(matchVoice(TTB, 'Tenor I')?.key).toBe('1.1')
		expect(matchVoice(TTB, '1. Tenor')?.key).toBe('1.1')
		expect(matchVoice(TTB, 'B')?.key).toBe('2.1')
		expect(matchVoice(TTB, 'alle')).toBeNull()
		expect(matchVoice(TTB, 'Sopran')).toBeNull()
	})

	it('takes only a unique match', () => {
		// two tenors: "T" names neither of them for sure
		expect(matchVoice(TTB, 'T')).toBeNull()
		// two basses: "B" is not unique, "B2" is
		expect(matchVoice(SATBB, 'B')).toBeNull()
		expect(matchVoice(SATBB, 'B2')?.key).toBe('4.2')
		// one tenor voice: "T1" can only mean it
		expect(matchVoice(SATBB, 'T1')?.key).toBe('3.1')
		expect(matchVoice(SATBB, 'A')?.key).toBe('2.1')
	})

	it('uses the display name of a voice without a name', () => {
		const voices = buildVoices([{ name: '', staves: [1] }], new Map([['1.1', 3]]))
		expect(matchVoice(voices, 'Chorus', () => 'Chorus')?.key).toBe('1.1')
	})
})

describe('measures of review points', () => {
	it('reads measure texts', () => {
		expect(parseMeasures('23')).toEqual({ from: '23', to: '23' })
		expect(parseMeasures('12–14')).toEqual({ from: '12', to: '14' })
		expect(parseMeasures('12-14')).toEqual({ from: '12', to: '14' })
		expect(parseMeasures('T. 12–14')).toEqual({ from: '12', to: '14' })
		expect(parseMeasures('Takt 5')).toEqual({ from: '5', to: '5' })
		expect(parseMeasures('12, 14')).toEqual({ from: '12', to: '14' })
		expect(parseMeasures('12 bis 14')).toEqual({ from: '12', to: '14' })
		expect(parseMeasures('')).toBeNull()
		expect(parseMeasures(null)).toBeNull()
		expect(parseMeasures('<script>')).toBeNull()
	})

	it('prefers measure_from and measure_to', () => {
		expect(pointMeasures(point({ measure_from: '12', measure_to: '14', measures: '1' }))).toEqual({ from: '12', to: '14' })
		expect(pointMeasures(point({ measure_from: '7' }))).toEqual({ from: '7', to: '7' })
		expect(pointMeasures(point({ measures: 'T. 3' }))).toEqual({ from: '3', to: '3' })
		expect(pointMeasures(point({ category: 'material' }))).toBeNull()
	})

	it('finds the measures in the score, with printed numbers', () => {
		expect(pointRange(point({ measure_from: '0', measure_to: '2' }), MEASURES)).toEqual({ start: 0, end: 2 })
		expect(pointRange(point({ measure_from: '8', measure_to: '9' }), MEASURES)).toEqual({ start: 8, end: 10 })
		expect(pointRange(point({ measure_from: '99' }), MEASURES)).toBeNull()
		expect(pointRange(point({}), MEASURES)).toBeNull()
	})

	it('tells whether a point lies in a range', () => {
		expect(pointInRange(point({ measure_from: '3', measure_to: '5' }), MEASURES, { start: 5, end: 6 })).toBe(true)
		expect(pointInRange(point({ measure_from: '3' }), MEASURES, { start: 4, end: 6 })).toBe(false)
		expect(pointInRange(point({}), MEASURES, { start: 0, end: 17 })).toBe(false)
	})

	it('describes the place', () => {
		expect(pointPlace(point({ measure_from: '12', measure_to: '14', voice: 'B' }))).toBe('Measures 12 to 14, B')
		expect(pointPlace(point({ measure_from: '23', voice: 'alle' }))).toBe('Measure 23, all voices')
		expect(pointPlace(point({ category: 'material' }))).toBe('No place in the score')
	})
})

describe('order and decisions', () => {
	const points = [
		point({ id: 1, status: 'confirmed', measure_from: '2' }),
		point({ id: 2, status: 'open', measure_from: '14' }),
		point({ id: 3, status: 'correct', measure_from: '3' }),
		point({ id: 4, status: 'open', category: 'material', role: 'librarian' }),
		point({ id: 5, status: 'question', measure_from: '1' }),
		point({ id: 6, status: 'dismissed', measure_from: '1' }),
	]

	it('puts what waits first, by measure, material last', () => {
		expect(sortPoints(points).map((item) => item.id)).toEqual([5, 3, 2, 4, 1, 6])
	})

	it('filters what still waits for someone', () => {
		expect(filterPoints(points, 'open').map((item) => item.id)).toEqual([5, 3, 2, 4])
		expect(filterPoints(points, 'all')).toHaveLength(6)
		expect(isUnresolved(point({ status: 'correct' }))).toBe(true)
		expect(isOpen(point({ status: 'correct' }))).toBe(false)
		expect(isOpen(point({ status: 'question' }))).toBe(true)
	})

	it('lets the librarian decide everything, the conductor the musical points', () => {
		expect(mayDecide(point({ role: 'librarian' }), LIBRARIAN)).toBe(true)
		expect(mayDecide(point({ role: 'conductor' }), LIBRARIAN)).toBe(true)
		expect(mayDecide(point({ role: 'conductor' }), CONDUCTOR)).toBe(true)
		expect(mayDecide(point({ role: 'librarian' }), CONDUCTOR)).toBe(false)
		expect(mayDecide(point({ role: 'conductor' }), READER)).toBe(false)
	})

	it('offers the decisions that change something, never "corrected"', () => {
		expect(allowedDecisions(point({ status: 'open' }), CONDUCTOR, true)).toEqual(['confirmed', 'correct', 'question', 'dismissed'])
		expect(allowedDecisions(point({ status: 'open' }), CONDUCTOR, false)).toEqual(['confirmed', 'correct', 'dismissed'])
		expect(allowedDecisions(point({ status: 'correct' }), LIBRARIAN, true)).toEqual(['confirmed', 'question', 'dismissed', 'open'])
		expect(allowedDecisions(point({ status: 'corrected' }), LIBRARIAN, true)).toEqual(['confirmed', 'correct', 'question', 'dismissed', 'open'])
		expect(allowedDecisions(point({ role: 'librarian' }), CONDUCTOR, true)).toEqual([])
	})
})
