/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { FileRef } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { parsePage, scanFiles, storedScanPage, storeScanPage } from '../scan.ts'

/**
 * A file row with defaults.
 *
 * @param values - fields that differ from the defaults
 */
function file(values: Partial<FileRef>): FileRef {
	return {
		id: 1,
		copy_id: 5,
		file_id: 4711,
		path: 'Mappe_5/5-01_X.pdf',
		name: '5-01_X.pdf',
		type: 'pdf',
		role: 'score_pdf',
		size: 1,
		mtime: 0,
		listed: true,
		musicxml_valid: false,
		found: true,
		...values,
	}
}

describe('scan beside the score', () => {
	it('offers the PDF scans that were found', () => {
		const files = [file({ id: 1 }), file({ id: 2, found: false }), file({ id: 3, role: 'musicxml', type: 'mxl' }), file({ id: 4, found: null })]
		expect(scanFiles(files).map((item) => item.id)).toEqual([1, 4])
	})

	it('accepts whole page numbers from 1 only', () => {
		expect(parsePage('3')).toBe(3)
		expect(parsePage(' 12 ')).toBe(12)
		expect(parsePage(2)).toBe(2)
		for (const value of ['', '0', '-1', '1.5', 'abc', null, undefined, 100000]) {
			expect(parsePage(value), String(value)).toBeNull()
		}
	})

	it('remembers the page per copy and file', () => {
		window.localStorage.clear()
		expect(storedScanPage(5, 1)).toBe(1)
		storeScanPage(5, 1, 4)
		expect(storedScanPage(5, 1)).toBe(4)
		expect(storedScanPage(5, 2)).toBe(1)
		window.localStorage.setItem('notenarchiv.scanPage.5.2', 'nonsense')
		expect(storedScanPage(5, 2)).toBe(1)
	})
})
