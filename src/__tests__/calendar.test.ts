/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { CalendarAddResult, ChoirEvent } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { calendarMessage, davUrl, isFullDate, timeText, upcomingConcerts } from '../calendar.ts'

/**
 * A concert as returned by GET /api/events.
 *
 * @param values - fields to change
 */
function concert(values: Partial<ChoirEvent>): ChoirEvent {
	const base: ChoirEvent = {
		id: 1,
		kind: 'concert',
		title: 'Konzert',
		event_date: '2026-12-12',
		start_time: null,
		end_time: null,
		time_zone: null,
		location: null,
		period: null,
		status: 'draft',
		is_public: true,
		board_agreed: false,
		imported: false,
		source: null,
		notes: null,
		created: 0,
		modified: 0,
	}
	return { ...base, ...values }
}

/**
 * An answer of POST /api/events/{id}/calendar.
 *
 * @param values - fields to change
 */
function result(values: Partial<CalendarAddResult>): CalendarAddResult {
	return { calendar: 'Persönlich', concerts: 1, created: 0, updates: [], skipped: 0, failed: 0, ...values }
}

describe('upcomingConcerts', () => {
	it('keeps planned concerts with a full date from today on', () => {
		const events = [
			concert({ id: 1, event_date: '2026-10-21' }),
			concert({ id: 2, event_date: '2026-10-22' }),
			concert({ id: 3, event_date: '2027-01-06', status: 'cancelled' }),
			concert({ id: 4, event_date: '2026-12-24', imported: true }),
			concert({ id: 5, event_date: '2027' }),
			concert({ id: 6, kind: 'song_list', event_date: '2026-12-31' }),
			concert({ id: 7, event_date: null }),
		]
		expect(upcomingConcerts(events, '2026-10-22').map((event) => event.id)).toEqual([2, 3])
	})

	it('knows full dates', () => {
		expect(isFullDate('2026-12-12')).toBe(true)
		expect(isFullDate('2026')).toBe(false)
		expect(isFullDate(null)).toBe(false)
	})
})

describe('timeText', () => {
	it('shows start and end or only the start', () => {
		expect(timeText({ start_time: '19:30', end_time: '21:45' })).toBe('19:30–21:45')
		expect(timeText({ start_time: '19:30', end_time: null })).toBe('19:30')
		expect(timeText({ start_time: null, end_time: null })).toBe('')
	})
})

describe('davUrl', () => {
	it('joins the DAV root and the path from the server', () => {
		expect(davUrl('https://cloud.example.org/remote.php/dav', 'calendars/anna/personal/x.ics'))
			.toBe('https://cloud.example.org/remote.php/dav/calendars/anna/personal/x.ics')
		expect(davUrl('https://cloud.example.org/remote.php/dav/', '/calendars/anna%20maria/my%20calendar/x.ics'))
			.toBe('https://cloud.example.org/remote.php/dav/calendars/anna%20maria/my%20calendar/x.ics')
	})
})

describe('calendarMessage', () => {
	it('reports added entries', () => {
		expect(calendarMessage(result({ created: 4 }), 0, 0))
			.toEqual({ text: '4 entries have been added to the calendar "Persönlich".', type: 'success' })
		expect(calendarMessage(result({ created: 1 }), 0, 0).text).toBe('1 entry has been added to the calendar "Persönlich".')
	})

	it('reports updated entries with their calendars', () => {
		const updates = [
			{ calendar: 'Chor', path: 'calendars/anna/chor/a.ics', ics: '' },
			{ calendar: 'Chor', path: 'calendars/anna/chor/b.ics', ics: '' },
		]
		expect(calendarMessage(result({ updates }), 2, 0))
			.toEqual({ text: '2 entries were already in your calendars (Chor) and have been updated there.', type: 'success' })
	})

	it('warns about failures without hiding what worked', () => {
		const updates = [{ calendar: 'Chor', path: 'p', ics: '' }]
		const message = calendarMessage(result({ created: 3, updates, failed: 0 }), 0, 1)
		expect(message.type).toBe('warning')
		expect(message.text).toContain('3 entries have been added')
		expect(message.text).toContain('1 entry could not be saved.')
		expect(calendarMessage(result({ failed: 4 }), 0, 0).type).toBe('error')
	})

	it('explains why a cancelled concert is not added', () => {
		const message = calendarMessage(result({ skipped: 4 }), 0, 0)
		expect(message.type).toBe('warning')
		expect(message.text).toBe('Cancelled concerts are not added; entries that are already in a calendar are updated.')
	})

	it('says when there is no upcoming concert', () => {
		expect(calendarMessage(result({ concerts: 0 }), 0, 0)).toEqual({ text: 'There are no upcoming concerts.', type: 'warning' })
	})
})
