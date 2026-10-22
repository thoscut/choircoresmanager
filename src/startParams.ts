/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The views of the app and their representation in the URL. Notifications,
 * the dashboard widget, shared links and the Files app open the app with
 * query parameters (`?copy=`, `?score=1`, `?task=`, `?view=proposals`,
 * `?event=`, `?due=`, `?link=`, `?review=1` with a copy for the review of a
 * transcription); the
 * app also writes its current view into the URL so that the browser's back
 * button works. Pure functions, no Vue, easy to test.
 */
import { dueTarget } from './dueTargets.ts'

export type ViewName =
	| 'due'
	| 'catalog'
	| 'tasks'
	| 'proposals'
	| 'portal'
	| 'printStation'
	| 'concerts'
	| 'songLists'
	| 'event'
	| 'ambiguous'
	| 'links'
	| 'licenses'
	| 'accession'
	| 'persons'
	| 'protection'
	| 'statistics'
	| 'merges'
	| 'inventory'
	| 'checksums'

/** Value of `?view=` for each view (the event view is `?event={id}`). */
const VIEW_PARAMS: Record<Exclude<ViewName, 'event'>, string> = {
	due: 'due',
	catalog: 'catalog',
	tasks: 'tasks',
	proposals: 'proposals',
	portal: 'portal',
	printStation: 'print-station',
	concerts: 'concerts',
	songLists: 'song-lists',
	ambiguous: 'ambiguous',
	links: 'links',
	licenses: 'licenses',
	accession: 'accession',
	persons: 'persons',
	protection: 'protection-review',
	statistics: 'statistics',
	merges: 'merges',
	inventory: 'inventory',
	checksums: 'checksums',
}

export const DEFAULT_VIEW: ViewName = 'due'

export interface StartParams {
	view: ViewName | null
	copyId: number | null
	/** open the score view of the copy (`?copy=…&score=1`, from the Files app) */
	score: boolean
	taskId: number | null
	eventId: number | null
	linkToken: string | null
	/** card of the start page to open with its filters (dashboard widget) */
	dueKey: string | null
	/** open the score view of the copy with the review of its transcription */
	review: boolean
}

/** Where the app is: a view, optionally an event and an open copy. */
export interface Location {
	view: ViewName
	eventId?: number | null
	copyId?: number | null
	/** with the copy: open its score view (`score=1`) */
	score?: boolean
}

/**
 * A positive whole number from a query parameter, or null.
 *
 * @param value - raw parameter value
 */
function positiveId(value: string | null): number | null {
	if (value === null || !/^\d{1,10}$/.test(value.trim())) {
		return null
	}
	const id = Number.parseInt(value, 10)
	return id > 0 ? id : null
}

/**
 * View for a `?view=` value, or null for unknown values.
 *
 * @param value - raw parameter value
 */
export function viewFromParam(value: string | null): ViewName | null {
	if (value === null) {
		return null
	}
	const wanted = value.trim().toLowerCase()
	for (const [view, param] of Object.entries(VIEW_PARAMS)) {
		if (param === wanted) {
			return view as ViewName
		}
	}
	return null
}

/**
 * Read the query parameters the app understands. Invalid values are ignored.
 *
 * @param search - `location.search`, with or without "?"
 */
export function parseStartParams(search: string): StartParams {
	const params = new URLSearchParams(search.startsWith('?') ? search.slice(1) : search)
	const token = params.get('link')?.trim() ?? ''
	const eventId = positiveId(params.get('event'))
	const due = params.get('due')?.trim() ?? ''
	const dueKey = eventId === null && /^[a-z0-9_]{1,40}$/.test(due) ? due : null
	const copyId = positiveId(params.get('copy'))
	let view = viewFromParam(params.get('view'))
	if (eventId !== null) {
		view = 'event'
	} else if (dueKey !== null) {
		view = dueTarget(dueKey).view
	}
	return {
		view,
		copyId,
		score: copyId !== null && params.get('score')?.trim() === '1',
		taskId: positiveId(params.get('task')),
		eventId,
		linkToken: /^[A-Za-z0-9]{32}$/.test(token) ? token : null,
		dueKey,
		review: params.get('review')?.trim() === '1' && positiveId(params.get('copy')) !== null,
	}
}

/**
 * Whether the copy of a start link (`?copy=`, or the copy of `?task=`) is
 * not in the catalog: the app then says so instead of opening an empty
 * sidebar. Without a loaded catalog nothing can be said; the copy is opened
 * and the sidebar shows what the server answers.
 *
 * @param copyId - copy id from the URL
 * @param entries - the loaded catalog, or null if it is not loaded
 */
export function isMissingCopy(copyId: number, entries: readonly { id: number }[] | null): boolean {
	return entries !== null && !entries.some((entry) => entry.id === copyId)
}

/**
 * Query string ("?view=…") for a location; the start view gives "".
 *
 * @param location - view, event, copy and whether to open its score view
 */
export function buildQuery(location: Location): string {
	const params = new URLSearchParams()
	if (location.view === 'event') {
		if (location.eventId) {
			params.set('event', String(location.eventId))
		}
	} else if (location.view !== DEFAULT_VIEW) {
		params.set('view', VIEW_PARAMS[location.view])
	}
	if (location.copyId) {
		params.set('copy', String(location.copyId))
		if (location.score) {
			params.set('score', '1')
		}
	}
	const query = params.toString()
	return query === '' ? '' : '?' + query
}
