/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import type { NoteValues } from '../score/correction.ts'

import { describe, expect, it } from 'vitest'
import {
	correctedScore,
	CorrectionFormatError,
	listNotes,
	measureLengths,
	parseScoreDocument,
	pendingChanges,
	pitchText,
	previewScore,
	rowValues,
	sameMeasureLengths,
	serializeScore,
	voiceTargets,
} from '../score/correction.ts'
import { loadScore } from '../score/model.ts'
import { createToolkit } from '../score/toolkit.ts'

/**
 * Tenor I and II on one staff (lyrics with Tenor I, an F sharp with its
 * accidental in measure 2), Bass on a second part.
 */
const SCORE = `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">
<score-partwise version="4.0">
  <part-list>
    <score-part id="P1"><part-name>Tenor I/II</part-name></score-part>
    <score-part id="P2"><part-name>Bass</part-name></score-part>
  </part-list>
  <part id="P1">
    <measure number="1">
      <attributes><divisions>2</divisions><key><fifths>0</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line><clef-octave-change>-1</clef-octave-change></clef></attributes>
      <note>
        <pitch><step>E</step><octave>4</octave></pitch>
        <duration>2</duration>
        <voice>1</voice>
        <type>quarter</type>
        <stem>up</stem>
        <lyric number="1"><syllabic>begin</syllabic><text>Lie</text></lyric>
        <lyric number="2"><text>Wald</text></lyric>
      </note>
      <note>
        <pitch><step>F</step><octave>4</octave></pitch>
        <duration>2</duration>
        <voice>1</voice>
        <type>quarter</type>
        <stem>up</stem>
        <lyric number="1"><syllabic>end</syllabic><text>be</text></lyric>
      </note>
      <backup><duration>4</duration></backup>
      <note>
        <pitch><step>C</step><octave>4</octave></pitch>
        <duration>3</duration>
        <voice>2</voice>
        <type>quarter</type>
        <dot/>
        <stem>down</stem>
      </note>
      <note>
        <pitch><step>D</step><octave>4</octave></pitch>
        <duration>1</duration>
        <voice>2</voice>
        <type>eighth</type>
        <stem>down</stem>
      </note>
    </measure>
    <measure number="2">
      <note>
        <pitch><step>F</step><alter>1</alter><octave>4</octave></pitch>
        <duration>4</duration>
        <voice>1</voice>
        <type>half</type>
        <accidental>sharp</accidental>
        <stem>up</stem>
      </note>
      <backup><duration>4</duration></backup>
      <note>
        <rest/>
        <duration>4</duration>
        <voice>2</voice>
        <type>half</type>
      </note>
    </measure>
  </part>
  <part id="P2">
    <measure number="1">
      <attributes><divisions>2</divisions><key><fifths>0</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>F</sign><line>4</line></clef></attributes>
      <note><pitch><step>C</step><octave>3</octave></pitch><duration>2</duration><voice>1</voice><type>quarter</type></note>
      <note><pitch><step>G</step><octave>2</octave></pitch><duration>2</duration><voice>1</voice><type>quarter</type></note>
    </measure>
    <measure number="2">
      <note><pitch><step>D</step><octave>3</octave></pitch><duration>4</duration><voice>1</voice><type>half</type></note>
    </measure>
  </part>
</score-partwise>
`

const PARTS = [{ name: 'Tenor I/II', staves: [1] }, { name: 'Bass', staves: [2] }]

/**
 * The <note> elements of a document, serialized, for comparing.
 *
 * @param xml - MusicXML text
 */
function notes(xml: string): string[] {
	return Array.from(parseScoreDocument(xml).getElementsByTagName('note')).map((note) => new XMLSerializer().serializeToString(note))
}

describe('notes of a voice', () => {
	it('maps the voices of the score view to parts, staves and voices', () => {
		expect(voiceTargets(PARTS, [{ staff: 1, layer: 2 }])).toEqual([{ part: 0, staff: 1, voice: '2' }])
		expect(voiceTargets(PARTS, [{ staff: 2, layer: 1 }])).toEqual([{ part: 1, staff: 1, voice: '1' }])
		expect(voiceTargets([{ name: 'Piano', staves: [1, 2] }], [{ staff: 2, layer: 5 }])).toEqual([{ part: 0, staff: 2, voice: '5' }])
	})

	it('lists the notes of one voice in a range, with values and syllables', () => {
		const document = parseScoreDocument(SCORE)
		const tenor1 = listNotes(document, { start: 0, end: 1 }, voiceTargets(PARTS, [{ staff: 1, layer: 1 }]))
		expect(tenor1.map((row) => [row.measure, pitchText(row.step, row.alter, row.octave), row.accidental])).toEqual([
			['1', 'E4', null], ['1', 'F4', null], ['2', 'F#4', 'sharp'],
		])
		expect(tenor1[0].lyrics).toEqual([{ number: '1', texts: ['Lie'] }, { number: '2', texts: ['Wald'] }])
		const tenor2 = listNotes(document, { start: 0, end: 1 }, voiceTargets(PARTS, [{ staff: 1, layer: 2 }]))
		expect(tenor2.map((row) => [row.type, row.dots, row.rest, row.pitched])).toEqual([
			['quarter', 1, false, true], ['eighth', 0, false, true], ['half', 0, true, false],
		])
		const bass = listNotes(document, { start: 1, end: 1 }, voiceTargets(PARTS, [{ staff: 2, layer: 1 }]))
		expect(bass.map((row) => row.key)).toEqual(['1.1.0'])
	})

	it('agrees with Verovio: a layer is the MusicXML voice', async () => {
		const toolkit = await createToolkit()
		try {
			const model = loadScore(toolkit, SCORE)
			const tenor2 = model.voices.find((voice) => voice.name === 'Tenor II')
			expect(tenor2).toBeDefined()
			const rows = listNotes(parseScoreDocument(SCORE), { start: 0, end: 0 }, voiceTargets(model.parts, tenor2?.members ?? []))
			expect(rows.map((row) => pitchText(row.step, row.alter, row.octave))).toEqual(['C4', 'D4'])
		} finally {
			toolkit.destroy()
		}
	})
})

