/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/// <reference lib="webworker" />

/**
 * The service worker of the page "Shared with me" (milestone 0.15),
 * served by MemberController::offlineWorker. It keeps nothing on its own
 * account: only while the page has put pieces on the device (the cache
 * DATA_CACHE exists) does it keep the scripts and styles of the page and
 * answer from the device when the network does not answer. With the
 * network, the network always wins and refreshes what is kept; an expired,
 * revoked or locked piece is never answered from the device. It never
 * caches requests that change something, the APIs of Nextcloud, or the
 * pages of the archive.
 *
 * Built as a classic script of its own (vite.config.ts), without imports.
 */
import { CATALOG_NAME, DATA_CACHE, parseCatalog, requestKind, SHELL_PREFIX, usable } from './rules.ts'

declare const self: ServiceWorkerGlobalScope

/** Replaced by the server with the version of the app */
const VERSION = '__NOTENARCHIV_VERSION__'
const SHELL_CACHE = SHELL_PREFIX + VERSION
/** How long to wait for the network before answering from the device */
const TIMEOUT_MS = 4000

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		// the scripts of an older version of the app are no longer needed
		for (const key of await caches.keys()) {
			if (key.startsWith(SHELL_PREFIX) && key !== SHELL_CACHE) {
				await caches.delete(key)
			}
		}
		await self.clients.claim()
	})())
})

self.addEventListener('fetch', (event) => {
	const request = event.request
	if (request.method !== 'GET' || request.headers.has('range')) {
		return
	}
	const url = new URL(request.url)
	if (url.origin !== self.location.origin) {
		return
	}
	const scope = new URL(self.registration.scope)
	const { kind, token } = requestKind(url.pathname, scope.pathname, request.destination, request.mode === 'navigate')
	if (kind === 'other') {
		return
	}
	event.respondWith(answer(event, kind, token ?? '', url, scope))
})

/**
 * The answer to a request of the page, its scripts or a kept piece.
 *
 * @param event - the fetch event
 * @param kind - what the request is
 * @param token - token of the link (score, scan)
 * @param url - address of the request
 * @param scope - scope of the worker
 */
async function answer(event: FetchEvent, kind: 'page' | 'score' | 'scan' | 'asset', token: string, url: URL, scope: URL): Promise<Response> {
	if (!(await caches.has(DATA_CACHE))) {
		// nothing kept on this device: as without the worker
		return fetch(event.request)
	}
	if (kind === 'asset') {
		const shell = await caches.open(SHELL_CACHE)
		return networkFirst(event, await shell.match(event.request), (response) => {
			if (response.ok && response.type === 'basic') {
				return shell.put(event.request, response)
			}
		})
	}
	const data = await caches.open(DATA_CACHE)
	if (kind === 'page') {
		const key = scope.href
		return networkFirst(event, await data.match(key), async (response) => {
			if (response.ok && response.headers.get('X-Notenarchiv-Page') === 'member') {
				await data.put(key, response)
			} else if (!response.redirected && (response.ok || response.status === 403 || response.status === 404)) {
				// The archive, another account or no access any more: nothing stays on the device
				await removeKept()
			}
			// a redirect to the login keeps everything for the next start with a network
		})
	}
	// score or scan of a link
	const key = url.origin + url.pathname
	const kept = await data.match(key)
	if (!kept) {
		return fetch(event.request)
	}
	const catalog = parseCatalog(await (await data.match(new URL(CATALOG_NAME, scope).href))?.text() ?? null)
	const shown = usable(catalog, token, new Date()) === 'ok' ? kept : undefined
	return networkFirst(event, shown, async (response) => {
		if (response.ok && !response.redirected) {
			await data.put(key, response)
		} else if (response.status === 403 || response.status === 404) {
			// revoked, expired or no longer allowed: removed from the device
			await data.delete(key)
		}
	})
}

/**
 * The network's answer; the kept one when the network fails or takes
 * longer than TIMEOUT_MS. The network's answer refreshes what is kept, also
 * when it comes late.
 *
 * @param event - the fetch event, kept alive until the cache is written
 * @param kept - the kept answer, undefined when there is none or it may not be shown
 * @param store - writes the network's answer (a copy) to the device
 */
function networkFirst(event: FetchEvent, kept: Response | undefined, store: (response: Response) => Promise<void> | void): Promise<Response> {
	const network = fetch(event.request).then((response) => {
		event.waitUntil(Promise.resolve(store(response.clone())).catch(() => undefined))
		return response
	})
	if (!kept) {
		return network
	}
	const late = new Promise<Response>((resolve) => {
		setTimeout(() => resolve(kept), TIMEOUT_MS)
	})
	event.waitUntil(network.then(() => undefined, () => undefined))
	return Promise.race([network.catch(() => kept), late])
}

/** Delete everything the app kept on the device. */
async function removeKept(): Promise<void> {
	for (const key of await caches.keys()) {
		if (key === DATA_CACHE || key.startsWith(SHELL_PREFIX)) {
			await caches.delete(key)
		}
	}
}
