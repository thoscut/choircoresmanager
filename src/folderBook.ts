/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The folder book (module "folder_book", see docs/api.md): which choir
 * folder is out with whom since when. Types, calls to the server and pure
 * helpers for the view and its dialogs. The server checks everything again.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { n, t } from './l10n.ts'
import { apiUrl } from './api.ts'

/** Condition of a folder. */
export type FolderWear = 'good' | 'worn' | 'damaged'

export const WEARS: readonly FolderWear[] = ['good', 'worn', 'damaged']

/** One copy of the catalog in a folder. */
export interface FolderItem {
	copyId: number
	count: number
	shelfmark: string
	title: string | null
	/** why it no longer applies: the copy was retired, or another version is valid */
	outdated: 'retired' | 'other_version_valid' | null
}

/** One issue of a folder and its return. */
export interface FolderLoan {
	id: number
	/** null once the retention period has ended (anonymised) */
	holderName: string | null
	holderAccount: boolean
	anonymized: boolean
	issuedOn: string
	issueNote: string | null
	returnedOn: string | null
	returnWear: FolderWear | null
	returnMissing: string | null
	returnNote: string | null
}

export interface Folder {
	id: number
	label: string
	voice: string | null
	wear: FolderWear
	missing: string | null
	note: string | null
	status: 'active' | 'retired'
	statusReason: string | null
	statusSince: string | null
	contents: FolderItem[]
	/** some copy no longer applies (a hint only) */
	outdated: boolean
	/** the current loan, if the folder is out */
	loan: FolderLoan | null
	/** all loans, newest first */
	history: FolderLoan[]
}

/** GET /api/folder-book */
export interface FolderBook {
	folders: Folder[]
	retentionMonths: number
	canEdit: boolean
}

/** Fields of a folder to create or change. */
export interface FolderInput {
	label?: string
	voice?: string | null
	contents?: { copyId: number, count: number }[]
	wear?: FolderWear
	missing?: string | null
	note?: string | null
	status?: 'active' | 'retired'
	statusReason?: string | null
}

/** Issuing a folder: an account or a name. */
export interface IssueInput {
	holderUid?: string
	holderName?: string
	issuedOn: string
	note: string | null
}

/** Taking a folder back. */
export interface ReturnInput {
	returnedOn: string
	wear: FolderWear
	missing: string | null
	note: string | null
}

/** A Nextcloud account found by the search of Nextcloud. */
export interface AccountOption {
	id: string
	label: string
}

/**
 * A list from the server, or an empty one.
 *
 * @param value - value from the server
 */
function list<T>(value: unknown): T[] {
	return Array.isArray(value) ? value as T[] : []
}

export async function fetchFolderBook(): Promise<FolderBook> {
	const { data } = await axios.get<FolderBook>(apiUrl('/folder-book'))
	return {
		folders: list<Folder>(data.folders).map((folder) => ({ ...folder, contents: list(folder.contents), history: list(folder.history) })),
		retentionMonths: data.retentionMonths,
		canEdit: data.canEdit === true,
	}
}

export async function createFolder(folder: FolderInput): Promise<number> {
	const { data } = await axios.post<{ id: number }>(apiUrl('/folder-book/folders'), { folder })
	return data.id
}

export async function updateFolder(id: number, folder: FolderInput): Promise<void> {
	await axios.put(apiUrl('/folder-book/folders/{id}', { id }), { folder })
}

export async function issueFolder(id: number, loan: IssueInput): Promise<void> {
	await axios.post(apiUrl('/folder-book/folders/{id}/issue', { id }), { loan })
}

export async function returnFolder(loanId: number, loan: ReturnInput): Promise<void> {
	await axios.post(apiUrl('/folder-book/loans/{id}/return', { id: loanId }), { loan })
}

export async function fetchFolderBookSettings(): Promise<{ retentionMonths: number, min: number, max: number }> {
	const { data } = await axios.get<{ retentionMonths: number, min: number, max: number }>(apiUrl('/settings/folder-book'))
	return data
}

export async function saveFolderBookSettings(retentionMonths: number): Promise<{ retentionMonths: number, min: number, max: number }> {
	const { data } = await axios.put<{ retentionMonths: number, min: number, max: number }>(apiUrl('/settings/folder-book'), { retentionMonths })
	return data
}

/**
 * Nextcloud accounts matching a search, through Nextcloud's own search for
 * people (it follows the instance's rules on who may find whom).
 *
 * @param search - part of a name or account
 */
export async function searchAccounts(search: string): Promise<AccountOption[]> {
	if (search.trim().length < 2) {
		return []
	}
	const { data } = await axios.get<{ ocs?: { data?: unknown } }>(generateOcsUrl('core/autocomplete/get'), {
		params: { search: search.trim(), itemType: 'notenarchiv', itemId: 'folder-book', shareTypes: [0], limit: 10 },
	})
	return list<{ id?: unknown, label?: unknown }>(data.ocs?.data)
		.filter((entry) => typeof entry.id === 'string' && entry.id !== '')
		.map((entry) => ({ id: entry.id as string, label: typeof entry.label === 'string' && entry.label !== '' ? entry.label : entry.id as string }))
}

