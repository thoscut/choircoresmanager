/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Passages to practise (milestone 0.13): measure ranges with a name of
 * one's own, per piece, remembered in this browser only. Nothing about them
 * goes to the server.
 */
import { readStored, removeStored, writeStored } from '../storage.ts'

/** A remembered passage, in printed measure numbers. */
export interface Mark {
	name: string
	from: string
	to: string
}

/** Most passages per piece; the oldest goes first */
export const MAX_MARKS = 20
/** Most characters of a name */
export const MAX_NAME = 60

const STORAGE_PREFIX = 'marks.'

/**
 * Whether a value read from the storage is a passage.
 *
 * @param value - anything
 */
function isMark(value: unknown): value is Mark {
	const mark = value as Mark
	return typeof mark === 'object' && mark !== null
		&& typeof mark.name === 'string' && typeof mark.from === 'string' && typeof mark.to === 'string'
}

/**
 * The passages of a piece remembered in this browser.
 *
 * @param piece - key of the piece, e.g. "copy:12" or "piece:" and the practice key of a shared piece
 */
export function storedMarks(piece: string): Mark[] {
	try {
		const parsed: unknown = JSON.parse(readStored(STORAGE_PREFIX + piece) ?? '[]')
		return Array.isArray(parsed) ? parsed.filter(isMark).slice(0, MAX_MARKS) : []
	} catch {
		return []
	}
}

/**
 * Remember the passages of a piece in this browser.
 *
 * @param piece - key of the piece
 * @param marks - the passages; none removes the entry
 */
export function storeMarks(piece: string, marks: Mark[]): void {
	if (marks.length === 0) {
		removeStored(STORAGE_PREFIX + piece)
	} else {
		writeStored(STORAGE_PREFIX + piece, JSON.stringify(marks))
	}
}

/**
 * The passages with a new one at the end. A passage with the same measures
 * gets the new name; beyond MAX_MARKS the oldest is dropped.
 *
 * @param marks - the passages so far
 * @param mark - the new passage; an empty name becomes the measures
 * @param fallbackName - name for an empty one, e.g. "m. 5–12"
 */
export function addMark(marks: Mark[], mark: Mark, fallbackName: string): Mark[] {
	const name = mark.name.trim().slice(0, MAX_NAME) || fallbackName
	const others = marks.filter((other) => other.from !== mark.from || other.to !== mark.to)
	return [...others, { name, from: mark.from, to: mark.to }].slice(-MAX_MARKS)
}
