/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ReferenceWords } from '../references.ts'

import { describe, expect, it } from 'vitest'
import { measurePart, placeText, textReference } from '../references.ts'

const ENGLISH: ReferenceWords = {
	page: (page) => `p. ${page}`,
	measure: (measure) => `m. ${measure}`,
	measures: (from, to) => `m. ${from}–${to}`,
	verse: (verse) => `verse ${verse}`,
}

const GERMAN: ReferenceWords = {
	page: (page) => `S. ${page}`,
	measure: (measure) => `T. ${measure}`,
	measures: (from, to) => `T. ${from}–${to}`,
	verse: (verse) => `Str. ${verse}`,
}

describe('textReference', () => {
	it('builds the example of docs/api.md', () => {
		expect(textReference({
			shelfmark: '5-1',
			title: 'Freude, schöner Götterfunken',
			page: 2,
			measureFrom: '17',
			measureTo: '24',
			voice: 'Tenor II',
			note: 'note',
		}, ENGLISH)).toBe('5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note')
	})

	it('uses the words of the UI language', () => {
		expect(textReference({ shelfmark: '9-19', measureFrom: '62', measureTo: '66', voice: 'Tenor II' }, GERMAN))
			.toBe('9-19 · T. 62–66 · Tenor II')
	})

	it('leaves out empty parts and collapses white space', () => {
		expect(textReference({ shelfmark: ' 2-35 ', title: '  Abschied  vom\nWalde ', verse: 3 }, GERMAN))
			.toBe('2-35 · Abschied vom Walde · Str. 3')
	})

	it('is only the shelf mark when nothing else is known', () => {
		expect(textReference({ shelfmark: '10-3' }, ENGLISH)).toBe('10-3')
	})
})

describe('measurePart', () => {
	it('shows a single measure once', () => {
		expect(measurePart('0', '0', ENGLISH)).toBe('m. 0')
		expect(measurePart('12', null, ENGLISH)).toBe('m. 12')
		expect(measurePart('', '34X', ENGLISH)).toBe('m. 34X')
	})

	it('is empty without measures', () => {
		expect(measurePart(null, undefined, ENGLISH)).toBe('')
	})
})

describe('placeText', () => {
	it('lists page, measures, voice and verse in this order', () => {
		expect(placeText({ verse: 2, voice: 'Bass I', measureFrom: '1', measureTo: '8', page: 3 }, GERMAN))
			.toBe('S. 3 · T. 1–8 · Bass I · Str. 2')
	})
})