// ---- pure helpers -------------------------------------------------------------------------

/**
 * Label of a condition.
 *
 * @param wear - condition key
 */
export function wearLabel(wear: string | null | undefined): string {
	switch (wear) {
	case 'good':
		return t('notenarchiv', 'Good')
	case 'worn':
		return t('notenarchiv', 'Worn')
	case 'damaged':
		return t('notenarchiv', 'Damaged')
	default:
		return '–'
	}
}

/**
 * Who has (or had) a folder, in words.
 *
 * @param loan - the loan
 */
export function holderText(loan: FolderLoan): string {
	if (loan.anonymized || loan.holderName === null) {
		return t('notenarchiv', 'Former loan (name removed after the retention period)')
	}
	return loan.holderAccount
		? t('notenarchiv', '{name} (Nextcloud account)', { name: loan.holderName })
		: loan.holderName
}

/**
 * Why a copy in a folder no longer applies, in words.
 *
 * @param item - copy in the folder
 */
export function outdatedText(item: FolderItem): string {
	switch (item.outdated) {
	case 'retired':
		return t('notenarchiv', '{shelfmark}: the copy has been retired in the catalog.', { shelfmark: item.shelfmark })
	case 'other_version_valid':
		return t('notenarchiv', '{shelfmark}: another version of the work is now valid for the choir.', { shelfmark: item.shelfmark })
	default:
		return ''
	}
}

/**
 * The retention period in words: what is removed when.
 *
 * @param months - months after the return
 */
export function retentionText(months: number): string {
	return n('notenarchiv',
		'Names, Nextcloud accounts and notes of a loan are removed automatically %n month after the folder came back. The loan itself stays as "former loan".',
		'Names, Nextcloud accounts and notes of a loan are removed automatically %n months after the folder came back. The loan itself stays as "former loan".',
		months)
}

export type FolderFilter = 'all' | 'out' | 'available' | 'hints' | 'retired'

/**
 * The folders a filter shows: everything in service, out, available, with a
 * hint, or out of service. Retired folders only show with their own filter.
 *
 * @param folders - all folders
 * @param filter - the filter
 * @param search - text to find in label, voice or the name of the holder
 */
export function filterFolders(folders: readonly Folder[], filter: FolderFilter, search = ''): Folder[] {
	const needle = search.trim().toLocaleLowerCase()
	return folders.filter((folder) => {
		const retired = folder.status === 'retired'
		const shown = filter === 'retired'
			? retired
			: !retired && (filter === 'all'
				|| (filter === 'out' && folder.loan !== null)
				|| (filter === 'available' && folder.loan === null)
				|| (filter === 'hints' && (folder.outdated || (folder.missing ?? '') !== '')))
		if (!shown || needle === '') {
			return shown
		}
		return [folder.label, folder.voice ?? '', folder.loan?.holderName ?? '']
			.some((text) => text.toLocaleLowerCase().includes(needle))
	})
}

/**
 * Figures for the summary above the list (folders in service only).
 *
 * @param folders - all folders
 */
export function folderSummary(folders: readonly Folder[]): { active: number, out: number, available: number, hints: number } {
	const active = folders.filter((folder) => folder.status === 'active')
	const out = active.filter((folder) => folder.loan !== null).length
	return {
		active: active.length,
		out,
		available: active.length - out,
		hints: active.filter((folder) => folder.outdated || (folder.missing ?? '') !== '').length,
	}
}

/**
 * Contents to send: whole numbers of at least one, each copy once.
 *
 * @param rows - rows of the form (copy and number as typed)
 * @return the contents, or an error text for the first wrong row
 */
export function contentsInput(rows: readonly { copyId: number | null, count: string }[]): { contents: { copyId: number, count: number }[] } | { error: string } {
	const counts = new Map<number, number>()
	for (const row of rows) {
		if (row.copyId === null) {
			continue
		}
		const count = row.count.trim() === '' ? 1 : Number(row.count)
		if (!Number.isInteger(count) || count < 1 || count > 999) {
			return { error: t('notenarchiv', 'Please enter the number of each copy as a whole number from 1 to 999.') }
		}
		counts.set(row.copyId, (counts.get(row.copyId) ?? 0) + count)
	}
	return { contents: [...counts].map(([copyId, count]) => ({ copyId, count })) }
}

/**
 * Today in the browser (YYYY-MM-DD), the default day of an issue or return.
 *
 * @param now - the current time
 */
export function todayIso(now: Date = new Date()): string {
	const pad = (value: number) => String(value).padStart(2, '0')
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
