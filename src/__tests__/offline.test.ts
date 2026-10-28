/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Catalog } from '../offline/rules.ts'

import { describe, expect, it } from 'vitest'
import { COLLECTIONS, localDay, MAX_DAYS_UNCHECKED, pageCollection, parseCatalog, requestKind, syncCatalog, usable } from '../offline/rules.ts'

const SCOPE = '/index.php/apps/notenarchiv/'
const TOKEN = 'a'.repeat(32)
const OTHER = 'b'.repeat(32)

function catalog(checked: string, expires: string | null = null): Catalog {
	return {
		v: 1,
		account: 'x',
		checked,
		pieces: { [TOKEN]: { title: 'Abendlied', composer: 'Rheinberger', expires, scan: true, kept: checked } },
	}
}

describe('requests of the page for choir members', () => {
	it('are the page, a score, a scan or an asset', () => {
		expect(requestKind(SCOPE, SCOPE, 'document', true)).toEqual({ kind: 'page', collection: 'member' })
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/score`, SCOPE, '', false)).toEqual({ kind: 'score', collection: 'member', key: TOKEN })
		// the scan opens in the page or in a tab of its own
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/scan`, SCOPE, 'document', true)).toEqual({ kind: 'scan', collection: 'member', key: TOKEN })
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/scan`, SCOPE, '', false)).toEqual({ kind: 'scan', collection: 'member', key: TOKEN })
		expect(requestKind('/apps/notenarchiv/js/notenarchiv-member.mjs', SCOPE, 'script', false)).toEqual({ kind: 'asset' })
		expect(requestKind('/apps/theming/theme/light.css', SCOPE, 'style', false)).toEqual({ kind: 'asset' })
	})

	it('never cover the APIs, the login, other pages of the app or writing routes', () => {
		expect(requestKind(`${SCOPE}api/member/links`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/practice-audio`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}api/entries`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}concert-folder/x`, SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind(`${SCOPE}api/concert-folder`, SCOPE, '', false).kind).toBe('other')
		// the page of a public link, fetched by the page itself (not opened), is not answered from the device
		expect(requestKind(`${SCOPE}p/${TOKEN}`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}p/short`, SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/ocs/v2.php/apps/notifications/api/v2/notifications', SCOPE, '', false).kind).toBe('other')
		expect(requestKind('/index.php/avatar/anna/64', SCOPE, 'image', false).kind).toBe('other')
		expect(requestKind('/index.php/login', SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/index.php/apps/files/', SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/index.php/csrftoken', SCOPE, '', false).kind).toBe('other')
	})
})

describe('requests of the concert folder and of public links', () => {
	it('are their page, a score or a scan', () => {
		expect(requestKind(`${SCOPE}concert-folder`, SCOPE, 'document', true)).toEqual({ kind: 'page', collection: 'folder' })
		expect(requestKind(`${SCOPE}api/concert-folder/items/42/score`, SCOPE, '', false)).toEqual({ kind: 'score', collection: 'folder', key: '42' })
		expect(requestKind(`${SCOPE}api/concert-folder/items/42/scan`, SCOPE, '', false)).toEqual({ kind: 'scan', collection: 'folder', key: '42' })
		expect(requestKind(`${SCOPE}p/${TOKEN}`, SCOPE, 'document', true)).toEqual({ kind: 'page', collection: 'public', key: TOKEN })
		expect(requestKind(`${SCOPE}p/${TOKEN}/scan`, SCOPE, '', false)).toEqual({ kind: 'scan', collection: 'public', key: TOKEN })
	})

	it('name the page whose scripts are kept, never the archive', () => {
		expect(pageCollection(SCOPE, SCOPE)).toBe('member')
		expect(pageCollection(`${SCOPE}concert-folder`, SCOPE)).toBe('folder')
		expect(pageCollection(`${SCOPE}p/${TOKEN}`, SCOPE)).toBe('public')
		expect(pageCollection(`${SCOPE}settings`, SCOPE)).toBeNull()
		expect(pageCollection('/index.php/apps/files/', SCOPE)).toBeNull()
	})

	it('have caches of their own', () => {
		const caches = Object.values(COLLECTIONS).map((collection) => collection.cache)
		expect(new Set(caches).size).toBe(caches.length)
		expect(caches.every((cache) => cache.startsWith('notenarchiv-') && !cache.startsWith('notenarchiv-shell-'))).toBe(true)
	})
})

describe('pieces kept on the device', () => {
	const now = new Date(2026, 9, 28, 12)

	it('may be shown while the link is valid and the last check is recent', () => {
		expect(usable(catalog(new Date(2026, 9, 20).toISOString(), '2026-10-28'), TOKEN, now)).toBe('ok')
		expect(usable(catalog(new Date(2026, 9, 20).toISOString(), '2026-10-27'), TOKEN, now)).toBe('expired')
		expect(usable(catalog(new Date(2026, 9, 20).toISOString()), OTHER, now)).toBe('unknown')
		expect(usable(null, TOKEN, now)).toBe('unknown')
	})

	it(`are locked after ${MAX_DAYS_UNCHECKED} days without a check, and for a check in the future`, () => {
		expect(usable(catalog(new Date(2026, 8, 29, 13).toISOString()), TOKEN, now)).toBe('ok')
		expect(usable(catalog(new Date(2026, 8, 27).toISOString()), TOKEN, now)).toBe('unchecked')
		expect(usable(catalog(new Date(2027, 0, 1).toISOString()), TOKEN, now)).toBe('unchecked')
		expect(usable(catalog('not a date'), TOKEN, now)).toBe('unchecked')
	})

	it('follow the links on the server at a check', () => {
		const before = { ...catalog('2026-10-01T00:00:00.000Z'), pieces: { ...catalog('2026-10-01T00:00:00.000Z').pieces, [OTHER]: catalog('x').pieces[TOKEN] } }
		const { catalog: after, removed, scansRemoved } = syncCatalog(before, [
			{ key: TOKEN, title: 'Abendlied (neu)', composer: 'J. Rheinberger', expires: '2026-12-31', offline: true, scan: false },
			{ key: OTHER, title: 'Other', composer: '', expires: null, offline: false },
		], now)
		expect(removed).toEqual([OTHER])
		expect(scansRemoved).toEqual([TOKEN])
		expect(after.checked).toBe(now.toISOString())
		expect(after.pieces[TOKEN]).toEqual({ title: 'Abendlied (neu)', composer: 'J. Rheinberger', expires: '2026-12-31', scan: false, kept: '2026-10-01T00:00:00.000Z' })
		expect(Object.keys(after.pieces)).toEqual([TOKEN])
		expect(syncCatalog(before, [], now).removed).toEqual([TOKEN, OTHER])
	})

	it('come from a list that is checked when read', () => {
		const good = catalog('2026-10-01T00:00:00.000Z')
		expect(parseCatalog(JSON.stringify(good))).toEqual(good)
		expect(parseCatalog(null)).toBeNull()
		expect(parseCatalog('{broken')).toBeNull()
		expect(parseCatalog(JSON.stringify({ ...good, v: 2 }))).toBeNull()
		const odd = parseCatalog(JSON.stringify({ ...good, pieces: { short: good.pieces[TOKEN], [OTHER]: { title: 3 } } }))
		expect(odd?.pieces).toEqual({})
	})

	it('of a public link count from their own last check', () => {
		const list = catalog(new Date(2026, 8, 1).toISOString())
		list.pieces[TOKEN].checked = new Date(2026, 9, 20).toISOString()
		expect(usable(list, TOKEN, now)).toBe('ok')
		delete list.pieces[TOKEN].checked
		expect(usable(list, TOKEN, now)).toBe('unchecked')
	})

	it('of the concert folder are kept by the id of the programme item', () => {
		const list = { v: 1, account: 'x', checked: 'x', pieces: { 42: catalog('x').pieces[TOKEN], [TOKEN]: catalog('x').pieces[TOKEN] } }
		expect(Object.keys(parseCatalog(JSON.stringify(list), COLLECTIONS.folder.key)?.pieces ?? {})).toEqual(['42'])
		expect(Object.keys(parseCatalog(JSON.stringify(list))?.pieces ?? {})).toEqual([TOKEN])
	})

	it('expire at the end of their last day on the device', () => {
		expect(localDay(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05')
	})
})
