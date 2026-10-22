import { describe, expect, it } from 'vitest'
import { buildQuery, DEFAULT_VIEW, isMissingCopy, parseStartParams, viewFromParam } from '../startParams.ts'

const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345'

describe('parseStartParams', () => {
	it('reads copy and task of a task notification', () => {
		expect(parseStartParams('?copy=35&task=4')).toEqual({ view: null, copyId: 35, taskId: 4, eventId: null, linkToken: null })
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
			view: null, copyId: null, taskId: null, eventId: null, linkToken: null,
		})
	})

	it('treats an empty query as nothing', () => {
		expect(parseStartParams('')).toEqual({ view: null, copyId: null, taskId: null, eventId: null, linkToken: null })
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
