/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The action "Open in music archive" of the Files app (src/files.ts): for
 * which files it is shown, when it is the default action of a click, how the
 * answer of the server is read and where it leads. Pure functions without
 * Vue and without Nextcloud globals, easy to test.
 *
 * The action only guesses from the path and the extension. Which copy a
 * file belongs to is decided by the server when the action runs
 * (`GET /api/nextcloud-files/{fileId}/copy`), and the app checks the rights
 * as always.
 */
import { t } from './l10n.ts'
import { buildQuery, DEFAULT_VIEW } from './startParams.ts'

/** Initial state `files` from Listener\FilesScriptListener. */
export interface FilesState {
	/** the archive folder as the account sees it, relative to its files, e.g. "/Notenarchiv" */
	archivePath: string
	/** extensions of archive files (lower case, without dot) */
	extensions: string[]
	/** extensions whose click opens the score view */
	scoreExtensions: string[]
	/** whether the score view is switched on */
	scoreView: boolean
}

/** What the action reads of a node of the Files app (INode of @nextcloud/files). */
export interface FileNode {
	/** 'file' or 'folder' */
	type: string
	/** DAV root of the node, "/files/{uid}" for the account's own files */
	root: string
	/** path relative to the root, e.g. "/Notenarchiv/Mappe_2/2-01_Demo.mxl" */
	path: string
	basename: string
	mime: string
	/** bit mask, 1 = read */
	permissions: number
	/** Nextcloud file id */
	id?: string
}

/** Answer of `GET /api/nextcloud-files/{fileId}/copy`. */
export interface CopyOfFile {
	copyId: number
	shelfmark: string
	role: string
	/** open the score view (MusicXML), otherwise the copy */
	score: boolean
}

/**
 * Which of the two actions applies: `default` opens with a click (MusicXML
 * in the archive folder), `menu` is only in the actions menu, null none.
 */
export type ActionKind = 'default' | 'menu' | null

const PERMISSION_READ = 1

/**
 * Extension of a file name in lower case, without dot ("" without one),
 * as the file sync reads it (PHP's pathinfo).
 *
 * @param name - file name
 */
export function fileExtension(name: string): string {
	const dot = name.lastIndexOf('.')
	return dot === -1 ? '' : name.slice(dot + 1).toLowerCase()
}

/**
 * Whether a path lies below a folder (both relative to the account's files).
 *
 * @param path - path of the file, e.g. "/Notenarchiv/Mappe_2/2-01.pdf"
 * @param folder - the folder, e.g. "/Notenarchiv" or "/" for all files
 */
export function isBelow(path: string, folder: string): boolean {
	const base = folder.replace(/\/+$/, '')
	return path.startsWith(base + '/') && path.length > base.length + 1
}

/**
 * Which action applies to the nodes of the Files app. Only one file of the
 * account's own files (`root`), readable, below the archive folder, with an
 * extension of archive files. It is the default action for MusicXML while
 * the score view is on, unless the Nextcloud viewer opens this type of file
 * (another app registered it there): a click then stays with that viewer
 * and the action is in the menu. PDFs and other files are never the default.
 *
 * @param nodes - nodes of the action context
 * @param state - initial state, null when the script got none
 * @param root - "/files/{uid}" of the current account
 * @param viewerMimes - MIME types the Nextcloud viewer opens (OCA.Viewer.mimetypes)
 */
export function actionKind(nodes: readonly FileNode[], state: FilesState | null, root: string,
	viewerMimes: readonly string[]): ActionKind {
	if (state === null || nodes.length !== 1) {
		return null
	}
	const node = nodes[0] as FileNode
	if (node.type !== 'file' || node.root !== root || !/^\d+$/.test(node.id ?? '')
		|| (node.permissions & PERMISSION_READ) === 0 || !isBelow(node.path, state.archivePath)) {
		return null
	}
	const extension = fileExtension(node.basename)
	if (!state.extensions.includes(extension)) {
		return null
	}
	const opensScore = state.scoreView && state.scoreExtensions.includes(extension)
	return opensScore && !viewerMimes.includes(node.mime) ? 'default' : 'menu'
}

/**
 * The answer of the server, or null when it is not one.
 *
 * @param body - parsed JSON body
 */
export function parseCopyOfFile(body: unknown): CopyOfFile | null {
	if (typeof body !== 'object' || body === null) {
		return null
	}
	const data = body as Record<string, unknown>
	if (typeof data.copyId !== 'number' || !Number.isInteger(data.copyId) || data.copyId <= 0) {
		return null
	}
	return {
		copyId: data.copyId,
		shelfmark: typeof data.shelfmark === 'string' ? data.shelfmark : '',
		role: typeof data.role === 'string' ? data.role : 'other',
		score: data.score === true,
	}
}

/**
 * Link into the app: the copy in the sidebar, for MusicXML with its score
 * view (`?copy={id}&score=1`).
 *
 * @param appUrl - URL of the app page, e.g. "/index.php/apps/notenarchiv/"
 * @param copy - answer of the server
 */
export function appLink(appUrl: string, copy: CopyOfFile): string {
	return appUrl + buildQuery({ view: DEFAULT_VIEW, copyId: copy.copyId, score: copy.score })
}

/**
 * Message for a failed request: the server's own (translated) error, or a
 * general text for the rate limit, other HTTP errors and a lost connection.
 *
 * @param status - HTTP status, null when the server was not reached
 * @param body - parsed JSON body, if any
 */
export function errorText(status: number | null, body: unknown): string {
	const error = typeof body === 'object' && body !== null ? (body as Record<string, unknown>).error : undefined
	if (typeof error === 'string' && error !== '') {
		return error
	}
	if (status === 429) {
		return t('notenarchiv', 'Too many requests. Please wait a moment and try again.')
	}
	if (status !== null) {
		return t('notenarchiv', 'The server reported an error (HTTP {status}).', { status })
	}
	return t('notenarchiv', 'The server cannot be reached. Please check your connection.')
}
