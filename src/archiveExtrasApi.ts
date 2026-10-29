/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Requests of milestone 0.8, archive: gaps in the shelf marks and annual
 * snapshots (see docs/api.md). Types and helpers are in archiveExtras.ts.
 */
import type { ShelfmarkGaps, SnapshotComparison, SnapshotOverview, SnapshotResult } from './archiveExtras.ts'

import axios from '@nextcloud/axios'
import { apiUrl } from './api.ts'

export async function fetchShelfmarkGaps(): Promise<ShelfmarkGaps[]> {
	const { data } = await axios.get<{ binders?: ShelfmarkGaps[] }>(apiUrl('/inventory/gaps'))
	return Array.isArray(data.binders) ? data.binders : []
}

export async function fetchSnapshots(): Promise<SnapshotOverview> {
	const { data } = await axios.get<SnapshotOverview>(apiUrl('/annual-snapshots'))
	return { ...data, snapshots: Array.isArray(data.snapshots) ? data.snapshots : [] }
}

export async function createSnapshot(): Promise<SnapshotResult> {
	const { data } = await axios.post<SnapshotResult>(apiUrl('/annual-snapshots'))
	return data
}

/**
 * @param older - name of the earlier snapshot
 * @param newer - name of the later snapshot
 */
export async function compareSnapshots(older: string, newer: string): Promise<SnapshotComparison> {
	const params = new URLSearchParams({ older, newer })
	const { data } = await axios.get<SnapshotComparison>(apiUrl('/annual-snapshots/compare') + '?' + params.toString())
	return data
}

/**
 * @param auto - freeze automatically at the turn of the year
 */
export async function setSnapshotAuto(auto: boolean): Promise<boolean> {
	const { data } = await axios.put<{ auto: boolean }>(apiUrl('/annual-snapshots/settings'), { auto })
	return data.auto
}
