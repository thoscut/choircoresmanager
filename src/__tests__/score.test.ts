/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MeasureMarks } from '../score/musicxml.ts'

import { describe, expect, it } from 'vitest'
import { notePitch } from '../score/mei.ts'
import { loadScore, playingOrder, timelineFor } from '../score/model.ts'
import { readScoreText } from '../score/mxl.ts'
import { parseEndingNumbers, parseMusicXml } from '../score/musicxml.ts'
import { unfoldRepeats } from '../score/playOrder.ts'
import { duplicateNumbers, hasPickup, orderInRange, resolveRange } from '../score/range.ts'
import { buildTimeline } from '../score/timeline.ts'
import { createToolkit } from '../score/toolkit.ts'
import { buildVoices, splitPartName } from '../score/voices.ts'
import { parseXml, textOf } from '../score/xml.ts'

// ---- helpers ----------------------------------------------------------------------

/**
 * Structure marks of a measure.
 *
 * @param number - printed number
 * @param marks - marks that differ from "nothing"
 */
function m(number: string, marks: Partial<MeasureMarks> = {}): MeasureMarks {
	return {
		number,
		implicit: false,
		repeatStart: false,
		repeatEnd: false,
		repeatTimes: null,
		endingStart: null,
		endingStop: false,
		segno: null,
		coda: null,
		toCoda: null,
		daCapo: false,
		dalSegno: null,
		fine: false,
		...marks,
	}
}

/**
 * Playing order as printed numbers.
 *
 * @param measures - structure marks
 * @param repeats - with repeats
 */
function order(measures: MeasureMarks[], repeats = true): string {
	return unfoldRepeats(measures, repeats).map((index) => measures[index].number).join(' ')
}

/**
 * Measures with numbers from..to (no marks).
 *
 * @param from - first number
 * @param to - last number
 */
function plain(from: number, to: number): MeasureMarks[] {
	return Array.from({ length: to - from + 1 }, (_, i) => m(String(from + i)))
}

// ---- xml --------------------------------------------------------------------------

describe('parseXml', () => {
	it('reads elements, attributes, entities and CDATA', () => {
		const root = parseXml('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><a x="1 &amp; 2"><!-- c --><b>T&#228;nzer &lt;</b><c/><![CDATA[<raw>]]></a>')
		expect(root.name).toBe('a')
		expect(root.attrs.x).toBe('1 & 2')
		expect(textOf(root)).toBe('Tänzer <<raw>')
	})
})

// ---- voices -----------------------------------------------------------------------

describe('splitPartName', () => {
	it.each([
		['Tenor I/II', ['Tenor I', 'Tenor II']],
		['Tenor I. II.', ['Tenor I', 'Tenor II']],
		['Tenor I.II.', ['Tenor I', 'Tenor II']],
		['Bass 1./2.', ['Bass 1', 'Bass 2']],
		['Tenor 1/2', ['Tenor 1', 'Tenor 2']],
		['TI/TII', ['Tenor I', 'Tenor II']],
		['BI/BII', ['Bass I', 'Bass II']],
		['Sopran/Alt', ['Sopran', 'Alt']],
		['Tenor II', ['Tenor II']],
		['I. Gefangener', ['I. Gefangener']],
		['Alt oder Bariton Solo', ['Alt oder Bariton Solo']],
		['', []],
	])('%s', (name, expected) => {
		expect(splitPartName(name)).toEqual(expected)
	})
})

describe('buildVoices', () => {
	it('names the voices of "Tenor I/II" and merges a voice with its own staff', () => {
		const counts = new Map([['1.1', 10], ['1.2', 9], ['2.1', 4], ['3.1', 12]])
		const voices = buildVoices([
			{ name: 'Tenor I. II.', staves: [1] },
			{ name: 'Tenor II', staves: [2] },
			{ name: 'Bass', staves: [3] },
		], counts)
		expect(voices.map((voice) => [voice.name, voice.key, voice.notes])).toEqual([
			['Tenor I', '1.1', 10],
			['Tenor II', '1.2+2.1', 13],
			['Bass', '3.1', 12],
		])
	})

	it('numbers voices of a part without names', () => {
		const voices = buildVoices([{ name: 'Tenor', staves: [1] }], new Map([['1.1', 1], ['1.2', 1]]))
		expect(voices.map((voice) => [voice.name, voice.number])).toEqual([[null, 1], [null, 2]])
	})
})

