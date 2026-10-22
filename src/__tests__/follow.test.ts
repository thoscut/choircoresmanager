/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { followDelta } from '../score/follow.ts'

describe('followDelta', () => {
	// a phone: the page scrolls, the bar covers the top 100 pixels of an 800 pixel window
	const view = { top: 100, bottom: 800 }

	it('leaves a measure in view where it is', () => {
		expect(followDelta({ top: 300, bottom: 420 }, view)).toBe(0)
	})

	it('scrolls a measure below the view up to a quarter of the view', () => {
		// height 700, margin 80; (700 - 120) / 4 = 145 below the top of the view
		expect(followDelta({ top: 900, bottom: 1020 }, view)).toBe(900 - 100 - 145)
	})

	it('scrolls back to a measure behind the bar or above the view', () => {
		expect(followDelta({ top: 60, bottom: 180 }, view)).toBe(60 - 100 - 145)
		expect(followDelta({ top: -500, bottom: -380 }, view)).toBeLessThan(0)
	})

	it('keeps at least the margin for a measure taller than the view', () => {
		expect(followDelta({ top: 900, bottom: 1700 }, view)).toBe(900 - 100 - 80)
	})

	it('needs the margin at both ends', () => {
		expect(followDelta({ top: 150, bottom: 300 }, view)).not.toBe(0)
		expect(followDelta({ top: 600, bottom: 760 }, view)).not.toBe(0)
	})

	it('works in a box of the score as before (no bar)', () => {
		const box = { top: 200, bottom: 600 }
		// margin min(80, 400 / 5) = 80
		expect(followDelta({ top: 290, bottom: 400 }, box)).toBe(0)
		expect(followDelta({ top: 650, bottom: 750 }, box)).toBe(650 - 200 - 80)
	})
})
