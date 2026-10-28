/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What the app remembers in this browser (localStorage): the size of the
 * notes, the count-in, the page of a scan, folded filters, passages and
 * settings for practising (milestones 0.12 to 0.15). Every key starts with
 * "notenarchiv.", so that "Delete everything on this device" finds them all.
 * In a private window or with blocked storage nothing is remembered, and
 * everything still works.
 */

const PREFIX = 'notenarchiv.'

/**
 * A remembered value, or null.
 *
 * @param name - key without the prefix, e.g. "zoom"
 */
export function readStored(name: string): string | null {
	try {
		return window.localStorage.getItem(PREFIX + name)
	} catch {
		return null
	}
}

/**
 * Remember a value in this browser.
 *
 * @param name - key without the prefix
 * @param value - the value
 */
export function writeStored(name: string, value: string): void {
	try {
		window.localStorage.setItem(PREFIX + name, value)
	} catch {
		// private window, blocked or full storage: simply not remembered
	}
}

/**
 * Forget a value.
 *
 * @param name - key without the prefix
 */
export function removeStored(name: string): void {
	try {
		window.localStorage.removeItem(PREFIX + name)
	} catch {
		// nothing to forget
	}
}

/**
 * Forget every value whose key starts with one of the given parts, or
 * everything of the app when none is given.
 *
 * @param starts - keys without the prefix, e.g. "marks." and "practice."
 * @return the number of values forgotten
 */
export function removeStoredStarting(starts: string[] = ['']): number {
	try {
		const keys: string[] = []
		for (let i = 0; i < window.localStorage.length; i++) {
			const key = window.localStorage.key(i)
			if (key !== null && starts.some((start) => key.startsWith(PREFIX + start))) {
				keys.push(key)
			}
		}
		keys.forEach((key) => window.localStorage.removeItem(key))
		return keys.length
	} catch {
		return 0
	}
}