// ---- playing order ---------------------------------------------------------------

describe('unfoldRepeats', () => {
	it('repeats a section with a forward repeat', () => {
		const measures = plain(1, 4)
		measures[1].repeatStart = true
		measures[2].repeatEnd = true
		expect(order(measures)).toBe('1 2 3 2 3 4')
	})

	it('repeats from the beginning without a forward repeat', () => {
		const measures = plain(1, 3)
		measures[1].repeatEnd = true
		expect(order(measures)).toBe('1 2 1 2 3')
	})

	it('goes back to the measure after the previous end repeat', () => {
		const measures = plain(1, 5)
		measures[1].repeatEnd = true
		measures[3].repeatEnd = true
		expect(order(measures)).toBe('1 2 1 2 3 4 3 4 5')
	})

	it('plays first and second ending', () => {
		const measures = [m('1', { repeatStart: true }), m('2'), m('3', { endingStart: [1], endingStop: true, repeatEnd: true }), m('4', { endingStart: [2], endingStop: true }), m('5')]
		expect(order(measures)).toBe('1 2 3 1 2 4 5')
		expect(order(measures, false)).toBe('1 2 4 5')
	})

	it('repeats a section with only a first ending (7-6, measures 26–34)', () => {
		const measures = [m('26', { repeatStart: true }), m('32'), m('33', { endingStart: [1], endingStop: true, repeatEnd: true }), m('34')]
		expect(order(measures)).toBe('26 32 33 26 32 34')
		expect(order(measures, false)).toBe('26 32 34')
	})

	it('honours times="3" with an ending for passes 1 and 2 (3-35)', () => {
		const measures = [m('47'), m('X1', { implicit: true, repeatStart: true }), m('89'), m('90', { endingStart: [1, 2] }), m('91', { endingStop: true, repeatEnd: true, repeatTimes: 3 })]
		expect(order(measures)).toBe('47 X1 89 90 91 X1 89 90 91 X1 89')
	})

	it('plays D.C. al Fine without repeats and with the last ending (1-10)', () => {
		const measures = [
			m('0', { implicit: true }), m('8', { repeatEnd: true }),
			m('X1', { implicit: true, repeatStart: true }), m('15'),
			m('16', { endingStart: [1], endingStop: true, repeatEnd: true }),
			m('17', { endingStart: [2], endingStop: true, fine: true }),
			m('75', { daCapo: true }),
		]
		expect(order(measures)).toBe('0 8 0 8 X1 15 16 X1 15 17 75 0 8 X1 15 17')
	})

	it('jumps D.S. al Coda', () => {
		const measures = [m('1'), m('2', { segno: 'S' }), m('3', { toCoda: 'C' }), m('4', { dalSegno: 'S' }), m('5', { coda: 'C' }), m('6')]
		expect(order(measures)).toBe('1 2 3 4 2 3 5 6')
	})
})

describe('parseEndingNumbers', () => {
	it('reads lists and ranges', () => {
		expect(parseEndingNumbers('1')).toEqual([1])
		expect(parseEndingNumbers('1, 2')).toEqual([1, 2])
		expect(parseEndingNumbers('1.2.')).toEqual([1, 2])
		expect(parseEndingNumbers('1-3')).toEqual([1, 2, 3])
		expect(parseEndingNumbers('')).toEqual([])
	})
})

// ---- measure ranges ---------------------------------------------------------------

