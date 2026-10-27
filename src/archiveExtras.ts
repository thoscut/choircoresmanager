/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Milestone 0.8, archive: the report of gaps in the shelf marks (part of the
 * inventory) and the annual snapshots with their comparison (module
 * "annual_snapshot"). Types and pure helpers, no Vue, easy to test; the
 * requests are in archiveExtrasApi.ts. See docs/api.md.
 */

import type { RunState } from './types.ts'

// ---- gaps in the shelf marks ----------------------------------------------------

/** One binder of `GET /api/inventory/gaps`. */
export interface ShelfmarkGaps {
	binder: number
	/** highest number used, by a copy or a file name */
	highest: number
	/** copies in the binder */
	copies: number
	/** numbers without a copy */
	missing: number
	ranges: { from: number, to: number }[]
	/** missing numbers that a file name uses (probably a copy not entered yet) */
	withFile: number[]
}

/**
 * Ranges as text with shelf marks: "5-1 – 5-2, 5-7".
 *
 * @param binder - binder number
 * @param ranges - ranges of missing numbers
 */
export function rangesText(binder: number, ranges: readonly { from: number, to: number }[]): string {
	return ranges.map(({ from, to }) => from === to ? `${binder}-${from}` : `${binder}-${from} – ${binder}-${to}`).join(', ')
}

// ---- annual snapshots -----------------------------------------------------------

export interface Snapshot {
	/** folder name `<year>_<YYYYMMDD-HHMMSS>` */
	name: string
	year: number
	/** "YYYY-MM-DD HH:MM:SS", local time of the account that made it */
	created: string
	/** all three files present */
	complete: boolean
}

export interface SnapshotOverview {
	snapshots: Snapshot[]
	/** freeze automatically at the turn of the year */
	auto: boolean
	/** translated message of the last failed automatic snapshot */
	lastError: string | null
	/** a snapshot being made at this moment */
	running?: RunState | null
	/** what a new snapshot writes where */
	next: {
		year: number
		/** path relative to the files of the account that set the archive folder */
		folder: string
		files: string[]
		archiveFiles: number
		bytes: number
		/** a snapshot of this year exists already */
		exists: boolean
	}
}

export interface SnapshotResult {
	name: string
	folder: string
	files: string[]
	manifest: { files: number, bytes: number, missing: number }
}

export interface SnapshotChange {
	field: string
	old: string | number | null
	new: string | number | null
}

export interface SnapshotComparison {
	older: Snapshot
	newer: Snapshot
	copies: {
		added: { shelfmark: string, title: string | null }[]
		removed: { shelfmark: string, title: string | null }[]
		changed: { shelfmark: string, title: string | null, changes: SnapshotChange[] }[]
	}
	rights: { shelfmark: string, title: string | null, changes: SnapshotChange[] }[]
	licenses: { shelfmark: string, title: string | null, holder: string | null, uses: string | null, change: 'added' | 'changed' | 'removed', changes: SnapshotChange[] }[]
	files: {
		added: { path: string, size: number | null }[]
		removed: { path: string, size: number | null }[]
		changed: { path: string, size: number | null }[]
		/** false when a manifest could not be read */
		compared: boolean
	}
}

/**
 * The snapshots to compare by default: the newest, and the newest one of an
 * earlier year (the year before); without one, the snapshot before the
 * newest. Only complete snapshots count.
 *
 * @param snapshots - newest first
 */
export function defaultPair(snapshots: readonly Snapshot[]): { older: string, newer: string } | null {
	const complete = snapshots.filter((snapshot) => snapshot.complete)
	const newer = complete[0]
	if (newer === undefined) {
		return null
	}
	const older = complete.find((snapshot) => snapshot.year < newer.year) ?? complete[1]
	return older === undefined ? null : { older: older.name, newer: newer.name }
}

/**
 * Number of differences of a comparison, for the summary.
 *
 * @param comparison - result of compareSnapshots()
 */
export function differenceCount(comparison: SnapshotComparison): number {
	const { copies, rights, licenses, files } = comparison
	return copies.added.length + copies.removed.length + copies.changed.length + rights.length + licenses.length
		+ files.added.length + files.removed.length + files.changed.length
}
