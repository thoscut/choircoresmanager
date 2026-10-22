/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The scan beside the score: which PDF files of a copy can be shown and the
 * page last looked at, remembered per copy and file in the browser (a
 * convenience only: without storage the scan starts at page 1). The PDF is
 * shown by the browser's own viewer (see docs/api.md, "Scan beside the
 * score"), no library of the app.
 */
import type { FileRef } from './types.ts'

const PREFIX = 'notenarchiv.scanPage.'

/**
 * PDF scans of a copy that can be shown.
 *
 * @param files - files of the copy
 */
export function scanFiles(files: readonly FileRef[]): FileRef[] {
	return files.filter((file) => file.role === 'score_pdf' && file.found !== false)
}

/**
 * A page number from user input: a whole number from 1, otherwise null.
 *
 * @param value - input
 */
export function parsePage(value: unknown): number | null {
	const number = typeof value === 'number' ? value : Number(String(value ?? '').trim())
	return Number.isInteger(number) && number >= 1 && number <= 9999 ? number : null
}

/**
 * The page last looked at, or 1.
 *
 * @param copyId - copy id
 * @param fileId - `files[].id` of the PDF
 */
export function storedScanPage(copyId: number, fileId: number): number {
	try {
		return parsePage(window.localStorage.getItem(`${PREFIX}${copyId}.${fileId}`)) ?? 1
	} catch {
		return 1
	}
}

/**
 * Remember the page of a scan.
 *
 * @param copyId - copy id
 * @param fileId - `files[].id` of the PDF
 * @param page - page number
 */
export function storeScanPage(copyId: number, fileId: number, page: number): void {
	try {
		window.localStorage.setItem(`${PREFIX}${copyId}.${fileId}`, String(page))
	} catch {
		// private window or blocked storage: the page is simply not remembered
	}
}
