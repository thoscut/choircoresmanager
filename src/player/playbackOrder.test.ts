/// <reference types="node" />
// Run with: npm run test:player

import type { PlaybackOrderOptions } from './playbackOrder.ts'

import { DOMParser as XmlDomParser } from '@xmldom/xmldom'
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { getPlaybackOrder } from './playbackOrder.ts'

globalThis.DOMParser ??= XmlDomParser as unknown as typeof DOMParser

/**
 * Compact description of a synthetic test measure
 */
interface MeasureSpec {
	forward?: boolean
	/** Backward repeat; 2 is written without times attribute */
	backward?: number
	ending?: { number: string, start?: boolean, stop?: 'stop' | 'discontinue' }
	/** Attributes of <sound> elements in directions, e.g. 'fine="yes"' */
	sound?: string[]
	/** Attributes of a <sound> element directly in the measure */
	measureSound?: string
	/** Words of the directions; defaults to the sound attributes as in pruefe_abnahme.py */
	words?: string
	symbols?: ('segno' | 'coda')[]
}

/**
 * @param spec - The measure
 * @param number - The measure number
 */
function measureXml(spec: MeasureSpec, number: number): string {
	let xml = `<measure number="${number}">`
	if (number === 1) {
		xml += '<attributes><divisions>1</divisions><time><beats>4</beats><beat-type>4</beat-type></time></attributes>'
	}
	if (spec.forward || spec.ending?.start) {
		xml += '<barline location="left">'
		if (spec.ending?.start) {
			xml += `<ending number="${spec.ending.number}" type="start"/>`
		}
		if (spec.forward) {
			xml += '<repeat direction="forward"/>'
		}
		xml += '</barline>'
	}
	for (const sound of spec.sound ?? []) {
		xml += `<direction><direction-type><words>${spec.words ?? sound}</words></direction-type><sound ${sound}/></direction>`
	}
	if (!spec.sound && spec.words) {
		xml += `<direction><direction-type><words>${spec.words}</words></direction-type></direction>`
	}
	for (const symbol of spec.symbols ?? []) {
		xml += `<direction><direction-type><${symbol}/></direction-type></direction>`
	}
	if (spec.measureSound) {
		xml += `<sound ${spec.measureSound}/>`
	}
	xml += '<note><pitch><step>C</step><octave>4</octave></pitch><duration>4</duration><voice>1</voice><type>whole</type></note>'
	if (spec.backward || spec.ending?.stop) {
		xml += '<barline location="right">'
		if (spec.ending?.stop) {
			xml += `<ending number="${spec.ending.number}" type="${spec.ending.stop}"/>`
		}
		if (spec.backward) {
			xml += `<repeat direction="backward"${spec.backward !== 2 ? ` times="${spec.backward}"` : ''}/>`
		}
		xml += '</barline>'
	}
	return xml + '</measure>'
}

/**
 * Partwise MusicXML score
 *
 * @param parts - The measures of each part
 */
function score(...parts: MeasureSpec[][]): string {
	const partList = parts.map((_, index) => `<score-part id="P${index + 1}"><part-name>Part ${index + 1}</part-name></score-part>`).join('')
	const content = parts.map((measures, index) => `<part id="P${index + 1}">${measures.map((spec, k) => measureXml(spec, k + 1)).join('')}</part>`).join('')
	return '<?xml version="1.0" encoding="UTF-8"?>'
		+ '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">'
		+ `<score-partwise version="4.0"><part-list>${partList}</part-list>${content}</score-partwise>`
}

/**
 * Numbers of the played measures, separated by spaces
 *
 * @param xml - The MusicXML score
 * @param options - Playback options
 */
function played(xml: string, options?: PlaybackOrderOptions): string {
	const { measures, order } = getPlaybackOrder(xml, options)
	return order.measures.map((index) => measures[index].number).join(' ')
}

/**
 * @param xml - The MusicXML score
 */
function warnings(xml: string): string[] {
	return getPlaybackOrder(xml).order.warnings
}

