/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { CompareWords } from '../score/compare.ts'
import type { ChangeWords, VersionLineWords } from '../states.ts'

import { describe, expect, it } from 'vitest'
import { textReference } from '../references.ts'
import { compareScores, CompareFormatError } from '../score/compare.ts'
import { practiceMidi } from '../score/practice.ts'
import { encodeWav, infoChunk, wavBytes } from '../score/wav.ts'
import { changeText, memberNote, pitchName, sha256Hex, versionLine } from '../states.ts'

const WORDS: CompareWords = {
	pitch: (step, alter, octave) => pitchName(step, alter, octave),
	rest: 'rest',
	noteValue: (type, dots) => (dots > 0 ? `dotted ${type}` : type),
	voiceOf: (part, number) => `${part}, voice ${number}`,
	part: (number) => `Part ${number}`,
}

const CHANGE_WORDS: ChangeWords = {
	measure: (measure) => `m. ${measure}`,
	verse: (verse) => `verse ${verse}`,
	nothing: '–',
	syllable: (before, after) => `syllable "${before}" → "${after}"`,
	noteValue: 'note value',
	accidental: 'accidental',
	measureAdded: 'measure added',
	measureRemoved: 'measure removed',
	partAdded: (name) => `part added: ${name}`,
	partRemoved: (name) => `part removed: ${name}`,
}

const LINE_WORDS: VersionLineWords = {
	revision: (number) => `Revision ${number}`,
	validSince: (date) => `valid since ${date}`,
}

interface NoteSpec {
	step?: string
	alter?: number
	octave?: number
	rest?: boolean
	type?: string
	voice?: string
	lyric?: string
}

/**
 * A note element.
 *
 * @param note - what the note is
 */
function noteXml(note: NoteSpec): string {
	const pitch = note.rest
		? '<rest/>'
		: `<pitch><step>${note.step ?? 'C'}</step>${note.alter ? `<alter>${note.alter}</alter>` : ''}<octave>${note.octave ?? 4}</octave></pitch>`
	const lyric = note.lyric !== undefined ? `<lyric number="1"><syllabic>single</syllabic><text>${note.lyric}</text></lyric>` : ''
	return `<note>${pitch}<duration>1</duration><voice>${note.voice ?? '1'}</voice><type>${note.type ?? 'quarter'}</type>${lyric}</note>`
}

/**
 * A partwise score: parts with measures of notes.
 *
 * @param parts - part name and its measures (number and notes, with <backup> between voices)
 */
function score(parts: { name: string, measures: { number: string, voices: NoteSpec[][] }[] }[]): string {
	const list = parts.map((part, index) => `<score-part id="P${index + 1}"><part-name>${part.name}</part-name></score-part>`).join('')
	const body = parts.map((part, index) => `<part id="P${index + 1}">${part.measures.map((measure) => `<measure number="${measure.number}">${
		measure.voices.map((voice) => voice.map(noteXml).join('')).join('<backup><duration>4</duration></backup>')
	}</measure>`).join('')}</part>`).join('')
	return `<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0"><part-list>${list}</part-list>${body}</score-partwise>`
}

const TENORS = 'Tenor I/II'

/** Two measures of "Tenor I/II" (two voices on one staff) and "Bass". */
function base(): { name: string, measures: { number: string, voices: NoteSpec[][] }[] }[] {
	return [
		{
			name: TENORS,
			measures: [
				{ number: '1', voices: [[{ step: 'E', lyric: 'Lie' }, { step: 'F' }], [{ step: 'C' }, { step: 'D', voice: '2' }].map((n) => ({ ...n, voice: '2' }))] },
				{ number: '2', voices: [[{ step: 'G' }, { rest: true }], [{ step: 'E', voice: '2' }, { rest: true, voice: '2' }]] },
			],
		},
		{
			name: 'Bass',
			measures: [
				{ number: '1', voices: [[{ step: 'C', octave: 3 }, { step: 'G', octave: 2 }]] },
				{ number: '2', voices: [[{ step: 'C', octave: 3 }, { rest: true }]] },
			],
		},
	]
}

