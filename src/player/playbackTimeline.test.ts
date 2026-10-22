/// <reference types="node" />
// Run with: npm run test:player

import type { VerovioTimemapEntry } from 'verovio/esm'
import type { MeasureSpan, NoteEvent } from './scoreModel.ts'

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { buildLinearTimeline, buildPlaybackTimeline, findPlaybackTime, toScoreTime } from './playbackTimeline.ts'
import { matchMeasureNumbers, measureSpansFromTimemap } from './scoreModel.ts'

/**
 * @param id - Note id
 * @param start - Start on the score time axis
 * @param duration - Duration
 */
function note(id: string, start: number, duration = 500): NoteEvent {
	return { id, voiceKey: '1.1', pitch: 60, start, duration }
}

// Three measures of one second each, one note per measure
const spans: MeasureSpan[] = [{ start: 0, end: 1000 }, { start: 1000, end: 2000 }, { start: 2000, end: 3000 }]
const events = [note('a', 0), note('b', 1000), note('b2', 1500), note('c', 2000)]

describe('playback timeline', () => {
	it('keeps the score timing without repeats', () => {
		const timeline = buildLinearTimeline(spans, events)
		assert.deepEqual(timeline.events, events)
		assert.equal(timeline.duration, 2500)
		assert.equal(timeline.segments.length, 3)
	})

	it('lays the notes out along the playback order', () => {
		const timeline = buildPlaybackTimeline(spans, [0, 1, 0, 1, 2], events)
		assert.deepEqual(
			timeline.events.map((event) => `${event.id}@${event.start}`),
			['a@0', 'b@1000', 'b2@1500', 'a@2000', 'b@3000', 'b2@3500', 'c@4000'],
		)
		assert.equal(timeline.duration, 4500)
		assert.deepEqual(timeline.segments.map((segment) => [segment.measure, segment.start]), [[0, 0], [1, 1000], [0, 2000], [1, 3000], [2, 4000]])
	})

	it('keeps the duration of notes reaching into the next measure', () => {
		const timeline = buildPlaybackTimeline(spans, [0, 1, 0], [note('tied', 1500, 1000)])
		assert.deepEqual(timeline.events.map((event) => [event.start, event.duration]), [[1500, 1000]])
		assert.equal(timeline.duration, 2500)
	})

	it('skips measures without duration, e.g. merged into a multi-measure rest', () => {
		const merged: MeasureSpan[] = [{ start: 0, end: 1000 }, { start: 1000, end: 4000 }, { start: 4000, end: 4000 }, { start: 4000, end: 5000 }]
		const timeline = buildPlaybackTimeline(merged, [0, 1, 2, 3], [note('x', 0), note('y', 4000)])
		assert.deepEqual(timeline.segments.map((segment) => segment.measure), [0, 1, 3])
		assert.deepEqual(timeline.events.map((event) => `${event.id}@${event.start}`), ['x@0', 'y@4000'])
	})

	it('converts playback time to score time', () => {
		const timeline = buildPlaybackTimeline(spans, [0, 1, 0, 1, 2], events)
		assert.equal(toScoreTime(timeline, 0), 0)
		assert.equal(toScoreTime(timeline, 1500), 1500)
		assert.equal(toScoreTime(timeline, 2000), 0)
		assert.equal(toScoreTime(timeline, 3250), 1250)
		assert.equal(toScoreTime(timeline, 4999), 2999)
		// After the end, stay in the last measure
		assert.equal(toScoreTime(timeline, 6000), 2999)
	})

	it('finds the next occurrence of a point of the score', () => {
		const timeline = buildPlaybackTimeline(spans, [0, 1, 0, 1, 2], events)
		assert.equal(findPlaybackTime(timeline, 1500, 0), 1500)
		assert.equal(findPlaybackTime(timeline, 1500, 1500), 1500)
		assert.equal(findPlaybackTime(timeline, 1500, 1501), 1500)
		assert.equal(findPlaybackTime(timeline, 1500, 1600), 3500)
		// Not played again: first occurrence
		assert.equal(findPlaybackTime(timeline, 1500, 4000), 1500)
		assert.equal(findPlaybackTime(timeline, 2500, 0), 4500)
	})

	it('finds no playback time for measures that are never played', () => {
		const timeline = buildPlaybackTimeline(spans, [0, 2], events)
		assert.equal(findPlaybackTime(timeline, 1200, 0), null)
		assert.deepEqual(timeline.events.map((event) => event.id), ['a', 'c'])
	})
})

describe('measure spans from the timemap', () => {
	const measures = [{ id: 'm1', n: '1' }, { id: 'm2', n: '2' }, { id: 'm3', n: '5' }]

	it('computes the span of every measure', () => {
		const timemap: VerovioTimemapEntry[] = [
			{ tstamp: 0, qstamp: 0, measureOn: 'm1', on: ['a'] },
			{ tstamp: 500, qstamp: 1, off: ['a'] },
			{ tstamp: 2000, qstamp: 4, measureOn: 'm2' },
			{ tstamp: 8000, qstamp: 16, measureOn: 'm3' },
			{ tstamp: 10000, qstamp: 20, restsOff: ['r'] },
		]
		assert.deepEqual(measureSpansFromTimemap(measures, timemap), [{ start: 0, end: 2000 }, { start: 2000, end: 8000 }, { start: 8000, end: 10000 }])
	})

	it('gives measures missing from the timemap an empty span', () => {
		const timemap: VerovioTimemapEntry[] = [
			{ tstamp: 0, qstamp: 0, measureOn: 'm1' },
			{ tstamp: 2000, qstamp: 4, measureOn: 'm3' },
			{ tstamp: 4000, qstamp: 8 },
		]
		assert.deepEqual(measureSpansFromTimemap(measures, timemap), [{ start: 0, end: 2000 }, { start: 2000, end: 2000 }, { start: 2000, end: 4000 }])
	})

	it('returns null for a timemap without measure onsets', () => {
		assert.equal(measureSpansFromTimemap(measures, [{ tstamp: 0, qstamp: 0, on: ['a'] }]), null)
	})

	it('matches MusicXML measures to MEI measures', () => {
		assert.deepEqual(matchMeasureNumbers(['1', '2', '3'], [{ id: 'a', n: '1' }, { id: 'b', n: '2' }, { id: 'c', n: '3' }]), [0, 1, 2])
		// Measures 2 to 4 are one multi-measure rest in the MEI output
		assert.deepEqual(matchMeasureNumbers(['1', '2', '3', '4', '5'], measures), [0, 1, null, null, 2])
		// Numbering restarts in a second movement
		assert.deepEqual(
			matchMeasureNumbers(['1', '2', '3', '1', '2', '3'], [{ id: 'a', n: '1' }, { id: 'b', n: '2' }, { id: 'c', n: '3' }, { id: 'd', n: '1' }, { id: 'e', n: '2' }]),
			[0, 1, 2, 3, 4, null],
		)
	})
})
