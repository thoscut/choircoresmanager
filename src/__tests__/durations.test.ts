/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { formatDuration, parseDuration } from '../durations.ts'

describe('formatDuration', () => {
	it('formats minutes and seconds', () => {
		expect(formatDuration(185)).toBe('3:05')
		expect(formatDuration(59)).toBe('0:59')
		expect(formatDuration(0)).toBe('0:00')
	})

	it('adds hours for long works', () => {
		expect(formatDuration(3723)).toBe('1:02:03')
	})

	it('rounds fractions of seconds', () => {
		expect(formatDuration(184.6)).toBe('3:05')
	})

	it('gives an empty text for missing or invalid values', () => {
		expect(formatDuration(null)).toBe('')
		expect(formatDuration(undefined)).toBe('')
		expect(formatDuration(-1)).toBe('')
		expect(formatDuration(Number.NaN)).toBe('')
	})
})

describe('parseDuration', () => {
	it('reads minutes:seconds', () => {
		expect(parseDuration('3:05')).toBe(185)
		expect(parseDuration(' 12:00 ')).toBe(720)
		expect(parseDuration('0:59')).toBe(59)
	})

	it('accepts a dot as separator', () => {
		expect(parseDuration('3.05')).toBe(185)
	})

	it('reads hours:minutes:seconds', () => {
		expect(parseDuration('1:02:03')).toBe(3723)
	})

	it('gives null for empty input', () => {
		expect(parseDuration('')).toBeNull()
		expect(parseDuration('   ')).toBeNull()
	})

	it('rejects invalid input', () => {
		expect(parseDuration('3')).toBeUndefined()
		expect(parseDuration('3:5')).toBeUndefined()
		expect(parseDuration('3:60')).toBeUndefined()
		expect(parseDuration('1:60:00')).toBeUndefined()
		expect(parseDuration('abc')).toBeUndefined()
	})

	it('round-trips with formatDuration', () => {
		for (const seconds of [0, 1, 59, 60, 185, 3599, 3600, 7322]) {
			expect(parseDuration(formatDuration(seconds))).toBe(seconds)
		}
	})
})
