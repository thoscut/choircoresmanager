// Lays the notes of a score out along its playback order (repeats, voltas and jumps)
//
// Two time axes are involved, both in milliseconds at the score's original tempo:
// - score time: each measure once, in score order, as in Verovio's unexpanded timemap. Verovio's
//   highlighting (getElementsAtTime) and getTimeForElement work on this axis.
// - playback time: the measures in playback order. Audio, position and duration use this axis.

import type { MeasureSpan, NoteEvent } from './scoreModel.ts'

/**
 * One played measure
 */
export interface PlaybackSegment {
	/** Index of the measure span */
	measure: number
	/** Start on the playback time axis */
	start: number
	/** Start on the score time axis */
	scoreStart: number
	/** End on the score time axis */
	scoreEnd: number
}

export interface PlaybackTimeline {
	/** Played measures in playback order */
	segments: PlaybackSegment[]
	/** Notes to play, with start times on the playback time axis, sorted by start */
	events: NoteEvent[]
	/** End of the last note on the playback time axis */
	duration: number
}

// Positions this close before a note still count as being at the note
const SEEK_TOLERANCE_MS = 5

/**
 * Index of the last item whose start is at or before a time, 0 if there is none
 *
 * @param items - Items sorted by start
 * @param start - Function returning the start of an item
 * @param time - The time
 */
function lastStartingAtOrBefore<T>(items: T[], start: (item: T) => number, time: number): number {
	let low = 0
	let high = items.length - 1
	let found = 0
	while (low <= high) {
		const middle = (low + high) >> 1
		if (start(items[middle]) <= time) {
			found = middle
			low = middle + 1
		} else {
			high = middle - 1
		}
	}
	return found
}

/**
 * Lay the notes of a score out along its playback order.
 *
 * Each note keeps its id, so that every occurrence highlights the same element of the score,
 * and its duration, also where the next played measure is not the next one in the score.
 *
 * @param spans - Time span of every measure on the score time axis, in score order
 * @param order - Indices of the measures in playback order
 * @param events - Notes with start times on the score time axis
 */
export function buildPlaybackTimeline(spans: MeasureSpan[], order: number[], events: NoteEvent[]): PlaybackTimeline {
	const eventsPerMeasure: NoteEvent[][] = spans.map(() => [])
	if (spans.length > 0) {
		for (const event of events) {
			eventsPerMeasure[lastStartingAtOrBefore(spans, (span) => span.start, event.start)].push(event)
		}
	}

	const segments: PlaybackSegment[] = []
	const playbackEvents: NoteEvent[] = []
	let cursor = 0
	for (const measure of order) {
		const span = spans[measure]
		if (!span || span.end <= span.start) {
			continue
		}
		segments.push({ measure, start: cursor, scoreStart: span.start, scoreEnd: span.end })
		for (const event of eventsPerMeasure[measure]) {
			playbackEvents.push({ ...event, start: event.start - span.start + cursor })
		}
		cursor += span.end - span.start
	}
	playbackEvents.sort((a, b) => a.start - b.start)

	return {
		segments,
		events: playbackEvents,
		duration: playbackEvents.reduce((max, event) => Math.max(max, event.start + event.duration), 0),
	}
}

/**
 * Timeline playing every measure once in score order
 *
 * @param spans - Time span of every measure, in score order
 * @param events - Notes with start times on the score time axis
 */
export function buildLinearTimeline(spans: MeasureSpan[], events: NoteEvent[]): PlaybackTimeline {
	return buildPlaybackTimeline(spans, spans.map((_, index) => index), events)
}

/**
 * Convert a time on the playback time axis to the score time axis
 *
 * @param timeline - The playback timeline
 * @param time - Time on the playback time axis
 */
export function toScoreTime(timeline: PlaybackTimeline, time: number): number {
	const { segments } = timeline
	if (segments.length === 0) {
		return time
	}
	const segment = segments[lastStartingAtOrBefore(segments, (item) => item.start, time)]
	const length = segment.scoreEnd - segment.scoreStart
	// Stay inside the measure, also after the last measure while its notes fade out
	return segment.scoreStart + Math.min(Math.max(0, time - segment.start), Math.max(0, length - 1))
}

/**
 * Find where a point of the score is played: its first occurrence at or after a given
 * playback time, else its first occurrence.
 *
 * @param timeline - The playback timeline
 * @param scoreTime - Time on the score time axis, e.g. the start of a clicked note
 * @param from - Time on the playback time axis, e.g. the current position
 * @return Time on the playback time axis, or null if that point of the score is never played
 */
export function findPlaybackTime(timeline: PlaybackTimeline, scoreTime: number, from: number): number | null {
	let first: number | null = null
	for (const segment of timeline.segments) {
		if (scoreTime < segment.scoreStart || scoreTime >= segment.scoreEnd) {
			continue
		}
		const time = segment.start + scoreTime - segment.scoreStart
		if (time >= from - SEEK_TOLERANCE_MS) {
			return time
		}
		if (first === null) {
			first = time
		}
	}
	return first
}
