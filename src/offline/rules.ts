/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Practising without a network (milestone 0.15): the rules that the page
 * "Shared with me" and its service worker share. What a request is, which
 * pieces kept on the device may still be shown, and how the list of kept
 * pieces follows the links on the server. No browser API here, so that the
 * rules are tested in Node; the service worker is built from them on its own
 * (vite.config.ts).
 */

/** Every cache of the app starts with this, so that "delete" finds them all */
export const CACHE_PREFIX = 'notenarchiv-'
/** The pieces kept on the device, the page and the list of kept pieces */
export const DATA_CACHE = 'notenarchiv-offline'
/** Scripts, styles, fonts and images of the page, one cache per app version */
export const SHELL_PREFIX = 'notenarchiv-shell-'
/** The list of kept pieces, inside DATA_CACHE, relative to the scope */
export const CATALOG_NAME = 'offline-catalog.json'
/** Kept pieces are locked after this many days without a check with the server */
export const MAX_DAYS_UNCHECKED = 30

/** A piece kept on the device */
export interface KeptPiece {
	title: string
	composer: string
	/** last valid day of the link (YYYY-MM-DD), null without end */
	expires: string | null
	/** the scan (PDF) is kept too */
	scan: boolean
	/** when it was put on the device (ISO time) */
	kept: string
}

/** The list of kept pieces of one account on this device */
export interface Catalog {
	v: 1
	/** hash of the account, so that another account on the same browser starts empty */
	account: string
	/** last check with the server (ISO time) */
	checked: string
	pieces: Record<string, KeptPiece>
}

/** What a link on the server says about keeping it (from the page or GET /api/member/links) */
export interface LinkForDevice {
	token: string
	title: string
	composer: string
	expires: string | null
	offline?: boolean
	scan?: boolean
}

export type RequestKind = 'page' | 'score' | 'scan' | 'asset' | 'other'

/** Paths that never come from the device: login, the session, the APIs of Nextcloud, avatars */
const NEVER_KEPT = ['/ocs/', '/remote.php', '/avatar/', '/login', '/logout', '/csrftoken', '/heartbeat', '/status.php']

/** Types of requests that make up the page */
const ASSET_DESTINATIONS = ['script', 'style', 'font', 'image', 'manifest']

const MEMBER_DATA = /^api\/member\/links\/([A-Za-z0-9]{32})\/(score|scan)$/

/**
 * What a request of a page in the scope of the worker is.
 *
 * @param path - pathname of the request
 * @param scopePath - pathname of the scope, ending in "/" (e.g. /index.php/apps/notenarchiv/)
 * @param destination - Request.destination ('' for fetch())
 * @param navigate - the request opens a page (Request.mode === 'navigate')
 */
export function requestKind(path: string, scopePath: string, destination: string, navigate: boolean): { kind: RequestKind, token?: string } {
	if (NEVER_KEPT.some((part) => path.includes(part))) {
		return { kind: 'other' }
	}
	if (path.startsWith(scopePath)) {
		const inner = path.slice(scopePath.length)
		if (inner === '' && navigate) {
			return { kind: 'page' }
		}
		const data = MEMBER_DATA.exec(inner)
		if (data) {
			return { kind: data[2] as 'score' | 'scan', token: data[1] }
		}
		if (inner.startsWith('api/') || navigate) {
			return { kind: 'other' }
		}
	}
	return !navigate && ASSET_DESTINATIONS.includes(destination) ? { kind: 'asset' } : { kind: 'other' }
}

/**
 * The day of a time in the time zone of the device (YYYY-MM-DD), as links
 * expire at the end of a day.
 *
 * @param time - the time
 */
export function localDay(time: Date): string {
	const pad = (value: number) => String(value).padStart(2, '0')
	return `${time.getFullYear()}-${pad(time.getMonth() + 1)}-${pad(time.getDate())}`
}

/**
 * Whether a kept piece may be shown: 'ok', 'expired' when its link ended,
 * 'unchecked' after MAX_DAYS_UNCHECKED days without a check with the
 * server, 'unknown' when it is not kept.
 *
 * @param catalog - the list of kept pieces, null when there is none
 * @param token - token of the link
 * @param now - the time now
 */
export function usable(catalog: Catalog | null, token: string, now: Date): 'ok' | 'expired' | 'unchecked' | 'unknown' {
	const piece = catalog?.pieces[token]
	if (!catalog || !piece) {
		return 'unknown'
	}
	if (piece.expires !== null && piece.expires < localDay(now)) {
		return 'expired'
	}
	const checked = Date.parse(catalog.checked)
	if (!Number.isFinite(checked) || now.getTime() - checked > MAX_DAYS_UNCHECKED * 24 * 60 * 60 * 1000 || checked > now.getTime() + 60 * 60 * 1000) {
		return 'unchecked'
	}
	return 'ok'
}

/**
 * The list of kept pieces from its JSON, or null when it is missing or
 * broken (then nothing counts as kept).
 *
 * @param text - JSON of the list
 */
export function parseCatalog(text: string | null): Catalog | null {
	if (text === null) {
		return null
	}
	try {
		const parsed = JSON.parse(text) as Partial<Catalog>
		if (parsed?.v !== 1 || typeof parsed.account !== 'string' || typeof parsed.checked !== 'string'
			|| typeof parsed.pieces !== 'object' || parsed.pieces === null) {
			return null
		}
		const pieces: Record<string, KeptPiece> = {}
		for (const [token, piece] of Object.entries(parsed.pieces)) {
			if (/^[A-Za-z0-9]{32}$/.test(token) && typeof piece?.title === 'string' && typeof piece.kept === 'string') {
				pieces[token] = {
					title: piece.title,
					composer: typeof piece.composer === 'string' ? piece.composer : '',
					expires: typeof piece.expires === 'string' ? piece.expires : null,
					scan: piece.scan === true,
					kept: piece.kept,
				}
			}
		}
		return { v: 1, account: parsed.account, checked: parsed.checked, pieces }
	} catch {
		return null
	}
}

/**
 * The list of kept pieces after a check with the server: only links that
 * are still shared and may still be kept stay, with the title and end date
 * of the server; a scan stays only while the link still offers it.
 *
 * @param catalog - the list so far
 * @param links - the links of the account on the server now
 * @param now - the time of the check
 */
export function syncCatalog(catalog: Catalog, links: LinkForDevice[], now: Date): { catalog: Catalog, removed: string[], scansRemoved: string[] } {
	const byToken = new Map(links.map((link) => [link.token, link]))
	const pieces: Record<string, KeptPiece> = {}
	const removed: string[] = []
	const scansRemoved: string[] = []
	for (const [token, piece] of Object.entries(catalog.pieces)) {
		const link = byToken.get(token)
		if (!link || link.offline !== true) {
			removed.push(token)
			continue
		}
		const scan = piece.scan && link.scan === true
		if (piece.scan && !scan) {
			scansRemoved.push(token)
		}
		pieces[token] = { title: link.title, composer: link.composer, expires: link.expires, scan, kept: piece.kept }
	}
	return { catalog: { ...catalog, checked: now.toISOString(), pieces }, removed, scansRemoved }
}
