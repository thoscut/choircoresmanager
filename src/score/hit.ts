/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What a click or a tap in the score means: the note or the measure under
 * it. On a phone the browser may name the whole score (the <svg>) as the
 * target of a tap, and a finger is wider than a note head; so the point is
 * looked at again, and then the nearest note within reach is taken.
 */

/** A box on the screen, in pixels of the window. */
export interface Box {
	left: number
	top: number
	right: number
	bottom: number
}

/** How far from a note a tap still counts for it, in pixels */
export const TOUCH_REACH = 12

const PLAYABLE = 'g.note[data-id], g.measure[data-id]'

/**
 * Distance from a point to a box: 0 inside.
 *
 * @param box - the box
 * @param x - point, from the left of the window
 * @param y - point, from the top of the window
 */
export function distanceToBox(box: Box, x: number, y: number): number {
	const dx = Math.max(box.left - x, 0, x - box.right)
	const dy = Math.max(box.top - y, 0, y - box.bottom)
	return Math.hypot(dx, dy)
}

/**
 * The box nearest to a point, if it is within reach.
 *
 * @param boxes - the boxes
 * @param x - point, from the left of the window
 * @param y - point, from the top of the window
 * @param reach - largest distance that counts
 * @return its index, or -1
 */
export function nearestBox(boxes: readonly Box[], x: number, y: number, reach: number): number {
	let best = -1
	let bestDistance = reach
	boxes.forEach((box, index) => {
		const distance = distanceToBox(box, x, y)
		if (distance <= bestDistance && (best === -1 || distance < bestDistance)) {
			best = index
			bestDistance = distance
		}
	})
	return best
}

/**
 * The element of the score that a click or a tap names: inside a note or
 * a measure (see closest()), or null.
 *
 * @param event - the click
 * @param pages - the element with the pages of the score
 */
export function scoreTarget(event: MouseEvent, pages: Element | null): Element | null {
	if (!pages) {
		return null
	}
	const direct = event.target instanceof Element ? event.target : null
	if (direct?.closest(PLAYABLE)) {
		return direct
	}
	const at = document.elementFromPoint(event.clientX, event.clientY)
	if (at && pages.contains(at) && at.closest(PLAYABLE)) {
		return at
	}
	const notes = [...pages.querySelectorAll('g.note[data-id]')]
	const index = nearestBox(notes.map((note) => note.getBoundingClientRect()), event.clientX, event.clientY, TOUCH_REACH)
	return index === -1 ? null : notes[index]
}
