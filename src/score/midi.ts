/**
 * Writing Standard MIDI Files, format 1: one track per voice plus a first
 * track with tempo, time and key signature ("conductor track").
 *
 * Every channel event is written with its status byte (no running status):
 * a few bytes more, but every player and editor reads it the same way.
 */

/** Ticks per quarter note */
export const PPQ = 480

/** One event at an absolute time. */
export interface MidiEvent {
	/** absolute time in ticks */
	tick: number
	/** status byte and data bytes, or a meta event (0xff, type, length, data) */
	bytes: number[]
}

export interface MidiTrack {
	/** events in the order they are written; sorted by tick, equal ticks keep their order */
	events: MidiEvent[]
	/** tick of the end-of-track event; at least the tick of the last event */
	end?: number
}

/**
 * A number as MIDI variable-length quantity: 7 bits per byte, the highest
 * bit set on every byte but the last.
 *
 * @param value - 0 … 0x0fffffff
 */
export function variableLength(value: number): number[] {
	if (!Number.isInteger(value) || value < 0 || value > 0x0fffffff) {
		throw new RangeError(`MIDI variable-length value out of range: ${value}`)
	}
	const bytes = [value & 0x7f]
	let rest = value >>> 7
	while (rest > 0) {
		bytes.unshift((rest & 0x7f) | 0x80)
		rest >>>= 7
	}
	return bytes
}

/**
 * A meta event.
 *
 * @param type - meta event type, e.g. 0x03 for a track name
 * @param data - its data bytes
 */
export function metaEvent(type: number, data: number[]): number[] {
	return [0xff, type, ...variableLength(data.length), ...data]
}

/**
 * Text as bytes for a MIDI text event: printable ASCII only, anything else
 * becomes "?" (convert names with `asciiText` first).
 *
 * @param text - the text
 */
function textBytes(text: string): number[] {
	return [...text].map((char) => {
		const code = char.charCodeAt(0)
		return code >= 0x20 && code < 0x7f ? code : 0x3f
	})
}

/**
 * Sequence or track name (meta event 0x03).
 *
 * @param name - ASCII name
 */
export function trackName(name: string): number[] {
	return metaEvent(0x03, textBytes(name))
}

/**
 * Tempo (meta event 0x51) in microseconds per quarter note.
 *
 * @param quartersPerMinute - tempo in quarter notes per minute
 */
export function tempoEvent(quartersPerMinute: number): number[] {
	const micros = Math.min(0xffffff, Math.max(1, Math.round(60_000_000 / quartersPerMinute)))
	return metaEvent(0x51, [(micros >> 16) & 0xff, (micros >> 8) & 0xff, micros & 0xff])
}

/**
 * Time signature (meta event 0x58), e.g. 3/4 or 6/8. The metronome clicks
 * on the beat unit; 8 thirty-second notes per quarter.
 *
 * @param numerator - beats per bar
 * @param denominator - beat unit, a power of two
 */
export function timeSignatureEvent(numerator: number, denominator: number): number[] {
	const power = Math.round(Math.log2(denominator))
	return metaEvent(0x58, [numerator, power, Math.max(1, Math.round(96 / denominator)), 8])
}

/**
 * Key signature (meta event 0x59).
 *
 * @param fifths - sharps (positive) or flats (negative)
 * @param minor - minor key
 */
export function keySignatureEvent(fifths: number, minor: boolean): number[] {
	return metaEvent(0x59, [fifths & 0xff, minor ? 1 : 0])
}

/**
 * @param channel - 0 … 15
 * @param program - General MIDI program 0 … 127
 */
export function programChange(channel: number, program: number): number[] {
	return [0xc0 | channel, program]
}

/**
 * @param channel - 0 … 15
 * @param controller - controller number, e.g. 7 for the channel volume
 * @param value - 0 … 127
 */
export function controlChange(channel: number, controller: number, value: number): number[] {
	return [0xb0 | channel, controller, value]
}

/**
 * @param channel - 0 … 15
 * @param pitch - MIDI pitch
 * @param velocity - 1 … 127
 */
export function noteOn(channel: number, pitch: number, velocity: number): number[] {
	return [0x90 | channel, pitch, velocity]
}

/**
 * @param channel - 0 … 15
 * @param pitch - MIDI pitch
 */
export function noteOff(channel: number, pitch: number): number[] {
	return [0x80 | channel, pitch, 0x40]
}

/**
 * Bytes of one track chunk ("MTrk") with delta times and end of track.
 *
 * @param track - the track
 */
function trackChunk(track: MidiTrack): number[] {
	const events = [...track.events].sort((a, b) => a.tick - b.tick)
	const body: number[] = []
	let last = 0
	for (const event of events) {
		body.push(...variableLength(event.tick - last), ...event.bytes)
		last = event.tick
	}
	const end = Math.max(last, track.end ?? 0)
	body.push(...variableLength(end - last), 0xff, 0x2f, 0x00)
	return [0x4d, 0x54, 0x72, 0x6b, ...uint32(body.length), ...body]
}

function uint32(value: number): number[] {
	return [(value >>> 24) & 0xff, (value >>> 16) & 0xff, (value >>> 8) & 0xff, value & 0xff]
}

/**
 * A Standard MIDI File, format 1.
 *
 * @param tracks - the tracks; the first one carries tempo and signatures
 * @param division - ticks per quarter note
 */
export function writeMidi(tracks: MidiTrack[], division = PPQ): Uint8Array {
	if (tracks.length === 0 || tracks.length > 0xffff) {
		throw new RangeError('A MIDI file needs 1 to 65535 tracks.')
	}
	const header = [0x4d, 0x54, 0x68, 0x64, ...uint32(6), 0x00, 0x01, (tracks.length >> 8) & 0xff, tracks.length & 0xff,
		(division >> 8) & 0x7f, division & 0xff]
	const chunks = tracks.map(trackChunk)
	const bytes = new Uint8Array(header.length + chunks.reduce((sum, chunk) => sum + chunk.length, 0))
	bytes.set(header, 0)
	let offset = header.length
	for (const chunk of chunks) {
		bytes.set(chunk, offset)
		offset += chunk.length
	}
	return bytes
}
