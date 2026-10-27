/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { addPageNumbers, pageCounterContent, printHeaderLine } from '../printHeader.ts'

describe('header of printouts', () => {
	it('names choir, view, filter and date', () => {
		expect(printHeaderLine({ site: 'Liederkranz', view: 'Tasks', filter: 'Only open', date: 'Oct 27, 2026' }))
			.toBe('Liederkranz · Tasks · Filter: Only open · Printed on Oct 27, 2026')
	})

	it('leaves out what is empty', () => {
		expect(printHeaderLine({ site: ' ', view: 'Persons', filter: '', date: '27.10.2026' })).toBe('Persons · Printed on 27.10.2026')
	})

	it('turns the page text into CSS counters', () => {
		expect(pageCounterContent('Page {page} of {pages}')).toBe('"Page " counter(page) " of " counter(pages)')
		expect(pageCounterContent('Seite {page}/{pages} "x"')).toBe('"Seite " counter(page) "/" counter(pages) " \\"x\\""')
	})

	it('adds the page numbers once', () => {
		addPageNumbers()
		addPageNumbers()
		const styles = document.querySelectorAll('#notenarchiv-page-numbers')
		expect(styles).toHaveLength(1)
		expect(styles[0].textContent).toContain('counter(page)')
	})
})
