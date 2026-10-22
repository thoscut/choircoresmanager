/// <reference types="node" />
// End-to-end test of the playback timeline with the bundled Verovio toolkit.
// Run with: npm run test:player

import type { PlaybackTimeline } from './playbackTimeline.ts'

import { DOMParser as XmlDomParser } from '@xmldom/xmldom'
import assert from 'node:assert/strict'
import { before, describe, it } from 'node:test'
import { VerovioToolkit } from 'verovio/esm'
import createVerovioModule from 'verovio/wasm'
import { getPlaybackOrder } from './playbackOrder.ts'
import { buildPlaybackTimeline, findPlaybackTime, toScoreTime } from './playbackTimeline.ts'
import { buildNoteEvents, extractMeasures, matchMeasureNumbers, measureSpansFromTimemap, parseMei } from './scoreModel.ts'

globalThis.DOMParser ??= XmlDomParser as unknown as typeof DOMParser

const STEPS = ['C', 'D', 'E', 'F', 'G', 'A', 'B']
const SEMITONES = [0, 2, 4, 5, 7, 9, 11]
// A whole note at Verovio's default tempo of 120 quarter notes per minute
const MEASURE_MS = 2000

/**
 * MIDI pitch of the whole note in a test measure; every measure has its own pitch
 *
 * @param measure - Measure number, starting at 1
 */
function pitchOf(measure: number): number {
	return 60 + SEMITONES[(measure - 1) % 7] + 12 * Math.floor((measure - 1) / 7)
}

/**
 * Partwise score with one part. Measures are written as MusicXML snippets placed before
 * the note; "rest" replaces the note by a whole-measure rest.
 *
 * @param measures - Content of each measure, or a tuple of content before and after the note
 */
function score(measures: (string | [string, string])[]): string {
	const content = measures.map((measure, index) => {
		const number = index + 1
		const [before, after] = typeof measure === 'string' ? [measure, ''] : measure
		const attributes = number === 1
			? '<attributes><divisions>1</divisions><time><beats>4</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>'
			: ''
		const note = before.includes('<multiple-rest>') || after === 'rest'
			? '<note><rest measure="yes"/><duration>4</duration><voice>1</voice></note>'
			: `<note><pitch><step>${STEPS[index % 7]}</step><octave>${4 + Math.floor(index / 7)}</octave></pitch><duration>4</duration><voice>1</voice><type>whole</type></note>`
		return `<measure number="${number}">${attributes}${before}${note}${after === 'rest' ? '' : after}</measure>`
	}).join('')
	return '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0">'
		+ '<part-list><score-part id="P1"><part-name>Tenor</part-name></score-part></part-list>'
		+ `<part id="P1">${content}</part></score-partwise>`
}

const FORWARD = '<barline location="left"><repeat direction="forward"/></barline>'
const BACKWARD = '<barline location="right"><repeat direction="backward"/></barline>'

/**
 * @param number - Ending number
 * @param repeat - Whether the ending closes with a backward repeat
 */
function ending(number: string, repeat = false): [string, string] {
	return [
		`<barline location="left"><ending number="${number}" type="start"/></barline>`,
		`<barline location="right"><ending number="${number}" type="stop"/>${repeat ? '<repeat direction="backward"/>' : ''}</barline>`,
	]
}

/**
 * @param sound - Attributes of the sound element
 * @param words - Text of the direction
 */
function direction(sound: string, words = ''): string {
	return `<direction><direction-type><words>${words}</words></direction-type><sound ${sound}/></direction>`
}

let toolkit: VerovioToolkit

/**
 * Load a score as the player does and lay its notes out along the playback order
 *
 * @param xml - The MusicXML score
 */