describe('playback order: cases of pruefe_abnahme.py', () => {
	const cases: [string, MeasureSpec[], string][] = [
		['simple repeat', [{}, { forward: true }, { backward: 2 }, {}], '1 2 3 2 3 4'],
		['1st and 2nd ending', [
			{ forward: true },
			{},
			{ ending: { number: '1', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '2', start: true, stop: 'stop' } },
			{},
		], '1 2 3 1 2 4 5'],
		['three endings, two backward repeats (6-21)', [
			{ forward: true },
			{ ending: { number: '1, 2', start: true, stop: 'stop' } },
			{ ending: { number: '1', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '2', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '3', start: true, stop: 'stop' } },
			{},
		], '1 2 3 1 2 4 1 5 6'],
		['backward repeat without forward repeat after an earlier one (7-6)', [{}, { backward: 2 }, {}, { backward: 2 }, {}], '1 2 1 2 3 4 1 2 3 4 5'],
		['times=3', [{ forward: true }, { backward: 3 }, {}], '1 2 1 2 1 2 3'],
		['D.C. al Fine', [{}, { sound: ['fine="yes"'], words: 'Fine' }, {}, { sound: ['dacapo="yes"'], words: 'D.C. al Fine' }], '1 2 3 4 1 2'],
		['D.S. al Coda', [
			{},
			{ sound: ['segno="segno"'] },
			{ sound: ['tocoda="coda"'], words: 'al Coda' },
			{},
			{ sound: ['dalsegno="segno"'], words: 'D.S. al Coda' },
			{ sound: ['coda="coda"'] },
			{},
		], '1 2 3 4 5 2 3 6 7'],
		['D.C. without repeats after the jump', [
			{ forward: true },
			{ backward: 2 },
			{ sound: ['fine="yes"'], words: 'Fine' },
			{ sound: ['dacapo="yes"'], words: 'D.C. al Fine' },
		], '1 2 1 2 3 4 1 2 3'],
	]
	for (const [name, measures, expected] of cases) {
		it(name, () => {
			assert.equal(played(score(measures)), expected)
		})
	}
})

describe('playback order: repeats and voltas', () => {
	it('plays a piece without repeats in score order', () => {
		assert.equal(played(score([{}, {}, {}, {}, {}])), '1 2 3 4 5')
	})

	it('repeats from the first measure', () => {
		assert.equal(played(score([{ forward: true }, { backward: 2 }, {}])), '1 2 1 2 3')
	})

	it('plays a volta for two passes followed by a third ending (1.–2. / 3.)', () => {
		assert.equal(played(score([
			{ forward: true },
			{},
			{ ending: { number: '1, 2', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '3', start: true, stop: 'stop' } },
			{},
		])), '1 2 3 1 2 3 1 2 4 5')
	})

	it('plays times="3" on a volta (1.–3. / 4.)', () => {
		assert.equal(played(score([
			{ forward: true },
			{ ending: { number: '1, 2, 3', start: true, stop: 'stop' }, backward: 3 },
			{ ending: { number: '4', start: true, stop: 'stop' } },
		])), '1 2 1 2 1 2 1 3')
	})

	it('accepts a range as ending number', () => {
		assert.equal(played(score([
			{ forward: true },
			{ ending: { number: '1-2', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '3', start: true, stop: 'stop' } },
		])), '1 2 1 2 1 3')
	})

	it('plays volta brackets spanning several measures, ended by stop and discontinue', () => {
		assert.equal(played(score([
			{ forward: true },
			{},
			{ ending: { number: '1', start: true } },
			{ ending: { number: '1', stop: 'stop' }, backward: 2 },
			{ ending: { number: '2', start: true, stop: 'discontinue' } },
			{},
		])), '1 2 3 4 1 2 5 6')
	})

	it('jumps back to the last forward repeat from a backward repeat without its own forward repeat', () => {
		const xml = score([{}, { forward: true }, { backward: 2 }, {}, { backward: 2 }, {}])
		assert.equal(played(xml), '1 2 3 2 3 4 5 2 3 4 5 6')
		assert.equal(warnings(xml).length, 1)
		assert.match(warnings(xml)[0], /^Measure 5:/)
	})

	it('reports a backward repeat without forward repeat after an earlier one (7-6)', () => {
		assert.deepEqual(
			warnings(score([{}, { backward: 2 }, {}, { backward: 2 }, {}])).map((warning) => warning.split(':')[0]),
			['Measure 4'],
		)
		assert.deepEqual(warnings(score([{}, { forward: true }, { backward: 2 }, { forward: true }, { backward: 2 }])), [])
	})

	it('plays a repeat listed in playOnce only once', () => {
		const xml = score([{ forward: true }, { backward: 2 }, {}, { forward: true }, { backward: 2 }])
		assert.equal(played(xml, { playOnce: ['2'] }), '1 2 3 4 5 4 5')
		assert.equal(played(xml), '1 2 1 2 3 4 5 4 5')
	})

	it('plays times="1" once', () => {
		assert.equal(played(score([{ forward: true }, { backward: 1 }, {}])), '1 2 3')
	})
})

