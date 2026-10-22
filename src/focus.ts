/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Focus helpers for keyboard and screen reader users (WCAG 2.4.3 focus
 * order). When an action removes or disables the control that had the
 * focus (a button that disappears after saving, a form that closes), the
 * browser puts the focus back to the start of the page. These helpers move
 * it to a sensible place instead: the next control, a heading or a message.
 */
import type { MaybeRefOrGetter } from 'vue'

import { nextTick, toValue } from 'vue'

/** An element, a component instance (NcButton, …) or nothing. */
export type FocusTarget = Element | { $el?: unknown } | null | undefined

const FOCUSABLE = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'

/**
 * The DOM element of an element or component reference.
 *
 * @param target - element or component instance
 */
export function elementOf(target: FocusTarget): HTMLElement | null {
	if (target instanceof HTMLElement) {
		return target
	}
	const element = target && !(target instanceof Element) ? target.$el : null
	return element instanceof HTMLElement ? element : null
}

/**
 * Focus an element. Form controls and buttons get the focus directly; a
 * wrapper (e.g. of a text field) passes it to its first control; anything
 * else (a heading, a message) becomes focusable for scripts only
 * (tabindex="-1"), so that a screen reader reads it.
 *
 * @param target - element or component instance
 * @return whether the focus could be set
 */
export function focusElement(target: FocusTarget): boolean {
	const element = elementOf(target)
	if (!element || !element.isConnected) {
		return false
	}
	let focusable: HTMLElement | null = element.matches(FOCUSABLE) ? element : null
	if (focusable === null && !/^H[1-6]$/.test(element.tagName) && !element.matches('[role="status"], [role="alert"], .notecard')) {
		focusable = element.querySelector<HTMLElement>(FOCUSABLE)
	}
	if (focusable === null) {
		if (!element.hasAttribute('tabindex')) {
			element.setAttribute('tabindex', '-1')
		}
		focusable = element
	}
	focusable.focus()
	return document.activeElement === focusable
}

/** Resolves after the next frames, when dialogs have finished closing. */
function afterFrames(): Promise<void> {
	return new Promise((resolve) => {
		window.requestAnimationFrame(() => window.requestAnimationFrame(() => window.setTimeout(resolve, 0)))
	})
}

/**
 * Focus an element once Vue has updated the page, e.g. a button that only
 * appears after an action.
 *
 * @param target - element, component instance, a ref or a function returning one
 */
export async function focusAfterUpdate(target: MaybeRefOrGetter<FocusTarget>): Promise<boolean> {
	await nextTick()
	return focusElement(toValue(target))
}

/**
 * Focus an element after a dialog of Nextcloud (e.g. the file picker) has
 * closed. Such a dialog returns the focus when it closes, but the dialog
 * underneath it then moves the focus to its first control; this puts the
 * focus back where the user was working.
 *
 * @param target - element, component instance, a ref or a function returning one
 */
export async function focusAfterDialog(target: MaybeRefOrGetter<FocusTarget>): Promise<boolean> {
	await nextTick()
	await afterFrames()
	return focusElement(toValue(target))
}

/**
 * Focus an element as soon as it is in the page, e.g. the heading of a view
 * that loads its data first. Gives up after `timeout` milliseconds.
 *
 * @param id - id of the element
 * @param timeout - how long to wait (ms)
 */
export function focusWhenPresent(id: string, timeout = 5000): Promise<boolean> {
	return new Promise((resolve) => {
		const tryFocus = (): boolean => {
			const element = document.getElementById(id)
			return element !== null && focusElement(element)
		}
		if (tryFocus()) {
			resolve(true)
			return
		}
		const observer = new MutationObserver(() => {
			if (tryFocus()) {
				observer.disconnect()
				window.clearTimeout(timer)
				resolve(true)
			}
		})
		const timer = window.setTimeout(() => {
			observer.disconnect()
			resolve(false)
		}, timeout)
		observer.observe(document.body, { childList: true, subtree: true })
	})
}
