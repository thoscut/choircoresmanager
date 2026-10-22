/**
 * Concerts in the calendar: which concerts count as upcoming, how a concert's
 * time is shown, where an entry lives (CalDAV) and what the user is told
 * after adding entries. Pure functions, tested in
 * src/__tests__/calendar.test.ts.
 */
import type { CalendarAddResult, ChoirEvent } from './types.ts'

import { n, t } from './l10n.ts'

/** Message after adding entries to a calendar. */
export interface CalendarMessage {
	text: string
	type: 'success' | 'warning' | 'error'
}

/**
 * Whether a value is a full date (YYYY-MM-DD); imported programmes may
 * only have a year.
 *
 * @param value - event date
 */
export function isFullDate(value: string | null | undefined): value is string {
	return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
}

/**
 * Whether a concert can go into a calendar: a concert with a full date.
 *
 * @param event - concert or song list
 */
export function isCalendarConcert(event: ChoirEvent): boolean {
	return event.kind === 'concert' && isFullDate(event.event_date)
}

/**
 * Concerts from today on that are planned in the app (as the server counts
 * them for "all upcoming concerts").
 *
 * @param events - concerts
 * @param today - YYYY-MM-DD
 */
export function upcomingConcerts(events: readonly ChoirEvent[], today: string): ChoirEvent[] {
	return events.filter((event) => isCalendarConcert(event) && !event.imported && (event.event_date ?? '') >= today)
}

/**
 * Start and end time of a concert for display ("19:30–21:45", "19:30"),
 * "" without a time.
 *
 * @param event - concert
 */
export function timeText(event: Pick<ChoirEvent, 'start_time' | 'end_time'>): string {
	if (!event.start_time) {
		return ''
	}
	return event.end_time ? `${event.start_time}–${event.end_time}` : event.start_time
}

/**
 * URL of a calendar object for the CalDAV PUT.
 *
 * @param root - URL of remote.php/dav
 * @param path - path from the server, segments already encoded
 */
export function davUrl(root: string, path: string): string {
	return root.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '')
}

/**
 * What the user is told after adding entries.
 *
 * @param result - answer of the server
 * @param updated - entries updated by the browser
 * @param failedUpdates - updates that failed
 */
export function calendarMessage(result: CalendarAddResult, updated: number, failedUpdates: number): CalendarMessage {
	if (result.concerts === 0) {
		return { text: t('notenarchiv', 'There are no upcoming concerts.'), type: 'warning' }
	}
	const parts: string[] = []
	if (result.created > 0) {
		parts.push(n('notenarchiv', '%n entry has been added to the calendar "{calendar}".', '%n entries have been added to the calendar "{calendar}".',
			result.created, { calendar: result.calendar }))
	}
	if (updated > 0) {
		const calendars = [...new Set(result.updates.map((update) => update.calendar))].join(', ')
		parts.push(n('notenarchiv', '%n entry was already in your calendar "{calendars}" and has been updated there.',
			'%n entries were already in your calendars ({calendars}) and have been updated there.', updated, { calendars }))
	}
	if (result.skipped > 0) {
		parts.push(t('notenarchiv', 'Cancelled concerts are not added; entries that are already in a calendar are updated.'))
	}
	const failed = result.failed + failedUpdates
	if (failed > 0) {
		parts.push(n('notenarchiv', '%n entry could not be saved. Please try again later or use the calendar file.',
			'%n entries could not be saved. Please try again later or use the calendar file.', failed))
	}
	if (parts.length === 0) {
		parts.push(t('notenarchiv', 'Nothing has changed.'))
	}
	const saved = result.created + updated
	let type: CalendarMessage['type'] = 'success'
	if (failed > 0) {
		type = saved > 0 ? 'warning' : 'error'
	} else if (saved === 0) {
		type = 'warning'
	}
	return { text: parts.join(' '), type }
}
