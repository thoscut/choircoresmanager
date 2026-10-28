/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Catalog } from '../offline/rules.ts'

import { describe, expect, it } from 'vitest'
import { localDay, MAX_DAYS_UNCHECKED, parseCatalog, requestKind, syncCatalog, usable } from '../offline/rules.ts'

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
		expect(requestKind(SCOPE, SCOPE, 'document', true)).toEqual({ kind: 'page' })
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/score`, SCOPE, '', false)).toEqual({ kind: 'score', token: TOKEN })
		// the scan opens in a tab of its own
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/scan`, SCOPE, 'document', true)).toEqual({ kind: 'scan', token: TOKEN })
		expect(requestKind('/apps/notenarchiv/js/notenarchiv-member.mjs', SCOPE, 'script', false)).toEqual({ kind: 'asset' })
		expect(requestKind('/apps/theming/theme/light.css', SCOPE, 'style', false)).toEqual({ kind: 'asset' })
	})

	it('never cover the APIs, the login, other pages of the app or writing routes', () => {
		expect(requestKind(`${SCOPE}api/member/links`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}api/member/links/${TOKEN}/practice-audio`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}api/entries`, SCOPE, '', false).kind).toBe('other')
		expect(requestKind(`${SCOPE}p/${TOKEN}`, SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/ocs/v2.php/apps/notifications/api/v2/notifications', SCOPE, '', false).kind).toBe('other')
		expect(requestKind('/index.php/avatar/anna/64', SCOPE, 'image', false).kind).toBe('other')
		expect(requestKind('/index.php/login', SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/index.php/apps/files/', SCOPE, 'document', true).kind).toBe('other')
		expect(requestKind('/index.php/csrftoken', SCOPE, '', false).kind).toBe('other')
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
			{ token: TOKEN, title: 'Abendlied (neu)', composer: 'J. Rheinberger', expires: '2026-12-31', offline: true, scan: false },
			{ token: OTHER, title: 'Other', composer: '', expires: null, offline: false },
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

	it('expire at the end of their last day on the device', () => {
		expect(localDay(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05')
	})
})
