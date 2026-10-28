/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pieces kept on this device for practising without a network (milestone
 * 0.15), from the page "Shared with me": registering the service worker,
 * keeping and forgetting a piece, the check with the server and the space
 * used. Everything stays in the browser (Cache Storage); nothing is kept
 * without a click, and only for links whose rights allow saving. The rules
 * are in rules.ts, the worker in worker.ts.
 */
import type { MemberLink } from '../types.ts'
import type { Catalog } from './rules.ts'

import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { preloadToolkit } from '../score/toolkit.ts'
import { CACHE_PREFIX, CATALOG_NAME, DATA_CACHE, parseCatalog, requestKind, SHELL_PREFIX, syncCatalog } from './rules.ts'

/** Whether this browser can keep pieces: a service worker and Cache Storage, on HTTPS */
export function offlineSupported(): boolean {
	return typeof window !== 'undefined' && window.isSecureContext === true
		&& 'serviceWorker' in navigator && 'caches' in window
}

/** Absolute address of the scope: the app's path, which covers the page */
function scopeUrl(): string {
	return new URL(generateUrl('/apps/notenarchiv/'), window.location.href).href
}

/**
 * Key of a kept answer: the address without query.
 *
 * @param path - path of the app's route
 */
function dataKey(path: string): string {
	return new URL(generateUrl(path), window.location.href).href
}

function scoreKey(token: string): string {
	return dataKey(`/apps/notenarchiv/api/member/links/${token}/score`)
}

function scanKey(token: string): string {
	return dataKey(`/apps/notenarchiv/api/member/links/${token}/scan`)
}

function catalogKey(): string {
	return new URL(CATALOG_NAME, scopeUrl()).href
}

/**
 * Register the service worker when the module is on; remove it and
 * everything kept when it is off.
 *
 * @param enabled - module "offline" on
 * @return whether the worker is registered
 */
export async function setUpWorker(enabled: boolean): Promise<boolean> {
	if (!offlineSupported()) {
		return false
	}
	if (!enabled) {
		for (const registration of await navigator.serviceWorker.getRegistrations()) {
			if (registration.scope === scopeUrl()) {
				await registration.unregister()
			}
		}
		await removeEverything()
		return false
	}
	try {
		await navigator.serviceWorker.register(generateUrl('/apps/notenarchiv/offline-worker'), { scope: generateUrl('/apps/notenarchiv/') })
		return true
	} catch (error) {
		console.warn('Notenarchiv: the service worker for practising without a network could not be registered', error)
		return false
	}
}

/**
 * The hash of the account, so that the pieces of one account are not shown
 * to another one on the same browser.
 */
export async function accountKey(): Promise<string> {
	const uid = getCurrentUser()?.uid ?? ''
	const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('notenarchiv|' + uid))
	return Array.from(new Uint8Array(digest).slice(0, 16), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/** The list of kept pieces on this device, or null. */
export async function readCatalog(): Promise<Catalog | null> {
	if (!offlineSupported() || !(await caches.has(DATA_CACHE))) {
		return null
	}
	const response = await (await caches.open(DATA_CACHE)).match(catalogKey())
	return parseCatalog(response ? await response.text() : null)
}

async function writeCatalog(catalog: Catalog): Promise<void> {
	const cache = await caches.open(DATA_CACHE)
	await cache.put(catalogKey(), new Response(JSON.stringify(catalog), { headers: { 'Content-Type': 'application/json' } }))
}

/**
 * Fetch an address and keep the answer; throws with the server's message.
 *
 * @param cache - the cache
 * @param url - address
 * @param key - key in the cache
 */
async function keep(cache: Cache, url: string, key: string): Promise<void> {
	const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { requesttoken: getRequestToken() ?? '' } })
	if (!response.ok || response.redirected) {
		let message = ''
		try {
			message = ((await response.json()) as { error?: string }).error ?? ''
		} catch {
			// no JSON
		}
		throw new Error(message)
	}
	await cache.put(key, response)
}

/**
 * Keep a piece on this device: its notes, on request the scan, the page
 * and its scripts. Needs the network.
 *
 * @param link - the link
 * @param withScan - also keep the scan (PDF), when the link offers it
 * @param version - version of the app (cache of the scripts)
 */