describe('compareScores', () => {
	it('finds nothing in the same score', () => {
		const text = score(base())
		expect(compareScores(text, text, WORDS)).toEqual({ changes: [], truncated: false })
	})

	it('names a changed pitch with measure and the voice the part name announces', () => {
		const after = base()
		after[0].measures[0].voices[1][1] = { step: 'E', voice: '2' }
		const { changes } = compareScores(score(base()), score(after), WORDS)
		expect(changes).toEqual([{ measure: '1', voice: 'Tenor II', kind: 'pitch', verse: null, old: 'D4', new: 'E4' }])
		expect(changeText(changes[0], CHANGE_WORDS)).toBe('m. 1, Tenor II: D4 → E4')
	})

	it('finds a changed syllable, note value and alteration', () => {
		const after = base()
		after[0].measures[0].voices[0][0] = { step: 'E', lyric: 'Lieb' }
		after[1].measures[0].voices[0][1] = { step: 'G', octave: 2, type: 'half' }
		after[1].measures[1].voices[0][0] = { step: 'C', alter: 1, octave: 3 }
		const { changes } = compareScores(score(base()), score(after), WORDS)
		expect(changes.map((change) => changeText(change, CHANGE_WORDS))).toEqual([
			'm. 1, Tenor I, verse 1: syllable "Lie" → "Lieb"',
			'm. 1, Bass: note value quarter → half',
			'm. 2, Bass: C3 → C♯3',
		])
	})

	it('reports measures added and removed once, not per part', () => {
		const after = base().map((part) => ({ ...part, measures: [...part.measures, { number: '3', voices: [[{ step: 'C' }]] }] }))
		const { changes } = compareScores(score(base()), score(after), WORDS)
		expect(changes).toEqual([{ measure: '3', voice: null, kind: 'measure_added', verse: null, old: '', new: '' }])
		const back = compareScores(score(after), score(base()), WORDS).changes
		expect(back.map((change) => changeText(change, CHANGE_WORDS))).toEqual(['m. 3: measure removed'])
	})

	it('shows the notes before and after when a voice has another number of notes', () => {
		const after = base()
		after[1].measures[1].voices[0] = [{ step: 'C', octave: 3 }, { step: 'E', octave: 3 }, { step: 'G', octave: 3 }]
		const { changes } = compareScores(score(base()), score(after), WORDS)
		expect(changes.map((change) => changeText(change, CHANGE_WORDS))).toEqual(['m. 2, Bass: C3 rest → C3 E3 G3'])
	})

	it('names parts added and removed', () => {
		const after = [...base(), { name: 'Bariton', measures: [{ number: '1', voices: [[{ step: 'A', octave: 3 }]] }] }]
		const { changes } = compareScores(score(base()), score(after), WORDS)
		expect(changes.map((change) => changeText(change, CHANGE_WORDS))).toEqual(['part added: Bariton'])
	})

	it('stops at the most changes asked for', () => {
		const after = base()
		after[1].measures[0].voices[0] = [{ step: 'D', octave: 3 }, { step: 'A', octave: 2 }]
		const result = compareScores(score(base()), score(after), WORDS, 1)
		expect(result.changes).toHaveLength(1)
		expect(result.truncated).toBe(true)
	})

	it('refuses what is not MusicXML', () => {
		expect(() => compareScores('<html></html>', score(base()), WORDS)).toThrow(CompareFormatError)
	})
})

describe('the note for choir members', () => {
	it('has a heading, one line per difference and the last line when cut', () => {
		const changes = [{ measure: '12', voice: 'Tenor II', kind: 'pitch' as const, verse: null, old: 'D4', new: 'E4' }]
		expect(memberNote('Title (2-35): changes from revision 2 to revision 3', changes, CHANGE_WORDS, '… and more'))
			.toBe('Title (2-35): changes from revision 2 to revision 3\n– m. 12, Tenor II: D4 → E4\n… and more')
	})
})

describe('pitchName', () => {
	it('uses signs in English and German note names in German', () => {
		expect(pitchName('F', 1, 4)).toBe('F♯4')
		expect(pitchName('B', -1, 3)).toBe('B♭3')
		expect(pitchName('B', 0, 3, true)).toBe('H3')
		expect(pitchName('B', -1, 3, true)).toBe('B3')
		expect(pitchName('E', -1, 4, true)).toBe('Es4')
		expect(pitchName('F', 1, 4, true)).toBe('Fis4')
		expect(pitchName('C', 0.5, 4)).toBe('C(+0.5)4')
	})
})

describe('versionLine', () => {
	it('names shelf mark, version, revision and the day since when it is valid', () => {
		expect(versionLine({ shelfmark: '2-35', version: 'Arrangement', revision: 3, validSince: '1 Oct 2026' }, LINE_WORDS))
			.toBe('2-35 · Arrangement · Revision 3 · valid since 1 Oct 2026')
		expect(versionLine({ shelfmark: '2-35', version: '', revision: null }, LINE_WORDS)).toBe('2-35')
	})

	it('goes into the text reference', () => {
		expect(textReference({ shelfmark: '2-35', title: 'Title', revision: 'Revision 3', measureFrom: '12', measureTo: '12', voice: 'Tenor II' }, {
			page: (page) => `p. ${page}`, measure: (measure) => `m. ${measure}`, measures: (from, to) => `m. ${from}–${to}`, verse: (verse) => `verse ${verse}`,
		})).toBe('2-35 · Title · Revision 3 · m. 12 · Tenor II')
	})
})

describe('the version line in practice files', () => {
	it('is a comment chunk after the samples of a WAV file', () => {
		const samples = new Float32Array(10)
		const plain = encodeWav(samples, 8000, 1)
		const withComment = encodeWav(samples, 8000, 1, '2-35 - Revision 3')
		const chunk = infoChunk('2-35 - Revision 3')
		expect(chunk.length % 2).toBe(0)
		expect(withComment.length).toBe(wavBytes(10, 1) + chunk.length)
		expect(withComment.slice(0, 4)).toEqual(plain.slice(0, 4))
		const view = new DataView(withComment.buffer)
		expect(view.getUint32(4, true)).toBe(withComment.length - 8)
		const tail = new TextDecoder().decode(withComment.slice(wavBytes(10, 1)))
		expect(tail.startsWith('LIST')).toBe(true)
		expect(tail).toContain('INFOICMT')
		expect(tail).toContain('2-35 - Revision 3\0')
	})

	it('is a text event of a MIDI file', () => {
		const model = {
			measures: [], voices: [], parts: [], signatures: { fifths: null, mode: '', time: '' }, timing: { tempo: null }, quarterTempo: 120,
		} as unknown as Parameters<typeof practiceMidi>[0]
		const timeline = { measures: [], events: [], duration: 0 } as unknown as Parameters<typeof practiceMidi>[1]
		const midi = practiceMidi(model, timeline, { voice: null, mode: 'all', label: 'Tutti' }, {
			rate: 1, title: '2-35 Title', labelOf: () => '', text: '2-35 - Revision 3',
		})
		const text = Array.from(midi, (byte) => String.fromCharCode(byte)).join('')
		expect(text).toContain('\x01\x112-35 - Revision 3')
	})
})

describe('sha256Hex', () => {
	it('gives the checksum the server stores', async () => {
		const data = new TextEncoder().encode('abc')
		expect(await sha256Hex(data.buffer as ArrayBuffer)).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
	})
})