describe('resolveRange', () => {
	const measures = ['0', '1', '2', '3', '4', 'X1', '5', '6'].map((number, index) => ({ index, id: `m${index}`, number, implicit: number === '0' || number === 'X1' }))

	it('resolves printed numbers and includes split measures in between', () => {
		const range = resolveRange(measures, '3', '6')
		expect('error' in range ? range : measures.slice(range.start, range.end + 1).map((measure) => measure.number)).toEqual(['3', '4', 'X1', '5', '6'])
	})

	it('accepts the pickup as measure 0 and leading zeros', () => {
		expect(resolveRange(measures, '0', '02')).toEqual({ start: 0, end: 2 })
		expect(hasPickup(measures)).toBe(true)
	})

	it('reports unknown and reversed numbers', () => {
		expect(resolveRange(measures, '9', '10')).toEqual({ error: 'from' })
		expect(resolveRange(measures, '1', '99')).toEqual({ error: 'to' })
		expect(resolveRange(measures, '5', '2')).toEqual({ error: 'order' })
	})

	it('limits a playing order to a range', () => {
		expect(orderInRange([0, 1, 2, 1, 2, 3], { start: 1, end: 2 }, true)).toEqual([1, 2, 1, 2])
		expect(orderInRange([0, 1, 2, 1, 2, 3], { start: 1, end: 2 }, false)).toEqual([1, 2])
	})

	it('finds duplicate printed numbers', () => {
		expect(duplicateNumbers([...measures, { index: 8, id: 'm8', number: '5', implicit: false }])).toEqual(['5'])
	})
})

// ---- timeline -----------------------------------------------------------------------

describe('buildTimeline', () => {
	it('lays out measures in playing order and merges tied notes', () => {
		const timing = {
			measures: [{ start: 0, end: 1000 }, { start: 1000, end: 2000 }],
			notes: new Map([['a', { on: 0, off: 1000 }], ['b', { on: 1000, off: 1500 }], ['c', { on: 1500, off: 2000 }]]),
			tempo: 120,
		}
		const notesByMeasure = [
			[{ id: 'a', measure: 0, staff: 1, layer: 1, pitch: 60, grace: false }],
			[{ id: 'b', measure: 1, staff: 1, layer: 1, pitch: 60, grace: false }, { id: 'c', measure: 1, staff: 1, layer: 2, pitch: 64, grace: false }],
		]
		const timeline = buildTimeline([0, 1, 0], timing, notesByMeasure, [{ start: 'a', end: 'b' }])
		expect(timeline.duration).toBe(3)
		expect(timeline.measures.map((slot) => [slot.index, slot.start])).toEqual([[0, 0], [1, 1], [0, 2]])
		const a = timeline.events.filter((event) => event.id === 'a')
		expect(a.map((event) => [event.start, event.soundEnd])).toEqual([[0, 1.5], [2, 3]])
		expect(timeline.events.find((event) => event.id === 'b')?.sound).toBe(false)
		expect(timeline.events.find((event) => event.id === 'c')?.voice).toBe('1.2')
	})
})

// ---- MEI pitches -----------------------------------------------------------------------

describe('notePitch', () => {
	it('uses gestural accidentals and transposition', () => {
		expect(notePitch(parseXml('<note pname="c" oct="4"/>'))).toBe(60)
		expect(notePitch(parseXml('<note pname="b" oct="3" accid.ges="f"/>'))).toBe(58)
		expect(notePitch(parseXml('<note pname="f" oct="4"><accid accid="s"/></note>'))).toBe(66)
		expect(notePitch(parseXml('<note pname="a" oct="4"/>'), -12)).toBe(57)
		expect(notePitch(parseXml('<rest/>'))).toBeNull()
	})
})

// ---- files and Verovio ---------------------------------------------------------------

const MUSICXML = `<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0">
  <part-list>
    <score-part id="T"><part-name>Tenor I/II</part-name></score-part>
  </part-list>
  <part id="T">
    <measure number="0" implicit="yes">
      <attributes><divisions>1</divisions><key><fifths>-1</fifths><mode>major</mode></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>
      <direction><direction-type><metronome print-object="no"><beat-unit>quarter</beat-unit><per-minute>120</per-minute></metronome></direction-type><sound tempo="120"/></direction>
      <note><pitch><step>C</step><octave>5</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type></note>
      <backup><duration>1</duration></backup>
      <note><pitch><step>A</step><octave>4</octave></pitch><duration>1</duration><voice>2</voice><type>quarter</type></note>
    </measure>
    <measure number="1">
      <barline location="left"><repeat direction="forward"/></barline>
      <note><pitch><step>B</step><alter>-1</alter><octave>4</octave></pitch><duration>2</duration><voice>1</voice><type>half</type></note>
      <backup><duration>2</duration></backup>
      <note><pitch><step>G</step><octave>4</octave></pitch><duration>2</duration><voice>2</voice><type>half</type></note>
    </measure>
    <measure number="2">
      <note><pitch><step>A</step><octave>4</octave></pitch><duration>2</duration><voice>1</voice><type>half</type></note>
      <backup><duration>2</duration></backup>
      <note><pitch><step>F</step><octave>4</octave></pitch><duration>2</duration><voice>2</voice><type>half</type></note>
      <barline location="right"><repeat direction="backward"/></barline>
    </measure>
  </part>
</score-partwise>`