export async function keepPiece(link: MemberLink, withScan: boolean, version: string): Promise<Catalog> {
	const account = await accountKey()
	let catalog = await readCatalog()
	if (catalog !== null && catalog.account !== account) {
		await removeEverything()
		catalog = null
	}
	const cache = await caches.open(DATA_CACHE)
	await keep(cache, generateUrl('/apps/notenarchiv/api/member/links/{token}/score', { token: link.token }), scoreKey(link.token))
	const scan = withScan && link.scan === true
	if (scan) {
		await keep(cache, generateUrl('/apps/notenarchiv/api/member/links/{token}/scan', { token: link.token }), scanKey(link.token))
	} else {
		await cache.delete(scanKey(link.token))
	}
	const page = await fetch(scopeUrl(), { credentials: 'same-origin', cache: 'no-store' })
	if (page.ok && page.headers.get('X-Notenarchiv-Page') === 'member') {
		await cache.put(scopeUrl(), page)
	}
	const now = new Date().toISOString()
	const next: Catalog = {
		v: 1,
		account,
		checked: catalog?.checked ?? now,
		pieces: {
			...(catalog?.pieces ?? {}),
			[link.token]: { title: link.title, composer: link.composer, expires: link.expires, scan, kept: now },
		},
	}
	await writeCatalog(next)
	await keepShell(version)
	return next
}

/**
 * Keep the scripts, styles, fonts and images of the page, including the
 * notation engine, which loads only with the first score.
 *
 * @param version - version of the app
 */
export async function keepShell(version: string): Promise<void> {
	try {
		await preloadToolkit()
	} catch {
		// kept with the next score shown
	}
	const scopePath = new URL(scopeUrl()).pathname
	const urls = new Set<string>()
	const add = (address: string | null | undefined) => {
		if (!address) {
			return
		}
		const url = new URL(address, window.location.href)
		if (url.origin === window.location.origin && requestKind(url.pathname, scopePath, 'script', false).kind === 'asset') {
			urls.add(url.href)
		}
	}
	for (const entry of performance.getEntriesByType('resource') as PerformanceResourceTiming[]) {
		if (['script', 'link', 'css', 'img', 'other'].includes(entry.initiatorType)) {
			add(entry.name)
		}
	}
	document.querySelectorAll<HTMLScriptElement>('script[src]').forEach((script) => add(script.src))
	document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"], link[rel="modulepreload"], link[rel="icon"]').forEach((link) => add(link.href))
	const shell = await caches.open(SHELL_PREFIX + version)
	await Promise.all([...urls].map(async (url) => {
		if (await shell.match(url)) {
			return
		}
		try {
			const response = await fetch(url, { credentials: 'same-origin' })
			if (response.ok) {
				await shell.put(url, response)
			}
		} catch {
			// kept the next time it loads
		}
	}))
}

/**
 * Remove a piece from this device.
 *
 * @param token - token of the link
 */
export async function forgetPiece(token: string): Promise<Catalog | null> {
	const catalog = await readCatalog()
	if (catalog === null) {
		return null
	}
	const cache = await caches.open(DATA_CACHE)
	await cache.delete(scoreKey(token))
	await cache.delete(scanKey(token))
	const pieces = { ...catalog.pieces }
	delete pieces[token]
	if (Object.keys(pieces).length === 0) {
		await removeEverything()
		return null
	}
	const next = { ...catalog, pieces }
	await writeCatalog(next)
	return next
}

/** Delete every cache of the app on this device. */
export async function removeEverything(): Promise<void> {
	if (!offlineSupported()) {
		return
	}
	for (const key of await caches.keys()) {
		if (key.startsWith(CACHE_PREFIX)) {
			await caches.delete(key)
		}
	}
}

/**
 * The check with the server at a start with a network: expired, revoked
 * and no longer allowed pieces are removed, the rest is checked again.
 *
 * @param links - the links of the account on the server now
 */
export async function checkWithServer(links: MemberLink[]): Promise<Catalog | null> {
	const catalog = await readCatalog()
	if (catalog === null) {
		return null
	}
	if (catalog.account !== await accountKey()) {
		await removeEverything()
		return null
	}
	const { catalog: next, removed, scansRemoved } = syncCatalog(catalog, links, new Date())
	const cache = await caches.open(DATA_CACHE)
	for (const token of removed) {
		await cache.delete(scoreKey(token))
		await cache.delete(scanKey(token))
	}
	for (const token of scansRemoved) {
		await cache.delete(scanKey(token))
	}
	if (Object.keys(next.pieces).length === 0) {
		await removeEverything()
		return null
	}
	await writeCatalog(next)
	return next
}

/** Space used and available, and whether the browser keeps it for good. */
export async function storageInfo(): Promise<{ usage: number | null, quota: number | null, persisted: boolean }> {
	try {
		const estimate = await navigator.storage?.estimate?.()
		const persisted = await navigator.storage?.persisted?.() ?? false
		return { usage: estimate?.usage ?? null, quota: estimate?.quota ?? null, persisted }
	} catch {
		return { usage: null, quota: null, persisted: false }
	}
}

/** Ask the browser to keep the pieces even when space runs short. */
export async function askToPersist(): Promise<boolean> {
	try {
		return await navigator.storage?.persist?.() ?? false
	} catch {
		return false
	}
}
