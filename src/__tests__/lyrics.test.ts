/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MeiNote } from '../score/mei.ts'
import type { PlayEvent, Timeline } from '../score/timeline.ts'
import type { Voice } from '../score/voices.ts'

import { describe, expect, it } from 'vitest'
import { currentSyllable, lyricLines, lyricSyllables, maskSyllable, syllableFor } from '../score/lyrics.ts'
import { parseMei } from '../score/mei.ts'

const SOPRANO: Voice = { key: '1.1', name: 'Sopran', part: 'Sopran/Alt', number: 1, members: [{ staff: 1, layer: 1 }], notes: 3 }
const ALTO: Voice = { key: '1.2', name: 'Alt', part: 'Sopran/Alt', number: 2, members: [{ staff: 1, layer: 2 }], notes: 3 }
const BASS: Voice = { key: '2.1', name: 'Bass', part: 'Bass', number: 1, members: [{ staff: 2, layer: 1 }], notes: 1 }

/**
 * A note with lyrics.
 *
 * @param id - note id
 * @param layer - layer on staff 1
 * @param lyrics - [verse, text, wordpos]
 */
function note(id: string, layer: number, lyrics: [number, string, string][]): MeiNote {
	return { id, measure: 0, staff: 1, layer, pitch: 60, grace: false, lyrics: lyrics.map(([verse, text, wordpos]) => ({ verse, text, wordpos })) }
}

/**
 * A playback event.
 *
 * @param id - note id
 * @param voice - member key
 * @param start - seconds
 */
function event(id: string, voice: string, start: number): PlayEvent {
	return { id, voice, pitch: 60, start, end: start + 1, sound: true, soundEnd: start + 1, measure: 0 }
}

describe('lyrics along the playback', () => {
	// One measure with a repeat: "Der Mond" in verse 1, "Die Nacht" in verse 2; the alto has no lyrics of its own
	const model = {
		notesByMeasure: [[
			note('a', 1, [[1, 'Der', 's'], [2, 'Die', 's']]),
			note('b', 1, [[1, 'Mond', 's'], [2, 'Nacht', 's']]),
			note('c', 1, [[1, 'ist', 's']]),
			{ id: 'x', measure: 0, staff: 1, layer: 2, pitch: 57, grace: false },
		]],
	}
	const timeline: Timeline = {
		events: [
			event('a', '1.1', 0), event('x', '1.2', 0), event('b', '1.1', 1), event('c', '1.1', 2),
			event('a', '1.1', 3), event('x', '1.2', 3), event('b', '1.1', 4), event('c', '1.1', 5),
		],
		measures: [{ index: 0, start: 0, end: 3 }, { index: 0, start: 3, end: 6 }],
		duration: 6,
	}

	it('takes the verse of each pass, and the one before for a syllable printed once', () => {
		expect(lyricSyllables(model, timeline, SOPRANO).map((syllable) => syllable.text)).toEqual(['Der', 'Mond', 'ist', 'Die', 'Nacht', 'ist'])
	})

	it('gives a voice without lyrics those of its staff, once per time', () => {
		expect(lyricSyllables(model, timeline, ALTO).map((syllable) => `${syllable.text}@${syllable.start}`))
			.toEqual(['Der@0', 'Mond@1', 'ist@2', 'Die@3', 'Nacht@4', 'ist@5'])
		expect(lyricSyllables(model, timeline, BASS)).toEqual([])
	})

	it('chooses a syllable for a pass', () => {
		const lyrics = [{ verse: 1, text: 'a', wordpos: 's' }, { verse: 3, text: 'c', wordpos: 's' }]
		expect(syllableFor(lyrics, 1)?.text).toBe('a')
		expect(syllableFor(lyrics, 2)?.text).toBe('a')
		expect(syllableFor(lyrics, 4)?.text).toBe('c')
		expect(syllableFor([{ verse: 2, text: 'b', wordpos: 's' }], 1)?.text).toBe('b')
		expect(syllableFor([], 1)).toBeNull()
	})

	it('groups the syllables in lines of four measures and finds the sung one', () => {
		const syllables = [0, 1, 3, 4, 9].map((slot, i) => ({ text: String(i), start: i, slot, wordEnd: true }))
		expect(lyricLines(syllables).map((line) => line.map((syllable) => syllable.text).join(''))).toEqual(['012', '3', '4'])
		expect(currentSyllable(syllables, -1)).toBe(-1)
		expect(currentSyllable(syllables, 2.5)).toBe(2)
		expect(currentSyllable(syllables, 10)).toBe(4)
	})
})

describe('learning the lyrics', () => {
	it('hides more letters step by step and keeps the first letter of a word', () => {
		expect(maskSyllable('Abendrot', 0, true)).toBe('Abendrot')
		expect(maskSyllable('Abendrot', 1, true)).toBe('Abe_dr_t')
		expect(maskSyllable('Abendrot', 2, true)).toBe('A_e__r__')
		expect(maskSyllable('Abendrot', 3, true)).toBe('A_______')
		expect(maskSyllable('gen,', 3, false)).toBe('___,')
		expect(maskSyllable('gen', 1, false)).toBe('ge_')
	})
})

describe('lyrics in the MEI', () => {
	it('are read per verse, also from a chord', () => {
		const mei = `<mei><music><body><mdiv><score><scoreDef><staffGrp><staffDef n="1"/></staffGrp></scoreDef><section>
			<measure xml:id="m1" n="1"><staff n="1"><layer n="1">
				<note xml:id="n1" pname="c" oct="4"><verse n="1"><syl wordpos="i" con="d">Hal</syl></verse><verse n="2"><syl wordpos="s">Oh</syl></verse></note>
				<chord xml:id="c1"><verse n="1"><syl wordpos="t">lo</syl></verse><note xml:id="n2" pname="e" oct="4"/><note xml:id="n3" pname="g" oct="4"/></chord>
				<note xml:id="n4" pname="c" oct="4"/>
			</layer></staff></measure></section></score></mdiv></body></music></mei>`
		const notes = parseMei(mei).notes
		expect(notes.find((n) => n.id === 'n1')?.lyrics).toEqual([{ verse: 1, text: 'Hal', wordpos: 'i' }, { verse: 2, text: 'Oh', wordpos: 's' }])
		expect(notes.find((n) => n.id === 'n2')?.lyrics).toEqual([{ verse: 1, text: 'lo', wordpos: 't' }])
		expect(notes.find((n) => n.id === 'n4')?.lyrics).toBeUndefined()
	})
})