/**
 * A ZIP archive with stored (uncompressed) entries, as .mxl files may be.
 *
 * @param files - name => text
 */
function storedZip(files: Record<string, string>): Uint8Array {
	const encoder = new TextEncoder()
	const parts: number[] = []
	const central: number[] = []
	const u16 = (value: number) => [value & 0xff, (value >> 8) & 0xff]
	const u32 = (value: number) => [value & 0xff, (value >> 8) & 0xff, (value >> 16) & 0xff, (value >>> 24) & 0xff]
	let count = 0
	for (const [name, text] of Object.entries(files)) {
		const nameBytes = [...encoder.encode(name)]
		const data = [...encoder.encode(text)]
		const offset = parts.length
		parts.push(...u32(0x04034b50), ...u16(20), ...u16(0), ...u16(0), ...u16(0), ...u16(0), ...u32(0), ...u32(data.length), ...u32(data.length), ...u16(nameBytes.length), ...u16(0), ...nameBytes, ...data)
		central.push(...u32(0x02014b50), ...u16(20), ...u16(20), ...u16(0), ...u16(0), ...u16(0), ...u16(0), ...u32(0), ...u32(data.length), ...u32(data.length), ...u16(nameBytes.length), ...u16(0), ...u16(0), ...u16(0), ...u16(0), ...u32(0), ...u32(offset), ...nameBytes)
		count++
	}
	const centralOffset = parts.length
	const end = [...u32(0x06054b50), ...u16(0), ...u16(0), ...u16(count), ...u16(count), ...u32(central.length), ...u32(centralOffset), ...u16(0)]
	return new Uint8Array([...parts, ...central, ...end])
}

describe('readScoreText', () => {
	it('reads plain MusicXML', async () => {
		expect(await readScoreText(new TextEncoder().encode(MUSICXML))).toBe(MUSICXML)
	})

	it('reads compressed MusicXML via META-INF/container.xml', async () => {
		const zip = storedZip({
			'META-INF/container.xml': '<container><rootfiles><rootfile full-path="score/piece.musicxml" media-type="application/vnd.recordare.musicxml+xml"/></rootfiles></container>',
			'score/piece.musicxml': MUSICXML,
		})
		expect(await readScoreText(zip)).toBe(MUSICXML)
	})
})

describe('parseMusicXml', () => {
	it('reads parts, numbers, repeats, tempo and signatures', () => {
		const structure = parseMusicXml(MUSICXML)
		expect(structure.parts).toEqual([{ id: 'T', name: 'Tenor I/II', staves: 1 }])
		expect(structure.measures.map((measure) => [measure.number, measure.implicit, measure.repeatStart, measure.repeatEnd]))
			.toEqual([['0', true, false, false], ['1', false, true, false], ['2', false, false, true]])
		expect(structure.tempo).toEqual({ beatUnit: 'quarter', dots: 0, perMinute: 120, printed: false })
		expect(structure.signatures).toEqual({ fifths: -1, mode: 'major', time: '2/4' })
	})
})

describe('loadScore (Verovio)', () => {
	it('analyses a small score like the score view does', async () => {
		const toolkit = await createToolkit()
		try {
			const model = loadScore(toolkit, MUSICXML)
			expect(model.measures.map((measure) => measure.number)).toEqual(['0', '1', '2'])
			expect(model.pickup).toBe(true)
			expect(model.voices.map((voice) => voice.name)).toEqual(['Tenor I', 'Tenor II'])
			expect(model.fullOrder).toEqual([0, 1, 2, 1, 2])
			const pitches = model.notesByMeasure[1].map((note) => note.pitch).sort()
			expect(pitches).toEqual([67, 70])
			const timeline = timelineFor(model, playingOrder(model, null, true))
			expect(timeline.events).toHaveLength(10)
			expect(timeline.duration).toBeCloseTo(4.5, 1)
		} finally {
			toolkit.destroy()
		}
	}, 30000)
})
