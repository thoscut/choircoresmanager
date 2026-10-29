/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Playing order from small MusicXML files: repeats, endings and jumps as
 * MuseScore 4 plays them. The cases follow the test cases of the player of
 * the parallel development (a fork of the Orchestra Scores Manager): triple
 * endings, times="3", D.C. al Fine, D.S. al Coda, jump marks in one part only.
 */
import { describe, expect, it } from 'vitest'
import { parseMusicXml } from '../score/musicxml.ts'
import { unfoldRepeats } from '../score/playOrder.ts'

// ---- a tiny MusicXML writer ---------------------------------------------------------

/**
 * One measure, written in a short notation; tokens separated by spaces:
 * - `|:` forward repeat, `:|` backward repeat, `:|3` backward repeat with times="3"
 * - `[1,2` an ending starts, `]` it stops, `]-` it is discontinued
 * - `fine`, `dc`, `ds`, `segno`, `coda`, `tocoda`: a `<sound>` playback mark
 * - `$segno`, `$coda`: a printed sign without a playback mark
 * - `"text"` words without a playback mark ("_" for a space)
 *
 * @param spec - tokens
 * @param number - measure number
 */
function measure(spec: string, number: number): string {
	const tokens = spec.split(/\s+/).filter((token) => token !== '')
	const left: string[] = []
	const right: string[] = []
	const directions: string[] = []
	for (const token of tokens) {
		if (token === '|:') {
			left.push('<repeat direction="forward"/>')
		} else if (token.startsWith(':|')) {
			const times = token.slice(2)
			right.push(`<repeat direction="backward"${times !== '' ? ` times="${times}"` : ''}/>`)
		} else if (token.startsWith('[')) {
			left.unshift(`<ending number="${token.slice(1)}" type="start"/>`)
		} else if (token === ']' || token === ']-') {
			right.unshift(`<ending number="0" type="${token === ']' ? 'stop' : 'discontinue'}"/>`)
		} else if (token.startsWith('$')) {
			directions.push(`<direction><direction-type><${token.slice(1)}/></direction-type></direction>`)
		} else if (token.startsWith('"')) {
			directions.push(`<direction><direction-type><words>${token.replaceAll('"', '').replaceAll('_', ' ')}</words></direction-type></direction>`)
		} else {
			const sound = {
				fine: 'fine="yes"',
				dc: 'dacapo="yes"',
				ds: 'dalsegno="s"',
				segno: 'segno="s"',
				coda: 'coda="c"',
				tocoda: 'tocoda="c"',
			}[token]
			if (sound === undefined) {
				throw new Error(`Unknown token ${token}`)
			}
			directions.push(`<direction><direction-type><words>${token}</words></direction-type><sound ${sound}/></direction>`)
		}
	}
	const attributes = number === 1 ? '<attributes><divisions>1</divisions><time><beats>4</beats><beat-type>4</beat-type></time></attributes>' : ''
	return `<measure number="${number}">${attributes}`
		+ (left.length > 0 ? `<barline location="left">${left.join('')}</barline>` : '')
		+ directions.join('')
		+ '<note><pitch><step>G</step><octave>4</octave></pitch><duration>4</duration><voice>1</voice><type>whole</type></note>'
		+ (right.length > 0 ? `<barline location="right">${right.join('')}</barline>` : '')
		+ '</measure>'
}

/**
 * A partwise score; each part is a list of measures in the short notation.
 *
 * @param parts - measures of every part
 */
function partwise(...parts: string[][]): string {
	const list = parts.map((_, k) => `<score-part id="P${k + 1}"><part-name>Voice ${k + 1}</part-name></score-part>`).join('')
	const body = parts.map((measures, k) => `<part id="P${k + 1}">${measures.map((spec, i) => measure(spec, i + 1)).join('')}</part>`).join('')
	return `<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0"><part-list>${list}</part-list>${body}</score-partwise>`
}

/**
 * Printed numbers of the measures in playing order.
 *
 * @param xml - MusicXML text
 * @param repeats - with repeats and jumps
 */
function played(xml: string, repeats = true): string {
	const { measures } = parseMusicXml(xml)
	return unfoldRepeats(measures, repeats).map((index) => measures[index].number).join(' ')
}

// ---- cases --------------------------------------------------------------------------

