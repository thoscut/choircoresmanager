/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Following the playback: keep the sounding measure in view. On a large
 * screen the score scrolls in its own box; on a phone it is part of the page
 * and the page (or the dialog) scrolls. So the scrolling element is looked
 * up, not assumed.
 */

/** A vertical span on the screen, in pixels from the top of the window. */
export interface Span {
	top: number
	bottom: number
}

/**
 * How far to scroll so that a measure is well in view: 0 when it is
 * already, otherwise the distance that puts it a quarter of the view (at
 * least a margin) below the top.
 *
 * @param box - the measure
 * @param view - the visible part of the scrolling element, without what a bar covers
 */
export function followDelta(box: Span, view: Span): number {
	const height = Math.max(0, view.bottom - view.top)
	const margin = Math.min(80, height / 5)
	if (box.top >= view.top + margin && box.bottom <= view.bottom - margin) {
		return 0
	}
	return Math.round(box.top - view.top - Math.max(margin, (height - (box.bottom - box.top)) / 4))
}

/**
 * The element that scrolls vertically: the element itself or its nearest
 * ancestor with content beyond its height; else the page.
 *
 * @param element - where to start
 */
export function scrollingParent(element: Element): Element {
	for (let node: Element | null = element; node !== null; node = node.parentElement) {
		const overflow = getComputedStyle(node).overflowY
		if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
			return node
		}
	}
	return document.scrollingElement ?? document.documentElement
}

/**
 * Scroll the measure into view, below a bar at the top that stays in place
 * (position: sticky) and covers part of the view.
 *
 * @param measure - the measure to show
 * @param score - the box of the score
 * @param bar - a bar that covers the top of the view, if any
 */
export function followMeasure(measure: Element, score: Element, bar: Element | null = null): void {
	const scroller = scrollingParent(score)
	const page = scroller === document.scrollingElement || scroller === document.documentElement
	const outer = page ? { top: 0, bottom: window.innerHeight } : scroller.getBoundingClientRect()
	const covered = bar?.getBoundingClientRect().bottom ?? 0
	const view = {
		top: Math.max(outer.top, 0, covered < outer.bottom ? covered : 0),
		bottom: Math.min(outer.bottom, window.innerHeight),
	}
	const delta = followDelta(measure.getBoundingClientRect(), view)
	if (delta !== 0) {
		const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
		scroller.scrollBy({ top: delta, behavior: reduceMotion ? 'auto' : 'smooth' })
	}
}
