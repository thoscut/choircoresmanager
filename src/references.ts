/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Text references to a place in a score, e.g.
 * "5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note".
 * They contain no notation and may always be copied and passed on. The
 * words ("p.", "m.") come translated from the caller, so this module stays
 * free of Vue and l10n and can be tested on its own.
 */

/** A place in a score; every part is optional except the shelf mark. */
export interface ScorePlace {
	shelfmark: string
	title?: string | null
	/** revision of the score, e.g. "Revision 3" (milestone 0.8) */
	revision?: string | null
	page?: number | string | null
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | string | null
	note?: string | null
}

/** Translated words of a reference. */
export interface ReferenceWords {
	/** e.g. "p. {page}" → "p. 2" */
	page: (page: string) => string
	/** one measure, e.g. "m. 17" */
	measure: (measure: string) => string
	/** a range, e.g. "m. 17–24" */
	measures: (from: string, to: string) => string
	/** e.g. "verse 2" */
	verse: (verse: string) => string
}

/** Separator between the parts of a reference. */
export const SEPARATOR = ' · '

/**
 * Trimmed text of a value, '' for empty values.
 *
 * @param value - any part of a place
 */
function text(value: number | string | null | undefined): string {
	return value === null || value === undefined ? '' : String(value).replace(/\s+/g, ' ').trim()
}

/**
 * The measure part: one measure, a range, or '' if no measure is given.
 *
 * @param from - first measure (printed number)
 * @param to - last measure (printed number)
 * @param words - translated words
 */
export function measurePart(from: string | null | undefined, to: string | null | undefined, words: ReferenceWords): string {
	const first = text(from)
	const last = text(to)
	if (first === '' && last === '') {
		return ''
	}
	if (first === '' || last === '' || first === last) {
		return words.measure(first || last)
	}
	return words.measures(first, last)
}

/**
 * Where in the score (without shelf mark and title): page, measures, voice,
 * verse. Used for task lists.
 *
 * @param place - the place
 * @param words - translated words
 */
export function placeText(place: Omit<ScorePlace, 'shelfmark' | 'title' | 'revision' | 'note'>, words: ReferenceWords): string {
	const parts: string[] = []
	if (text(place.page) !== '') {
		parts.push(words.page(text(place.page)))
	}
	const measures = measurePart(place.measureFrom, place.measureTo, words)
	if (measures !== '') {
		parts.push(measures)
	}
	if (text(place.voice) !== '') {
		parts.push(text(place.voice))
	}
	if (text(place.verse) !== '') {
		parts.push(words.verse(text(place.verse)))
	}
	return parts.join(SEPARATOR)
}

/**
 * The complete text reference.
 *
 * @param place - the place
 * @param words - translated words
 */
export function textReference(place: ScorePlace, words: ReferenceWords): string {
	const parts = [text(place.shelfmark), text(place.title), text(place.revision), placeText(place, words), text(place.note)]
	return parts.filter((part) => part !== '').join(SEPARATOR)
}