describe('playing order from MusicXML: repeats and endings', () => {
	it('repeats a section', () => {
		expect(played(partwise(['', '|:', ':|', '']))).toBe('1 2 3 2 3 4')
	})

	it('plays first and second ending', () => {
		expect(played(partwise(['|:', '', '[1 ] :|', '[2 ]', '']))).toBe('1 2 3 1 2 4 5')
	})

	it('plays three endings with two backward repeats', () => {
		expect(played(partwise(['|:', '[1,2 ]', '[1 ] :|', '[2 ] :|', '[3 ]', '']))).toBe('1 2 3 1 2 4 1 5 6')
	})

	it('plays an ending for passes 1 and 2 and a third ending', () => {
		expect(played(partwise(['|:', '', '[1,2 ] :|', '[3 ]', '']))).toBe('1 2 3 1 2 3 1 2 4 5')
	})

	it('repeats times="3" without endings', () => {
		expect(played(partwise(['|:', ':|3', '']))).toBe('1 2 1 2 1 2 3')
	})

	it('plays times="3" with an ending for passes 1 to 3 and a fourth ending', () => {
		expect(played(partwise(['|:', '[1,2,3 ] :|3', '[4 ]']))).toBe('1 2 1 2 1 2 1 3')
	})

	it('plays times="3" with an ending for passes 1 and 2 only', () => {
		expect(played(partwise(['|:', '', '[1,2 ] :|3', '']))).toBe('1 2 3 1 2 3 1 2 4')
	})

	it('plays times="1" once', () => {
		expect(played(partwise(['|:', ':|1', '']))).toBe('1 2 3')
	})

	it('accepts a range as ending number', () => {
		expect(played(partwise(['|:', '[1-2 ] :|', '[3 ]']))).toBe('1 2 1 2 1 3')
	})

	it('plays endings over several measures, closed by stop and discontinue', () => {
		expect(played(partwise(['|:', '', '[1', '] :|', '[2 ]-', '']))).toBe('1 2 3 4 1 2 5 6')
	})

	it('goes back to the last forward repeat, not to the measure after an earlier backward repeat', () => {
		expect(played(partwise(['', '|:', ':|', '', ':|', '']))).toBe('1 2 3 2 3 4 5 2 3 4 5 6')
		expect(played(partwise(['', ':|', '', ':|', '']))).toBe('1 2 1 2 3 4 1 2 3 4 5')
	})

	it('plays straight through without repeats: the last ending only', () => {
		expect(played(partwise(['|:', '[1,2 ]', '[1 ] :|', '[2 ] :|', '[3 ]', '']), false)).toBe('1 5 6')
	})
})

describe('playing order from MusicXML: jumps', () => {
	it('plays D.C. al Fine', () => {
		expect(played(partwise(['', 'fine', '', 'dc']))).toBe('1 2 3 4 1 2')
	})

	it('plays D.C. al Fine without the repeats after the jump', () => {
		expect(played(partwise(['|:', ':|', 'fine', 'dc']))).toBe('1 2 1 2 3 4 1 2 3')
	})

	it('plays D.S. al Coda', () => {
		expect(played(partwise(['', 'segno', 'tocoda', '', 'ds', 'coda', '']))).toBe('1 2 3 4 5 2 3 6 7')
	})

	it('plays D.C. al Coda', () => {
		expect(played(partwise(['', 'tocoda', '', 'dc', 'coda', '']))).toBe('1 2 3 4 1 2 5 6')
	})

	it('plays D.S. al Fine with the last ending only after the jump', () => {
		expect(played(partwise(['', 'segno |:', '', '[1 ] :|', '[2 ] fine', '', 'ds']))).toBe('1 2 3 4 2 3 5 6 7 2 3 5')
	})

	it('plays D.C. to the end without Fine', () => {
		expect(played(partwise(['', '', 'dc']))).toBe('1 2 3 1 2 3')
	})

	it('does not stop at a Fine before the jump', () => {
		expect(played(partwise(['', 'fine', '']))).toBe('1 2 3')
	})

	it('does not jump for D.S. without a segno', () => {
		expect(played(partwise(['', '', 'ds', '']))).toBe('1 2 3 4')
	})

	it('does not jump for words without a playback mark', () => {
		expect(played(partwise(['', 'fine', '', '"D.C._al_Fine"']))).toBe('1 2 3 4')
	})

	it('uses printed segno and coda signs when the playback marks are missing', () => {
		expect(played(partwise(['', '$segno', '$coda', '', 'ds', '$coda', '']))).toBe('1 2 3 4 5 2 3 6 7')
	})

	it('plays no jumps without repeats', () => {
		expect(played(partwise(['', 'segno', 'tocoda', '', 'ds', 'coda', '']), false)).toBe('1 2 3 4 5 6 7')
	})
})

describe('playing order from MusicXML: several parts', () => {
	it('takes the jump marks from another part when the first part has none', () => {
		expect(played(partwise(['', '', '', ''], ['', 'fine', '', 'dc']))).toBe('1 2 3 4 1 2')
	})

	it('combines jump marks spread over the parts', () => {
		expect(played(partwise(
			['', 'segno', '', '', 'ds', '', ''],
			['', '', 'tocoda', '', '', 'coda', ''],
		))).toBe('1 2 3 4 5 2 3 6 7')
	})

	it('takes repeats from the first part only', () => {
		expect(played(partwise(['|:', ':|', ''], ['', '', '']))).toBe('1 2 1 2 3')
		expect(played(partwise(['', '', ''], ['|:', ':|', '']))).toBe('1 2 3')
	})

	it('reads jump marks of every part of a timewise score', () => {
		const part = (id: string, content: string) => `<part id="${id}">${content}<note><rest/><duration>4</duration></note></part>`
		const xml = '<?xml version="1.0"?><score-timewise version="4.0"><part-list><score-part id="P1"/><score-part id="P2"/></part-list>'
			+ `<measure number="1">${part('P1', '')}${part('P2', '')}</measure>`
			+ `<measure number="2">${part('P1', '<barline location="right"><repeat direction="backward"/></barline>')}${part('P2', '')}</measure>`
			+ `<measure number="3">${part('P1', '')}${part('P2', '<direction><direction-type><words>D.C.</words></direction-type><sound dacapo="yes"/></direction>')}</measure>`
			+ '</score-timewise>'
		expect(played(xml)).toBe('1 2 1 2 3 1 2 3')
	})
})
