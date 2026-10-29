/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Snapshot, SnapshotComparison } from '../archiveExtras.ts'

import { describe, expect, it } from 'vitest'
import { defaultPair, differenceCount, rangesText } from '../archiveExtras.ts'
import { fieldActive, VIEW_MODULES, viewActive } from '../modules.ts'
import { buildQuery, parseStartParams } from '../startParams.ts'

/**
 * @param name - folder name
 * @param complete - all files present
 */
function snapshot(name: string, complete = true): Snapshot {
	return { name, year: Number.parseInt(name.slice(0, 4), 10), created: '2026-10-22 12:00:00', complete }
}

describe('gaps in the shelf marks', () => {
	it('writes ranges as shelf marks', () => {
		expect(rangesText(5, [{ from: 1, to: 2 }, { from: 7, to: 7 }])).toBe('5-1 – 5-2, 5-7')
		expect(rangesText(1, [])).toBe('')
	})
})

describe('annual snapshots', () => {
	it('compares the newest snapshot with the newest of the year before', () => {
		const list = [snapshot('2027_20270101-010000'), snapshot('2026_20261022-120000'), snapshot('2026_20260101-010000'), snapshot('2025_20250101-010000')]
		expect(defaultPair(list)).toEqual({ older: '2026_20261022-120000', newer: '2027_20270101-010000' })
	})

	it('takes the snapshot before when there is no earlier year, and skips incomplete ones', () => {
		expect(defaultPair([snapshot('2026_20261022-120000'), snapshot('2026_20260301-120000', false), snapshot('2026_20260101-010000')]))
			.toEqual({ older: '2026_20260101-010000', newer: '2026_20261022-120000' })
		expect(defaultPair([snapshot('2026_20261022-120000')])).toBeNull()
		expect(defaultPair([])).toBeNull()
	})

	it('counts the differences', () => {
		const comparison: SnapshotComparison = {
			older: snapshot('2025_20250101-010000'),
			newer: snapshot('2026_20260101-010000'),
			copies: { added: [{ shelfmark: '1-1', title: null }], removed: [], changed: [] },
			rights: [{ shelfmark: '1-1', title: null, changes: [] }],
			licenses: [],
			files: { added: [], removed: [{ path: 'a.pdf', size: 1 }], changed: [], compared: true },
		}
		expect(differenceCount(comparison)).toBe(3)
	})
})

describe('views and fields of milestone 0.8', () => {
	it('belong to their modules', () => {
		expect(VIEW_MODULES.gaps).toBe('inventory')
		expect(VIEW_MODULES.snapshots).toBe('annual_snapshot')
		expect(viewActive('snapshots', { annual_snapshot: false })).toBe(false)
		expect(viewActive('gaps', { inventory: true })).toBe(true)
		expect(fieldActive('scientific_edition', { protection_terms: false })).toBe(false)
		expect(fieldActive('first_published_year', { protection_terms: true })).toBe(true)
	})

	it('have their place in the URL', () => {
		expect(buildQuery({ view: 'gaps' })).toBe('?view=shelfmark-gaps')
		expect(buildQuery({ view: 'snapshots' })).toBe('?view=annual-snapshots')
		expect(parseStartParams('?view=annual-snapshots').view).toBe('snapshots')
	})
})