describe('playback order: jumps', () => {
	it('plays D.C. al Coda', () => {
		assert.equal(played(score([
			{},
			{ sound: ['tocoda="coda"'], words: 'To Coda' },
			{},
			{ sound: ['dacapo="yes"'], words: 'D.C. al Coda' },
			{ sound: ['coda="coda"'], words: 'Coda' },
			{},
		])), '1 2 3 4 1 2 5 6')
	})

	it('plays D.S. al Fine after repeats with the last volta only', () => {
		assert.equal(played(score([
			{},
			{ sound: ['segno="segno"'], forward: true },
			{},
			{ ending: { number: '1', start: true, stop: 'stop' }, backward: 2 },
			{ ending: { number: '2', start: true, stop: 'stop' }, sound: ['fine="yes"'], words: 'Fine' },
			{},
			{ sound: ['dalsegno="segno"'], words: 'D.S. al Fine' },
		])), '1 2 3 4 2 3 5 6 7 2 3 5')
	})

	it('plays D.C. to the end without Fine and Coda', () => {
		assert.equal(played(score([{}, {}, { sound: ['dacapo="yes"'], words: 'D.C.' }])), '1 2 3 1 2 3')
	})

	it('does not stop at a Fine before the jump', () => {
		assert.equal(played(score([{}, { sound: ['fine="yes"'], words: 'Fine' }, {}])), '1 2 3')
	})

	it('reads <sound> elements directly in the measure', () => {
		assert.equal(played(score([{}, { measureSound: 'fine="yes"' }, {}, { measureSound: 'dacapo="yes"' }])), '1 2 3 4 1 2')
	})

	it('ignores dacapo="no"', () => {
		assert.equal(played(score([{}, { sound: ['dacapo="no"'] }, {}])), '1 2 3')
	})

	it('uses segno and coda symbols when the score has no <sound> marks for them', () => {
		assert.equal(played(score([
			{},
			{ symbols: ['segno'] },
			{ symbols: ['coda'], words: 'To Coda' },
			{},
			{ sound: ['dalsegno="segno"'], words: 'D.S. al Coda' },
			{ symbols: ['coda'], words: 'Coda' },
			{},
		])), '1 2 3 4 5 2 3 6 7')
	})

	it('does not jump for D.S. without segno and reports it', () => {
		const xml = score([{}, {}, { sound: ['dalsegno="segno"'], words: 'D.S.' }, {}])
		assert.equal(played(xml), '1 2 3 4')
		assert.match(warnings(xml).join('\n'), /Measure 3: D\.S\. without a segno/)
	})

	it('does not jump for a jump text without <sound> and reports it', () => {
		const xml = score([{}, { sound: ['fine="yes"'], words: 'Fine' }, {}, { words: 'D.C. al Fine' }])
		assert.equal(played(xml), '1 2 3 4')
		assert.match(warnings(xml).join('\n'), /Measure 4: "D\.C\. al Fine" has no playback instruction/)
	})
})

describe('playback order: several parts', () => {
	it('takes jump marks from the second part when the first part has none', () => {
		assert.equal(played(score(
			[{}, {}, {}, {}],
			[{}, { sound: ['fine="yes"'], words: 'Fine' }, {}, { sound: ['dacapo="yes"'], words: 'D.C. al Fine' }],
		)), '1 2 3 4 1 2')
	})

	it('combines jump marks spread over the parts', () => {
		assert.equal(played(score(
			[{}, { sound: ['segno="segno"'] }, {}, {}, { sound: ['dalsegno="segno"'], words: 'D.S. al Coda' }, {}, {}],
			[{}, {}, { sound: ['tocoda="coda"'], words: 'To Coda' }, {}, {}, { sound: ['coda="coda"'] }, {}],
		)), '1 2 3 4 5 2 3 6 7')
	})

	it('takes repeats from the first part', () => {
		assert.equal(played(score([{ forward: true }, { backward: 2 }, {}], [{}, {}, {}])), '1 2 1 2 3')
	})

	it('reads timewise scores', () => {
		const measure = (number: number, barline = '') => `<measure number="${number}">`
			+ `<part id="P1">${barline}<note><rest/><duration>4</duration></note></part>`
			+ `<part id="P2"><note><rest/><duration>4</duration></note>${number === 3 ? '<direction><direction-type><words>D.C.</words></direction-type><sound dacapo="yes"/></direction>' : ''}</part>`
			+ '</measure>'
		const xml = '<score-timewise><part-list><score-part id="P1"/><score-part id="P2"/></part-list>'
			+ measure(1) + measure(2, '<barline location="right"><repeat direction="backward"/></barline>') + measure(3)
			+ '</score-timewise>'
		assert.equal(played(xml), '1 2 1 2 3 1 2 3')
	})
})