function load(xml: string): PlaybackTimeline {
	toolkit.setOptions({ expandNever: true })
	assert.ok(toolkit.loadData(xml))
	const mei = parseMei(toolkit.getMEI())
	const noteVoices = new Map(Array.from(mei.getElementsByTagName('note')).map((note) => [note.getAttribute('xml:id') ?? '', '1.1']))
	const timemap = toolkit.renderToTimemap({ includeRests: true, includeMeasures: true })
	const events = buildNoteEvents(timemap, noteVoices, (id) => {
		const values = toolkit.getMIDIValuesForElement(id)
		return values && typeof values.pitch === 'number' ? values : null
	})
	const meiMeasures = extractMeasures(mei)
	const spans = measureSpansFromTimemap(meiMeasures, timemap)
	assert.ok(spans)
	const { measures, order } = getPlaybackOrder(xml)
	const meiIndices = matchMeasureNumbers(measures.map((measure) => measure.number), meiMeasures)
	return buildPlaybackTimeline(spans, order.measures.map((index) => meiIndices[index]).filter((index) => index !== null), events)
}

/**
 * Played measures, identified by their pitch, with their start in measures
 *
 * @param timeline - The playback timeline
 */
function playedMeasures(timeline: PlaybackTimeline): string {
	return timeline.events.map((event) => {
		const measure = [...Array(14).keys()].map((k) => k + 1).find((k) => pitchOf(k) === event.pitch)
		return `${measure}@${event.start / MEASURE_MS}`
	}).join(' ')
}

describe('playback with Verovio', () => {
	before(async () => {
		toolkit = new VerovioToolkit(await createVerovioModule())
	})

	it('plays three endings with two backward repeats (Verovio\'s own expansion gets this wrong)', () => {
		const timeline = load(score([FORWARD, ending('1, 2'), ending('1', true), ending('2', true), ending('3'), '']))
		assert.equal(playedMeasures(timeline), '1@0 2@1 3@2 1@3 2@4 4@5 1@6 5@7 6@8')
		assert.equal(timeline.duration, 9 * MEASURE_MS)
	})

	it('plays D.S. al Coda', () => {
		const timeline = load(score([
			'',
			direction('segno="segno"'),
			direction('tocoda="coda"', 'To Coda'),
			'',
			direction('dalsegno="segno"', 'D.S. al Coda'),
			direction('coda="coda"', 'Coda'),
			'',
		]))
		assert.equal(playedMeasures(timeline), '1@0 2@1 3@2 4@3 5@4 2@5 3@6 6@7 7@8')
	})

	it('maps the measures of a multi-measure rest that Verovio merges', () => {
		const multiRest = '<attributes><measure-style><multiple-rest>3</multiple-rest></measure-style></attributes>'
		const timeline = load(score(['', multiRest, ['', 'rest'], ['', 'rest'], FORWARD, ['', BACKWARD]]))
		assert.equal(playedMeasures(timeline), '1@0 5@4 6@5 5@6 6@7')
		assert.equal(timeline.duration, 8 * MEASURE_MS)
	})

	it('plays each pass of a repeat at the tempo of its measures', () => {
		const timeline = load(score([FORWARD, '', [direction('tempo="60"'), BACKWARD], '']))
		// Measures 3 and 4 are at half the tempo; the second pass starts at the original tempo again
		assert.deepEqual(timeline.events.map((event) => event.start), [0, 2000, 4000, 8000, 10000, 12000, 16000])
		assert.equal(timeline.duration, 20000)
	})

	it('highlights the notes of the current pass and seeks to the next pass of a clicked note', () => {
		const timeline = load(score(['', FORWARD, ['', BACKWARD], '']))
		assert.equal(playedMeasures(timeline), '1@0 2@1 3@2 2@3 3@4 4@5')
		const noteOfMeasure3 = timeline.events[2].id
		const second = timeline.events[4]
		assert.equal(second.id, noteOfMeasure3)
		assert.deepEqual(toolkit.getElementsAtTime(Math.round(toScoreTime(timeline, second.start + 100))).notes, [noteOfMeasure3])

		const scoreTime = toolkit.getTimeForElement(noteOfMeasure3)
		assert.equal(findPlaybackTime(timeline, scoreTime, 0), 2 * MEASURE_MS)
		assert.equal(findPlaybackTime(timeline, scoreTime, 2 * MEASURE_MS + 500), 4 * MEASURE_MS)
		assert.equal(findPlaybackTime(timeline, scoreTime, 5 * MEASURE_MS), 2 * MEASURE_MS)
	})
})
