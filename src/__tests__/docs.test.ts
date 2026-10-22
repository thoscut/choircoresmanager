/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import info from '../../appinfo/info.xml?raw'
import adminGuide from '../../docs/admin-guide.md?raw'
import userGuide from '../../docs/user-guide.md?raw'
import { ADMIN_GUIDE, adminGuideUrl, headingAnchor, USER_GUIDE, userGuideUrl } from '../docs.ts'

/**
 * The anchors of the headings of a guide.
 *
 * @param text - Markdown of the guide
 */
function anchors(text: string): string[] {
	return [...text.matchAll(/^#{1,6} (.+)$/gm)].map((match) => headingAnchor(match[1]!))
}

describe('links into the guides', () => {
	it('computes the anchors the way GitHub does', () => {
		expect(headingAnchor('2. Roles and groups')).toBe('2-roles-and-groups')
		expect(headingAnchor('Setting up in five steps')).toBe('setting-up-in-five-steps')
		expect(headingAnchor('"The score cannot be shown"')).toBe('the-score-cannot-be-shown')
	})

	it('point to sections that exist in the administration guide', () => {
		const existing = anchors(adminGuide)
		for (const anchor of Object.values(ADMIN_GUIDE)) {
			expect(existing, anchor).toContain(anchor)
		}
	})

	it('point to sections that exist in the user guide', () => {
		const existing = anchors(userGuide)
		for (const anchor of Object.values(USER_GUIDE)) {
			expect(existing, anchor).toContain(anchor)
		}
	})

	it('point to the documentation named in the app info', () => {
		expect(info).toContain('<admin>' + adminGuideUrl(ADMIN_GUIDE.setup).split('#')[0] + '</admin>')
		expect(info).toContain('<user>' + userGuideUrl(USER_GUIDE.filesNotShared).split('#')[0] + '</user>')
	})
})
