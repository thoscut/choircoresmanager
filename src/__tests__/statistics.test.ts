import { describe, expect, it } from 'vitest'
import { buildQuery, parseStartParams } from '../startParams.ts'
import {
	barWidth,
	choosePeriodYear,
	defaultReportYear,
	figure,
	formatNumber,
	formatShare,
	MAX_YEARS,
	maxOf,
	periodYears,
	share,
	sumOf,
	yearsBetween,
} from '../statistics.ts'

describe('barWidth', () => {
	it('is the share of the largest value in percent', () => {
		expect(barWidth(50, 200)).toBe(25)
		expect(barWidth(200, 200)).toBe(100)
		expect(barWidth(1, 3)).toBe(33.3)
	})

	it('shows a sliver for small values and nothing for zero', () => {
		expect(barWidth(1, 10000)).toBe(1)
		expect(barWidth(0, 10)).toBe(0)
	})

	it('copes with empty tables and odd values', () => {
		expect(barWidth(5, 0)).toBe(0)
		expect(barWidth(-1, 10)).toBe(0)
		expect(barWidth(Number.NaN, 10)).toBe(0)
		expect(barWidth(20, 10)).toBe(100)
	})
})

describe('columns of a table', () => {
	const rows = [{ value: 3 }, { value: 7 }, { value: 2 }]

	it('finds the largest value and the sum', () => {
		expect(maxOf(rows, (row) => row.value)).toBe(7)
		expect(sumOf(rows, (row) => row.value)).toBe(12)
		expect(maxOf([], (row: { value: number }) => row.value)).toBe(0)
	})

	it('reads a figure of a map, 0 when missing', () => {
		expect(figure({ ok: 4 }, 'ok')).toBe(4)
		expect(figure({ ok: 4 }, 'remove')).toBe(0)
		expect(figure(undefined, 'ok')).toBe(0)
	})
})

describe('shares and numbers', () => {
	it('gives a share only with a whole', () => {
		expect(share(1, 4)).toBe(0.25)
		expect(share(0, 0)).toBeNull()
		expect(share(5, 4)).toBe(1)
	})

	it('formats numbers and shares in the locale', () => {
		expect(formatNumber(1234, 'de-DE')).toBe('1.234')
		expect(formatNumber(1234, 'en-US')).toBe('1,234')
		expect(formatShare(0.256, 'de-DE')).toMatch(/^26\s%$/)
		expect(formatShare(null, 'de-DE')).toBe('–')
	})
})

describe('periods', () => {
	it('lists the years of a period', () => {
		expect(yearsBetween(2024, 2026)).toEqual([2024, 2025, 2026])
		expect(yearsBetween(2026, 2025)).toEqual([])
	})

	it('offers at least ten years back and some years ahead', () => {
		const years = periodYears(2024, 2026)
		expect(years[0]).toBe(2017)
		expect(years[years.length - 1]).toBe(2036)
		expect(periodYears(1985, 2026)[0]).toBe(1985)
		expect(periodYears(1500, 2026)[0]).toBe(1800)
	})

	it('keeps the first year not after the last', () => {
		expect(choosePeriodYear({ from: 2017, to: 2026 }, 'from', 2020)).toEqual({ from: 2020, to: 2026 })
		expect(choosePeriodYear({ from: 2017, to: 2020 }, 'from', 2024)).toEqual({ from: 2024, to: 2024 })
		expect(choosePeriodYear({ from: 2017, to: 2026 }, 'to', 2010)).toEqual({ from: 2010, to: 2010 })
	})

	it('keeps the period within the longest allowed', () => {
		expect(choosePeriodYear({ from: 2017, to: 2026 }, 'from', 1900)).toEqual({ from: 1900, to: 1900 + MAX_YEARS - 1 })
		expect(choosePeriodYear({ from: 1900, to: 1950 }, 'to', 2026)).toEqual({ from: 2026 - MAX_YEARS + 1, to: 2026 })
	})

	it('proposes the past year for the annual report in the first half of the year', () => {
		expect(defaultReportYear('2026-03-15')).toBe(2025)
		expect(defaultReportYear('2026-06-30')).toBe(2025)
		expect(defaultReportYear('2026-07-01')).toBe(2026)
		expect(defaultReportYear('2026-12-31')).toBe(2026)
	})
})

describe('the statistics view in the URL', () => {
	it('is ?view=statistics', () => {
		expect(parseStartParams('?view=statistics').view).toBe('statistics')
		expect(buildQuery({ view: 'statistics' })).toBe('?view=statistics')
	})
})
