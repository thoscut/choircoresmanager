/**
 * The events the synthesizer plays, in seconds at 100 % tempo. Note times
 * come from Verovio's linear timemap (no repeats, tempo changes included);
 * the measures are then laid out in playing order, so repeats and ranges
 * reuse the same timings.
 */
import type { MeiNote, MeiTie } from './mei.ts'

import { memberKey } from './voices.ts'

/** One entry of `toolkit.renderToTimemap()`. */
export interface TimemapEntry {
	tstamp: number
	qstamp?: number
	on?: string[]
	off?: string[]
	measureOn?: string
	tempo?: number
}

export interface LinearTiming {
	/** start and end of every measure in ms, linear (index = measure index) */
	measures: { start: number, end: number }[]
	/** on and off time of every note in ms */
	notes: Map<string, { on: number, off: number }>
	/** first tempo in quarter notes per minute */
	tempo: number | null
}

export interface PlayEvent {
	/** note id in the SVG */
	id: string
	/** voice member key (staff.layer) */
	voice: string
	pitch: number
	/** start in seconds at 100 % tempo */
	start: number
	end: number
	/** false: continuation of a tied note, not struck again */
	sound: boolean
	/** end of the sound including tied continuations */
	soundEnd: number
	measure: number
}

export interface MeasureSlot {
	/** measure index */
	index: number
	start: number
	end: number
}

export interface Timeline {
	events: PlayEvent[]
	measures: MeasureSlot[]
	duration: number
}

/**
 * Measure and note times from a linear timemap.
 *
 * @param timemap - `renderToTimemap({ includeMeasures: true })` with `expandNever`
 * @param measureIds - ids of all measures in score order
 */
export function linearTiming(timemap: TimemapEntry[], measureIds: string[]): LinearTiming {
	const measureStart = new Map<string, number>()
	const notes = new Map<string, { on: number, off: number }>()
	let tempo: number | null = null
	let last = 0
	for (const entry of timemap) {
		last = Math.max(last, entry.tstamp)
		if (entry.measureOn && !measureStart.has(entry.measureOn)) {
			measureStart.set(entry.measureOn, entry.tstamp)
		}
		if (tempo === null && typeof entry.tempo === 'number' && entry.tempo > 0) {
			tempo = entry.tempo
		}
		for (const id of entry.on ?? []) {
			if (!notes.has(id)) {
				notes.set(id, { on: entry.tstamp, off: entry.tstamp })
			}
		}
		for (const id of entry.off ?? []) {
			const note = notes.get(id)
			if (note) {
				note.off = Math.max(note.off, entry.tstamp)
			}
		}
	}
	const starts = measureIds.map((id) => measureStart.get(id))
	// Measures without an entry start where the next known one starts
	let next = last
	for (let i = starts.length - 1; i >= 0; i--) {
		const start = starts[i]
		if (start === undefined) {
			starts[i] = next
		} else {
			next = start
		}
	}
	const measures = starts.map((start, i) => {
		const begin = start ?? 0
		const end = i + 1 < starts.length ? (starts[i + 1] ?? begin) : last
		return { start: begin, end: Math.max(begin, end) }
	})
	return { measures, notes, tempo }
}

/**
 * Events for a playing order.
 *
 * @param order - measure indices in playing order
 * @param timing - linear timing
 * @param notesByMeasure - notes of every measure
 * @param ties - tied notes (start id → end id)
 */
export function buildTimeline(order: number[], timing: LinearTiming, notesByMeasure: MeiNote[][], ties: MeiTie[]): Timeline {
	const events: PlayEvent[] = []
	const measures: MeasureSlot[] = []
	let cursor = 0
	for (const index of order) {
		const measure = timing.measures[index]
		if (!measure) {
			continue
		}
		const offset = cursor - measure.start
		for (const note of notesByMeasure[index] ?? []) {
			const time = timing.notes.get(note.id)
			if (!time || note.pitch === null || time.off <= time.on) {
				continue
			}
			const start = Math.round(time.on + offset)
			const end = Math.round(time.off + offset)
			events.push({
				id: note.id,
				voice: memberKey(note.staff, note.layer),
				pitch: note.pitch,
				start,
				end,
				sound: true,
				soundEnd: end,
				measure: index,
			})
		}
		const length = measure.end - measure.start
		measures.push({ index, start: cursor, end: cursor + length })
		cursor += length
	}
	events.sort((a, b) => a.start - b.start || a.pitch - b.pitch)

	// Tied notes sound as one: the continuation is not struck again
	const tieStart = new Map(ties.map((tie) => [tie.end, tie.start]))
	const byEnd = new Map<string, PlayEvent>()
	const rootOf = new Map<PlayEvent, PlayEvent>()
	for (const event of events) {
		const startId = tieStart.get(event.id)
		if (startId !== undefined) {
			const head = byEnd.get(`${startId}@${event.start}`)
			if (head && head.pitch === event.pitch) {
				const root = rootOf.get(head) ?? head
				event.sound = false
				root.soundEnd = Math.max(root.soundEnd, event.end)
				rootOf.set(event, root)
			}
		}
		byEnd.set(`${event.id}@${event.end}`, event)
	}

	const toSeconds = (ms: number) => ms / 1000
	return {
		events: events.map((event) => ({
			...event,
			start: toSeconds(event.start),
			end: toSeconds(event.end),
			soundEnd: toSeconds(event.soundEnd),
		})),
		measures: measures.map((slot) => ({ ...slot, start: toSeconds(slot.start), end: toSeconds(slot.end) })),
		duration: toSeconds(cursor),
	}
}
