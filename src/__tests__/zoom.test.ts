/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { nextZoom, storedZoom, storeZoom, ZOOM_DEFAULT, ZOOM_STEPS } from '../score/zoom.ts'

afterEach(() => {
	window.localStorage.clear()
})

describe('size of the notes', () => {
	it('is remembered in this browser', () => {
		expect(storedZoom()).toBe(ZOOM_DEFAULT)
		storeZoom(9)
		expect(storedZoom()).toBe(9)
	})

	it('ignores a stored value outside the steps', () => {
		window.localStorage.setItem('cantoria.zoom', '99')
		expect(storedZoom()).toBe(ZOOM_DEFAULT)
		window.localStorage.setItem('cantoria.zoom', 'big')
		expect(storedZoom()).toBe(ZOOM_DEFAULT)
	})

	it('stays within the steps', () => {
		expect(nextZoom(0, -1)).toBe(0)
		expect(nextZoom(ZOOM_STEPS.length - 1, 1)).toBe(ZOOM_STEPS.length - 1)
		expect(nextZoom(3, 1)).toBe(4)
	})
})
