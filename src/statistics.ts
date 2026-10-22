/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers of the statistics view: bar widths, shares, periods, number
 * formats and the default year of the annual report. No Vue, no server
 * access, easy to test.
 *
 * The bars are decoration only (aria-hidden); every figure is shown as text
 * next to its bar, so nothing depends on the bar or on colour.
 */
import { getCanonicalLocale } from '@nextcloud/l10n'

/** Locale of the user for numbers, German when it cannot be read. */
export function appLocale(): string {
	try {
		return getCanonicalLocale()
	} catch {
		return 'de-DE'
	}
}

/**
 * A whole number in the user's locale ("1.234" in German).
 *
 * @param value - the number
 * @param locale - BCP 47 locale
 */
export function formatNumber(value: number, locale = appLocale()): string {
	return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value)
}

/**
 * A share (0 … 1) as whole percent in the user's locale ("12 %" in
 * German), or "–" without a share.
 *
 * @param fraction - the share, or null
 * @param locale - BCP 47 locale
 */
export function formatShare(fraction: number | null, locale = appLocale()): string {
	return fraction === null ? '–' : new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(fraction)
}

/** Earliest year of a period (as on the server). */
export const FIRST_YEAR = 1800

/** Longest period in years (as on the server). */
export const MAX_YEARS = 100

/** Years after the current one that can be chosen (protection terms ahead). */
export const YEARS_AHEAD = 10

export interface Period {
	from: number
	to: number
}

/**
 * Width of a bar in percent of the largest value, 0 … 100. A value above
 * zero gets at least a sliver, so that it does not look like zero.
 *
 * @param value - the figure
 * @param max - the largest figure of the table
 */
export function barWidth(value: number, max: number): number {
	if (!Number.isFinite(value) || !Number.isFinite(max) || value <= 0 || max <= 0) {
		return 0
	}
	const width = Math.min(100, (value / max) * 100)
	return Math.max(1, Math.round(width * 10) / 10)
}

/**
 * Largest value of a column, for the bars of a table.
 *
 * @param rows - table rows
 * @param pick - the figure of a row
 */
export function maxOf<T>(rows: readonly T[], pick: (row: T) => number): number {
	return rows.reduce((max, row) => Math.max(max, pick(row)), 0)
}

/**
 * Sum of a column.
 *
 * @param rows - table rows
 * @param pick - the figure of a row
 */
export function sumOf<T>(rows: readonly T[], pick: (row: T) => number): number {
	return rows.reduce((sum, row) => sum + pick(row), 0)
}

/**
 * Share of a part in a whole as a fraction (0 … 1), or null without a whole.
 *
 * @param part - the part
 * @param whole - the whole
 */
export function share(part: number, whole: number): number | null {
	return whole > 0 ? Math.min(1, Math.max(0, part / whole)) : null
}

/**
 * Years from first to last, ascending.
 *
 * @param first - first year
 * @param last - last year
 */
export function yearsBetween(first: number, last: number): number[] {
	const years: number[] = []
	for (let year = first; year <= last; year++) {
		years.push(year)
	}
	return years
}

/**
 * A period after one of its years was chosen: the other year follows so
 * that the first is never after the last and the period is never longer
 * than allowed.
 *
 * @param period - the period before
 * @param changed - which year was chosen
 * @param year - the chosen year
 */
export function choosePeriodYear(period: Period, changed: 'from' | 'to', year: number): Period {
	let { from, to } = period
	if (changed === 'from') {
		from = year
		if (to < from) {
			to = from
		} else if (to - from >= MAX_YEARS) {
			to = from + MAX_YEARS - 1
		}
	} else {
		to = year
		if (from > to) {
			from = to
		} else if (to - from >= MAX_YEARS) {
			from = to - MAX_YEARS + 1
		}
	}
	return { from, to }
}

/**
 * Years that can be chosen for a period: from the first year with data
 * (at most ten years before the current one, so that a short history still
 * offers a choice) to some years ahead.
 *
 * @param first - first year with data
 * @param current - the current year
 */
export function periodYears(first: number, current: number): number[] {
	return yearsBetween(Math.max(FIRST_YEAR, Math.min(first, current - 9)), current + YEARS_AHEAD)
}

/**
 * The year an annual report is usually written for: in the first half of
 * the year the past one (for the general meeting), later the current one.
 *
 * @param today - "YYYY-MM-DD"
 */
export function defaultReportYear(today: string): number {
	const match = today.match(/^(\d{4})-(\d{2})/)
	if (!match) {
		return new Date().getFullYear()
	}
	const year = Number(match[1])
	return Number(match[2]) <= 6 ? year - 1 : year
}

/**
 * Age group of open tasks: the column keys of the table in their order.
 */
export const TASK_AGE_KEYS = ['days30', 'days90', 'days365', 'older'] as const

/**
 * Number of a row of a "key => figure" map, 0 if missing.
 *
 * @param map - figures by key
 * @param key - the key
 */
export function figure(map: Record<string, number> | undefined, key: string): number {
	const value = map?.[key]
	return typeof value === 'number' && Number.isFinite(value) ? value : 0
}