describe('changing a voice', () => {
	const document = parseScoreDocument(SCORE)
	const rows = listNotes(document, { start: 0, end: 1 }, voiceTargets(PARTS, [{ staff: 1, layer: 1 }]))

	/**
	 * Values of all rows with changes for some.
	 *
	 * @param changes - row index => changed values
	 */
	function valuesWith(changes: Record<number, Partial<NoteValues>>): Map<string, NoteValues> {
		return new Map(rows.map((row, index) => [row.key, { ...rowValues(row), ...changes[index] }]))
	}

	it('changes nothing without changes', () => {
		const result = correctedScore(SCORE, rows, valuesWith({}))
		expect(result.changes).toEqual([])
		expect(result.xml).toBe(serializeScore(parseScoreDocument(SCORE)))
		expect(pendingChanges(rows, valuesWith({}))).toEqual([])
	})

	it('changes only the step, the alteration, the accidental and the syllable of the note', () => {
		const values = valuesWith({ 0: { step: 'D', alter: 1, accidental: 'sharp', lyrics: [['Lieb'], ['Wald']] } })
		const result = correctedScore(SCORE, rows, values)
		expect(result.changes.map((change) => [change.kind, change.measure, change.verse, change.old, change.new])).toEqual([
			['pitch', '1', null, 'E4', 'D#4'],
			['accidental', '1', null, '', 'sharp'],
			['lyric', '1', '1', 'Lie', 'Lieb'],
		])
		expect(pendingChanges(rows, values)).toEqual(result.changes)

		const before = notes(SCORE)
		const after = notes(result.xml)
		expect(after).toHaveLength(before.length)
		// every other note is exactly as before
		expect(after.slice(1)).toEqual(before.slice(1))
		const changed = parseScoreDocument(result.xml).getElementsByTagName('note')[0]
		const pitch = changed.getElementsByTagName('pitch')[0]
		expect(Array.from(pitch.children).map((element) => `${element.localName}=${element.textContent}`)).toEqual(['step=D', 'alter=1', 'octave=4'])
		// the accidental comes after the note type and before the stem (MusicXML order)
		expect(Array.from(changed.children).map((element) => element.localName)).toEqual(['pitch', 'duration', 'voice', 'type', 'accidental', 'stem', 'lyric', 'lyric'])
		expect(changed.getElementsByTagName('syllabic')[0].textContent).toBe('begin')
		expect(sameMeasureLengths(SCORE, result.xml)).toBe(true)
	})

	it('removes an alteration and its accidental', () => {
		const result = correctedScore(SCORE, rows, valuesWith({ 2: { alter: 0, accidental: null } }))
		expect(result.changes.map((change) => [change.kind, change.old, change.new])).toEqual([['pitch', 'F#4', 'F4'], ['accidental', 'sharp', '']])
		const changed = parseScoreDocument(result.xml).getElementsByTagName('note')[4]
		expect(changed.getElementsByTagName('alter')).toHaveLength(0)
		expect(changed.getElementsByTagName('accidental')).toHaveLength(0)
		expect(changed.getElementsByTagName('duration')[0].textContent).toBe('4')
	})

	it('changes an octave and keeps the document type declaration', () => {
		const result = correctedScore(SCORE, rows, valuesWith({ 1: { octave: 3 } }))
		expect(result.changes.map((change) => [change.old, change.new])).toEqual([['F4', 'F3']])
		expect(result.xml).toContain('<!DOCTYPE score-partwise PUBLIC')
		expect(result.xml).toContain('<octave>3</octave>')
	})

	it('marks the changed notes for the preview only', () => {
		const preview = previewScore(SCORE, rows, valuesWith({ 1: { step: 'G' } }), '#123456')
		const all = Array.from(parseScoreDocument(preview.xml).getElementsByTagName('note'))
		expect(all.map((note) => note.getAttribute('color'))).toEqual([null, '#123456', null, null, null, null, null, null, null])
		const saved = correctedScore(SCORE, rows, valuesWith({ 1: { step: 'G' } }))
		expect(saved.xml).not.toContain('color=')
	})
})

describe('guards', () => {
	it('measures the length of every measure, also with backup', () => {
		expect(measureLengths(parseScoreDocument(SCORE))).toEqual(['0.0:4', '0.1:4', '1.0:4', '1.1:4'])
		const longer = SCORE.replace('<duration>1</duration>\n        <voice>2</voice>', '<duration>3</duration>\n        <voice>2</voice>')
		expect(sameMeasureLengths(SCORE, longer)).toBe(false)
	})

	it('reads only partwise MusicXML', () => {
		expect(() => parseScoreDocument('<score-timewise version="4.0"/>')).toThrow(CorrectionFormatError)
		expect(() => parseScoreDocument('<score-partwise><part>')).toThrow(CorrectionFormatError)
		expect(() => parseScoreDocument('<html/>')).toThrow(CorrectionFormatError)
	})

	it('writes pitches as text', () => {
		expect(pitchText('F', 1, 4)).toBe('F#4')
		expect(pitchText('B', -1, 3)).toBe('Bb3')
		expect(pitchText('C', 2, 5, true)).toBe('C𝄪5')
		expect(pitchText('E', -2, 4, true)).toBe('E𝄫4')
		expect(pitchText('D', 0.5, 4)).toBe('D(+0.5)4')
	})
})
