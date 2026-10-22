/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { distanceToBox, nearestBox, TOUCH_REACH } from '../score/hit.ts'

const box = (left: number, top: number, width = 10, height = 10) => ({ left, top, right: left + width, bottom: top + height })

describe('distanceToBox', () => {
	it('is 0 inside and on the edge', () => {
		expect(distanceToBox(box(0, 0), 5, 5)).toBe(0)
		expect(distanceToBox(box(0, 0), 10, 0)).toBe(0)
	})

	it('measures to the nearest edge or corner', () => {
		expect(distanceToBox(box(0, 0), 15, 5)).toBe(5)
		expect(distanceToBox(box(0, 0), 5, -7)).toBe(7)
		expect(distanceToBox(box(0, 0), 13, 14)).toBe(5)
	})
})

describe('nearestBox (a finger on a note head)', () => {
	const notes = [box(100, 100), box(130, 100), box(100, 160)]

	it('takes the note under the finger', () => {
		expect(nearestBox(notes, 135, 105, TOUCH_REACH)).toBe(1)
	})

	it('takes the nearest note within reach', () => {
		expect(nearestBox(notes, 120, 105, TOUCH_REACH)).toBe(0)
		expect(nearestBox(notes, 123, 105, TOUCH_REACH)).toBe(1)
		expect(nearestBox(notes, 105, 120, TOUCH_REACH)).toBe(0)
	})

	it('takes nothing out of reach', () => {
		expect(nearestBox(notes, 105, 135, TOUCH_REACH)).toBe(-1)
		expect(nearestBox([], 0, 0, TOUCH_REACH)).toBe(-1)
	})
})
