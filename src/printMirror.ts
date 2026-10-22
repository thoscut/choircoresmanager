/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The mirror folder of the print station (module "print_mirror", see
 * docs/api.md): types, calls to the server and pure helpers. The server
 * checks everything again.
 */
import axios from '@nextcloud/axios'
import { t } from './l10n.ts'
import { apiUrl } from './api.ts'

/** The target folder as the account that chose it sees it. */
export interface MirrorFolder {
	id: number
	path: string
	user: string
}

/** One file of a report; `reasonText` only for skipped files. */
export interface MirrorEntry {
	name: string
	shelfmark: string | null
	reason: string | null
	reasonText?: string
}

/** What a sync did, or would do (preview, `dryRun`). */
export interface MirrorReport {
	time: number
	dryRun: boolean
	writable: boolean
	copied: MirrorEntry[]
	replaced: MirrorEntry[]
	removed: MirrorEntry[]
	skipped: MirrorEntry[]
	unchanged: number
	files: number
}

/** GET /api/print-mirror and /api/settings/print-mirror */
export interface MirrorStatus {
	folder: MirrorFolder | null
	/** whether the daily sync runs (after the first sync by hand) */
	started: boolean
	report: MirrorReport | null
}

/**
 * A list from the server, or an empty one.
 *
 * @param value - value from the server
 */
function list<T>(value: unknown): T[] {
	return Array.isArray(value) ? value as T[] : []
}

/**
 * The report with all its lists, also when the server sends none.
 *
 * @param report - report from the server
 */
export function normalizeReport(report: Partial<MirrorReport> | null | undefined): MirrorReport | null {
	if (!report) {
		return null
	}
	return {
		time: report.time ?? 0,
		dryRun: report.dryRun === true,
		writable: report.writable !== false,
		copied: list(report.copied),
		replaced: list(report.replaced),
		removed: list(report.removed),
		skipped: list(report.skipped),
		unchanged: report.unchanged ?? 0,
		files: report.files ?? 0,
	}
}

/**
 * @param admin - read through the settings route (administrators)
 */
export async function fetchMirrorStatus(admin = false): Promise<MirrorStatus> {
	const { data } = await axios.get<MirrorStatus>(apiUrl(admin ? '/settings/print-mirror' : '/print-mirror'))
	return { folder: data.folder ?? null, started: data.started === true, report: normalizeReport(data.report) }
}

/** What a sync would do now, without writing anything. */
export async function previewMirror(): Promise<MirrorReport> {
	const { data } = await axios.get<MirrorReport>(apiUrl('/print-mirror/preview'))
	return normalizeReport(data) as MirrorReport
}

export async function syncMirror(): Promise<MirrorReport> {
	const { data } = await axios.post<MirrorReport>(apiUrl('/print-mirror/sync'))
	return normalizeReport(data) as MirrorReport
}

/**
 * Checks a folder as target folder without saving it (administrators).
 *
 * @param folderId - Nextcloud file id of the folder
 */
export async function checkMirrorFolder(folderId: number): Promise<MirrorFolder> {
	const { data } = await axios.get<{ folder: MirrorFolder }>(apiUrl('/settings/print-mirror/check'), { params: { folderId } })
	return data.folder
}

/**
 * Saves the target folder; 0 removes it (administrators).
 *
 * @param folderId - Nextcloud file id of the folder, or 0
 */
export async function saveMirrorFolder(folderId: number): Promise<MirrorFolder | null> {
	const { data } = await axios.put<{ folder: MirrorFolder | null }>(apiUrl('/settings/print-mirror'), { folderId })
	return data.folder ?? null
}

/**
 * Whether a report changes anything.
 *
 * @param report - the report
 */
export function hasChanges(report: MirrorReport): boolean {
	return report.copied.length + report.replaced.length + report.removed.length > 0
}

/**
 * The lists of a report with their headings, in the order shown; empty
 * lists are left out.
 *
 * @param report - the report
 */
export function reportSections(report: MirrorReport): { key: 'copied' | 'replaced' | 'removed' | 'skipped', title: string, entries: MirrorEntry[] }[] {
	const preview = report.dryRun
	const sections = [
		{ key: 'copied' as const, title: preview ? t('notenarchiv', 'Would be copied') : t('notenarchiv', 'Copied'), entries: report.copied },
		{ key: 'replaced' as const, title: preview ? t('notenarchiv', 'Would be replaced') : t('notenarchiv', 'Replaced'), entries: report.replaced },
		{ key: 'removed' as const, title: preview ? t('notenarchiv', 'Would be removed') : t('notenarchiv', 'Removed'), entries: report.removed },
		{ key: 'skipped' as const, title: preview ? t('notenarchiv', 'Would be skipped') : t('notenarchiv', 'Skipped'), entries: report.skipped },
	]
	return sections.filter((section) => section.entries.length > 0)
}
