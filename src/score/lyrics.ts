/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The lyrics of one voice along the playback (milestone 0.14): the
 * syllables in playing order with their times, the verse of each pass of a
 * repeat, lines of a few measures for reading along, and a learning mode
 * that hides letters step by step.
 */
import type { MeiNote, MeiSyllable } from './mei.ts'
import type { ScoreModel } from './model.ts'
import type { Timeline } from './timeline.ts'
import type { Voice } from './voices.ts'

import { slotAt } from './countIn.ts'
import { memberKey } from './voices.ts'

/** One syllable to sing, in seconds of the timeline at 100 % tempo. */
export interface LyricSyllable {
	text: string
	start: number
	/** index into timeline.measures */
	slot: number
	/** the last syllable of a word (a space follows) */
	wordEnd: boolean
}

/** Measures per line when reading along */
export const LINE_MEASURES = 4

/**
 * The syllable of a note for a pass: the verse of the pass, otherwise the
 * highest verse below it (a refrain printed once), otherwise the first.
 *
 * @param lyrics - syllables of the note
 * @param pass - 1 for the first pass of a measure, 2 for its repeat …
 */
export function syllableFor(lyrics: MeiSyllable[], pass: number): MeiSyllable | null {
	if (lyrics.length === 0) {
		return null
	}
	const exact = lyrics.find((syllable) => syllable.verse === pass)
	if (exact) {
		return exact
	}
	const below = lyrics.filter((syllable) => syllable.verse < pass).sort((a, b) => b.verse - a.verse)[0]
	return below ?? [...lyrics].sort((a, b) => a.verse - b.verse)[0]
}

/**
 * The syllables of a voice in playing order. A voice without lyrics of its
 * own takes those of its staff: a choir score often prints the text once
 * for the two voices of a staff.
 *
 * @param model - the score
 * @param timeline - playback events
 * @param voice - the voice to read along with
 */
export function lyricSyllables(model: Pick<ScoreModel, 'notesByMeasure'>, timeline: Timeline, voice: Voice): LyricSyllable[] {
	const notes = new Map<string, MeiNote>()
	for (const note of model.notesByMeasure.flat()) {
		if (note.lyrics) {
			notes.set(note.id, note)
		}
	}
	const own = new Set(voice.members.map((member) => memberKey(member.staff, member.layer)))
	const staves = new Set(voice.members.map((member) => member.staff))
	const hasOwn = timeline.events.some((event) => own.has(event.voice) && notes.has(event.id))
	const belongs = (key: string) => hasOwn ? own.has(key) : staves.has(Number(key.split('.')[0]))

	// How often each measure was played before: the pass of a repeat
	const passes = new Map<number, number>()
	const passOfSlot = timeline.measures.map((slot) => {
		const pass = (passes.get(slot.index) ?? 0) + 1
		passes.set(slot.index, pass)
		return pass
	})

	const syllables: LyricSyllable[] = []
	let lastStart = -Infinity
	for (const event of timeline.events) {
		const note = notes.get(event.id)
		if (!note?.lyrics || !event.sound || !belongs(event.voice)) {
			continue
		}
		// two voices or a chord at the same time: one syllable
		if (Math.abs(event.start - lastStart) < 1e-6) {
			continue
		}
		const slot = Math.max(0, slotAt(timeline.measures, event.start))
		const syllable = syllableFor(note.lyrics, passOfSlot[slot] ?? 1)
		if (!syllable) {
			continue
		}
		lastStart = event.start
		syllables.push({ text: syllable.text, start: event.start, slot, wordEnd: syllable.wordpos === '' || syllable.wordpos === 's' || syllable.wordpos === 't' })
	}
	return syllables
}

/**
 * The syllables in lines of LINE_MEASURES measures of the playback.
 *
 * @param syllables - in playing order
 */
export function lyricLines(syllables: LyricSyllable[]): LyricSyllable[][] {
	const lines: LyricSyllable[][] = []
	let key = -1
	for (const syllable of syllables) {
		const line = Math.floor(syllable.slot / LINE_MEASURES)
		if (line !== key) {
			lines.push([])
			key = line
		}
		lines[lines.length - 1].push(syllable)
	}
	return lines
}

/**
 * Index of the syllable sung at a time: the last one that has started; -1
 * before the first.
 *
 * @param syllables - in playing order
 * @param time - seconds of the timeline
 */
export function currentSyllable(syllables: LyricSyllable[], time: number): number {
	let found = -1
	for (let i = 0; i < syllables.length && syllables[i].start <= time + 1e-6; i++) {
		found = i
	}
	return found
}

/** Steps of the learning mode: 0 all letters … 3 only the first letter of each word */
export const MASK_STEPS = 3

const LETTER = /\p{L}/u

/**
 * A syllable with letters hidden for learning the lyrics: the first letter
 * of a word always stays; of the others, one in three is hidden at step 1,
 * two in three at step 2, all at step 3. The same letters stay hidden while
 * the step stays, so the text can be learned.
 *
 * @param text - the syllable
 * @param step - 0 … MASK_STEPS
 * @param wordStart - whether the syllable starts a word
 */
export function maskSyllable(text: string, step: number, wordStart: boolean): string {
	if (step <= 0) {
		return text
	}
	let letter = 0
	return [...text].map((char) => {
		if (!LETTER.test(char)) {
			return char
		}
		const index = letter++
		if (wordStart && index === 0) {
			return char
		}
		const position = wordStart ? index : index + 1
		return step >= MASK_STEPS || position % 3 < step ? '_' : char
	}).join('')
}
