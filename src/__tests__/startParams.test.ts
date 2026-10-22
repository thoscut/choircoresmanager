/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { buildQuery, DEFAULT_VIEW, isMissingCopy, parseStartParams, viewFromParam } from '../startParams.ts'

const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345'

describe('parseStartParams', () => {
	it('reads copy and task of a task notification', () => {
		expect(parseStartParams('?copy=35&task=4')).toEqual({ view: null, copyId: 35, score: false, taskId: 4, eventId: null, linkToken: null, dueKey: null })
	})

	it('reads the proposals view', () => {
		expect(parseStartParams('view=proposals').view).toBe('proposals')
	})

	it('reads the checksum view of a notification', () => {
		expect(parseStartParams('?view=checksums').view).toBe('checksums')
		expect(parseStartParams('?view=protection-review').view).toBe('protection')
	})

	it('maps view names with dashes', () => {
		expect(parseStartParams('?view=print-station').view).toBe('printStation')
		expect(parseStartParams('?view=song-lists').view).toBe('songLists')
	})

	it('opens the event view for ?event=', () => {
		const params = parseStartParams('?event=12&view=tasks')
		expect(params.view).toBe('event')
		expect(params.eventId).toBe(12)
	})

	it('accepts only 32 alphanumeric characters as link token', () => {
		expect(parseStartParams(`?link=${TOKEN}`).linkToken).toBe(TOKEN)
		expect(parseStartParams('?link=short').linkToken).toBeNull()
		expect(parseStartParams(`?link=${TOKEN.slice(0, 31)}-`).linkToken).toBeNull()
	})

	it('ignores invalid numbers and unknown views', () => {
		expect(parseStartParams('?copy=abc&task=-1&event=0&view=nonsense')).toEqual({
			view: null, copyId: null, score: false, taskId: null, eventId: null, linkToken: null, dueKey: null,
		})
	})

	it('opens the view of a start page card for ?due= (dashboard widget)', () => {
		expect(parseStartParams('?due=tasks_open')).toMatchObject({ view: 'tasks', dueKey: 'tasks_open' })
		expect(parseStartParams('?due=confirm_current')).toMatchObject({ view: 'catalog', dueKey: 'confirm_current' })
		expect(parseStartParams('?due=proposals&view=tasks')).toMatchObject({ view: 'proposals', dueKey: 'proposals' })
	})

	it('ignores invalid card keys and lets ?event= win', () => {
		expect(parseStartParams('?due=Tasks-Open')).toMatchObject({ view: null, dueKey: null })
		expect(parseStartParams(`?due=${'a'.repeat(41)}`).dueKey).toBeNull()
		expect(parseStartParams('?due=tasks_open&event=4')).toMatchObject({ view: 'event', eventId: 4, dueKey: null })
	})

	it('opens the score view of a copy for ?score=1 (Files app)', () => {
		expect(parseStartParams('?copy=35&score=1')).toMatchObject({ view: null, copyId: 35, score: true })
		expect(parseStartParams('?copy=35')).toMatchObject({ copyId: 35, score: false })
	})

	it('ignores ?score= without a copy or with another value', () => {
		expect(parseStartParams('?score=1').score).toBe(false)
		expect(parseStartParams('?copy=abc&score=1').score).toBe(false)
		expect(parseStartParams('?copy=35&score=true').score).toBe(false)
		expect(parseStartParams('?copy=35&score=0').score).toBe(false)
	})

	it('treats an empty query as nothing', () => {
		expect(parseStartParams('')).toEqual({ view: null, copyId: null, score: false, taskId: null, eventId: null, linkToken: null, dueKey: null })
	})
})

describe('viewFromParam', () => {
	it('is case-insensitive and trims', () => {
		expect(viewFromParam(' Portal ')).toBe('portal')
		expect(viewFromParam(null)).toBeNull()
	})
})

describe('buildQuery', () => {
	it('leaves the start view out', () => {
		expect(buildQuery({ view: DEFAULT_VIEW })).toBe('')
	})

	it('writes view and copy', () => {
		expect(buildQuery({ view: 'printStation', copyId: 7 })).toBe('?view=print-station&copy=7')
	})

	it('writes score=1 only with a copy', () => {
		expect(buildQuery({ view: DEFAULT_VIEW, copyId: 35, score: true })).toBe('?copy=35&score=1')
		expect(buildQuery({ view: DEFAULT_VIEW, score: true })).toBe('')
		expect(parseStartParams(buildQuery({ view: 'catalog', copyId: 35, score: true }))).toMatchObject({ view: 'catalog', copyId: 35, score: true })
	})

	it('writes the event view as ?event=', () => {
		expect(buildQuery({ view: 'event', eventId: 3 })).toBe('?event=3')
	})

	it('round-trips through parseStartParams', () => {
		for (const view of ['catalog', 'tasks', 'proposals', 'portal', 'printStation', 'concerts', 'songLists', 'ambiguous', 'links', 'licenses', 'accession', 'persons', 'protection', 'merges', 'inventory', 'checksums'] as const) {
			expect(parseStartParams(buildQuery({ view })).view).toBe(view)
		}
	})
})

describe('isMissingCopy', () => {
	const catalog = [{ id: 3 }, { id: 35 }]

	it('opens a copy of the catalog', () => {
		expect(isMissingCopy(35, catalog)).toBe(false)
	})

	it('reports a copy that is not in the catalog', () => {
		expect(isMissingCopy(999999, catalog)).toBe(true)
		expect(isMissingCopy(1, [])).toBe(true)
	})

	it('cannot tell without a loaded catalog', () => {
		expect(isMissingCopy(999999, null)).toBe(false)
	})
})
