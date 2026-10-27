/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { Folder, FolderLoan } from '../folderBook.ts'

import { describe, expect, it } from 'vitest'
import { contentsInput, filterFolders, folderSummary, holderText, openLoanEnd, openLoanText, outdatedText, retentionText, todayIso } from '../folderBook.ts'
import { HIDEABLE_VIEWS, VIEW_MODULES, viewShown } from '../modules.ts'
import { normalizeReport, reportSections } from '../printMirror.ts'
import { buildQuery, parseStartParams } from '../startParams.ts'

const loan: FolderLoan = {
	id: 1,
	holderName: 'Kim Example',
	holderAccount: false,
	anonymized: false,
	issuedOn: '2026-09-01',
	issueNote: null,
	returnedOn: null,
	returnWear: null,
	returnMissing: null,
	returnNote: null,
}

/**
 * A folder with defaults.
 *
 * @param id - folder id
 * @param changes - fields to change
 */
function folder(id: number, changes: Partial<Folder> = {}): Folder {
	return {
		id,
		label: String(id),
		voice: 'Tenor I',
		wear: 'good',
		missing: null,
		note: null,
		status: 'active',
		statusReason: null,
		statusSince: null,
		contents: [],
		outdated: false,
		loan: null,
		history: [],
		...changes,
	}
}

const folders = [
	folder(1, { loan }),
	folder(2),
	folder(3, { missing: 'page 2' }),
	folder(4, { outdated: true, voice: 'Bass' }),
	folder(5, { status: 'retired', statusReason: 'torn' }),
]

describe('the folder book', () => {
	it('counts the folders in service', () => {
		expect(folderSummary(folders)).toEqual({ active: 4, out: 1, available: 3, hints: 2 })
	})

	it('filters what is out, available, has a hint or is out of service', () => {
		expect(filterFolders(folders, 'all').map((f) => f.id)).toEqual([1, 2, 3, 4])
		expect(filterFolders(folders, 'out').map((f) => f.id)).toEqual([1])
		expect(filterFolders(folders, 'available').map((f) => f.id)).toEqual([2, 3, 4])
		expect(filterFolders(folders, 'hints').map((f) => f.id)).toEqual([3, 4])
		expect(filterFolders(folders, 'retired').map((f) => f.id)).toEqual([5])
	})

	it('finds a folder by label, voice or person', () => {
		expect(filterFolders(folders, 'all', 'bass').map((f) => f.id)).toEqual([4])
		expect(filterFolders(folders, 'all', 'kim').map((f) => f.id)).toEqual([1])
		expect(filterFolders(folders, 'all', ' 2 ').map((f) => f.id)).toEqual([2])
	})

	it('names the person, the account or a former loan', () => {
		expect(holderText(loan)).toBe('Kim Example')
		expect(holderText({ ...loan, holderAccount: true })).toBe('Kim Example (Nextcloud account)')
		expect(holderText({ ...loan, holderName: null, anonymized: true })).toBe('Former loan (name removed after the retention period)')
	})

	it('explains why a copy no longer applies', () => {
		const item = { copyId: 3, count: 1, shelfmark: '2-35', title: 'Ave', outdated: 'retired' as const }
		expect(outdatedText(item)).toBe('2-35: the copy has been retired in the catalog.')
		expect(outdatedText({ ...item, outdated: 'other_version_valid' })).toBe('2-35: another version of the work is now valid for the choir.')
		expect(outdatedText({ ...item, outdated: null })).toBe('')
	})

	it('says when names are removed', () => {
		expect(retentionText(12)).toContain('12 months after the folder came back')
		expect(retentionText(1)).toContain('1 month after the folder came back')
		expect(openLoanText(36)).toContain('36 months after it was issued')
	})

	it('names the day a folder that never came back loses its holder, 90 days ahead', () => {
		expect(openLoanEnd('2023-12-15', 36, '2026-10-27')).toBe('2026-12-15')
		expect(openLoanEnd('2024-03-15', 36, '2026-10-27')).toBeNull()
		expect(openLoanEnd('2023-02-28', 36, '2026-10-27')).toBe('2026-02-28')
		expect(openLoanEnd('2021-05-31', 36, '2024-05-01')).toBe('2024-05-31')
		expect(openLoanEnd('2023-11-30', 3, '2024-01-01')).toBe('2024-02-29')
		expect(openLoanEnd('', 36, '2026-10-27')).toBeNull()
	})

	it('adds up the same copy and checks the numbers', () => {
		expect(contentsInput([{ copyId: 3, count: '2' }, { copyId: null, count: '5' }, { copyId: 3, count: '' }]))
			.toEqual({ contents: [{ copyId: 3, count: 3 }] })
		expect(contentsInput([{ copyId: 3, count: '0' }])).toHaveProperty('error')
		expect(contentsInput([{ copyId: 3, count: '1.5' }])).toHaveProperty('error')
		expect(contentsInput([])).toEqual({ contents: [] })
	})

	it('writes today as a date of the form', () => {
		expect(todayIso(new Date(2026, 0, 5, 23, 30))).toBe('2026-01-05')
	})

	it('is a view of its own module that can be hidden', () => {
		expect(parseStartParams('?view=folder-book').view).toBe('folderBook')
		expect(buildQuery({ view: 'folderBook' })).toBe('?view=folder-book')
		expect(VIEW_MODULES.folderBook).toBe('folder_book')
		expect(HIDEABLE_VIEWS).toContain('folderBook')
		expect(viewShown('folderBook', { folder_book: false }, { navigation: [], cards: [] })).toBe(false)
		expect(viewShown('folderBook', { folder_book: true }, { navigation: ['folderBook'], cards: [] })).toBe(false)
	})
})

describe('the report of the print station', () => {
	it('fills in missing lists and leaves out empty ones', () => {
		const report = normalizeReport({ time: 5, dryRun: true, copied: [{ name: '2-35 Ave.pdf', shelfmark: '2-35', reason: null }] })
		expect(report?.removed).toEqual([])
		expect(report?.writable).toBe(true)
		expect(reportSections(report!).map((section) => [section.key, section.title])).toEqual([['copied', 'Would be copied']])
		expect(normalizeReport(null)).toBeNull()
	})

	it('names what was done after a sync', () => {
		const report = normalizeReport({
			dryRun: false,
			removed: [{ name: 'x.pdf', shelfmark: null, reason: null }],
			skipped: [{ name: 'y.pdf', shelfmark: null, reason: 'foreign' }],
		})
		expect(reportSections(report!).map((section) => section.title)).toEqual(['Removed', 'Skipped'])
	})
})
