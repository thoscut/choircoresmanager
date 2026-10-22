/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PracticePart } from '../score/practice.ts'
import type { Timeline } from '../score/timeline.ts'
import type { Voice } from '../score/voices.ts'

import { describe, expect, it } from 'vitest'
import {
	controlChange,
	keySignatureEvent,
	noteOff,
	noteOn,
	programChange,
	tempoEvent,
	timeSignatureEvent,
	trackName,
	variableLength,
	writeMidi,
} from '../score/midi.ts'
import { loadScore, playbackTimeline, playingOrder, timelineFor } from '../score/model.ts'
import { readScoreText } from '../score/mxl.ts'
import { scheduleEvents, voiceGain, voiceGains } from '../score/player.ts'
import {
	asciiText,
	estimateBytes,
	fileNamePart,
	fittingSignature,
	midiVolume,
	partGains,
	practiceFileName,
	practiceMidi,
	practiceParts,
	withoutOverlaps,
} from '../score/practice.ts'
import { normalizePeak } from '../score/render.ts'
import { linearTiming } from '../score/timeline.ts'
import { createToolkit } from '../score/toolkit.ts'
import { memberKey } from '../score/voices.ts'
import { encodeWav, wavBytes, wavHeader } from '../score/wav.ts'
import { crc32, zipStore } from '../score/zip.ts'

// ---- a small MIDI reader for the checks -------------------------------------------------

interface ReadEvent {
	tick: number
	/** status byte; 0xff for meta events */
	status: number
	/** meta type, or -1 */
	meta: number
	data: number[]
}

interface ReadMidi {
	format: number
	division: number
	tracks: ReadEvent[][]
}

/**
 * Reads a Standard MIDI File and fails on running status: every channel
 * event must start with its status byte.
 *
 * @param bytes - the file
 */
function readMidi(bytes: Uint8Array): ReadMidi {
	const text = (offset: number) => String.fromCharCode(...bytes.slice(offset, offset + 4))
	const u32 = (offset: number) => ((bytes[offset] << 24) | (bytes[offset + 1] << 16) | (bytes[offset + 2] << 8) | bytes[offset + 3]) >>> 0
	expect(text(0)).toBe('MThd')
	expect(u32(4)).toBe(6)
	const format = (bytes[8] << 8) | bytes[9]
	const count = (bytes[10] << 8) | bytes[11]
	const division = (bytes[12] << 8) | bytes[13]
	const tracks: ReadEvent[][] = []
	let offset = 14
	for (let n = 0; n < count; n++) {
		expect(text(offset)).toBe('MTrk')
		const end = offset + 8 + u32(offset + 4)
		let position = offset + 8
		let tick = 0
		const events: ReadEvent[] = []
		const vlq = () => {
			let value = 0
			let byte
			do {
				byte = bytes[position++]
				value = (value << 7) | (byte & 0x7f)
			} while (byte & 0x80)
			return value
		}
		while (position < end) {
			tick += vlq()
			const status = bytes[position++]
			if (status < 0x80) {
				throw new Error(`running status at byte ${position - 1}`)
			}
			if (status === 0xff) {
				const meta = bytes[position++]
				const length = vlq()
				events.push({ tick, status, meta, data: [...bytes.slice(position, position + length)] })
				position += length
			} else {
				const length = (status & 0xf0) === 0xc0 || (status & 0xf0) === 0xd0 ? 1 : 2
				events.push({ tick, status, meta: -1, data: [...bytes.slice(position, position + length)] })
				position += length
			}
		}
		expect(position).toBe(end)
		const last = events[events.length - 1]
		expect([last.meta, last.data.length]).toEqual([0x2f, 0])
		tracks.push(events)
		offset = end
	}
	expect(offset).toBe(bytes.length)
	return { format, division, tracks }
}

// ---- MIDI writer ------------------------------------------------------------------------

describe('MIDI writer', () => {
	it('writes variable-length quantities', () => {
		expect(variableLength(0)).toEqual([0x00])
		expect(variableLength(0x40)).toEqual([0x40])
		expect(variableLength(0x7f)).toEqual([0x7f])
		expect(variableLength(0x80)).toEqual([0x81, 0x00])
		expect(variableLength(0x2000)).toEqual([0xc0, 0x00])
		expect(variableLength(0x3fff)).toEqual([0xff, 0x7f])
		expect(variableLength(0x4000)).toEqual([0x81, 0x80, 0x00])
		expect(variableLength(0x0fffffff)).toEqual([0xff, 0xff, 0xff, 0x7f])
		expect(() => variableLength(0x10000000)).toThrow(RangeError)
		expect(() => variableLength(-1)).toThrow(RangeError)
	})

	it('writes meta events for tempo and signatures', () => {
		// 120 quarter notes per minute = 500000 µs per quarter
		expect(tempoEvent(120)).toEqual([0xff, 0x51, 0x03, 0x07, 0xa1, 0x20])
		expect(timeSignatureEvent(3, 4)).toEqual([0xff, 0x58, 0x04, 3, 2, 24, 8])
		expect(timeSignatureEvent(6, 8)).toEqual([0xff, 0x58, 0x04, 6, 3, 12, 8])
		expect(keySignatureEvent(-1, false)).toEqual([0xff, 0x59, 0x02, 0xff, 0])
		expect(keySignatureEvent(3, true)).toEqual([0xff, 0x59, 0x02, 3, 1])
		expect(trackName('Tenor I')).toEqual([0xff, 0x03, 7, ...[...'Tenor I'].map((c) => c.charCodeAt(0))])
	})

	it('writes a format 1 file byte for byte', () => {
		const bytes = writeMidi([
			{ events: [{ tick: 0, bytes: trackName('A') }, { tick: 0, bytes: timeSignatureEvent(4, 4) }, { tick: 0, bytes: tempoEvent(120) }], end: 960 },
			{
				events: [
					{ tick: 0, bytes: trackName('S') },
					{ tick: 0, bytes: programChange(0, 20) },
					{ tick: 0, bytes: controlChange(0, 7, 127) },
					{ tick: 0, bytes: noteOn(0, 60, 80) },
					{ tick: 480, bytes: noteOff(0, 60) },
					{ tick: 480, bytes: noteOn(0, 62, 80) },
					{ tick: 960, bytes: noteOff(0, 62) },
				],
				end: 960,
			},
		])
		expect([...bytes]).toEqual([
			// header: MThd, length 6, format 1, 2 tracks, 480 ticks per quarter
			0x4d, 0x54, 0x68, 0x64, 0, 0, 0, 6, 0, 1, 0, 2, 0x01, 0xe0,
			// conductor track, 25 bytes
			0x4d, 0x54, 0x72, 0x6b, 0, 0, 0, 25,
			0x00, 0xff, 0x03, 0x01, 0x41,
			0x00, 0xff, 0x58, 0x04, 4, 2, 24, 8,
			0x00, 0xff, 0x51, 0x03, 0x07, 0xa1, 0x20,
			// end of track two half notes later: delta 960 = 0x87 0x40
			0x87, 0x40, 0xff, 0x2f, 0x00,
			// voice track, 34 bytes, every event with its status byte
			0x4d, 0x54, 0x72, 0x6b, 0, 0, 0, 34,
			0x00, 0xff, 0x03, 0x01, 0x53,
			0x00, 0xc0, 20,
			0x00, 0xb0, 7, 127,
			0x00, 0x90, 60, 80,
			0x83, 0x60, 0x80, 60, 0x40,
			0x00, 0x90, 62, 80,
			0x83, 0x60, 0x80, 62, 0x40,
			0x00, 0xff, 0x2f, 0x00,
		])
		const read = readMidi(bytes)
		expect(read.format).toBe(1)
		expect(read.division).toBe(480)
		expect(read.tracks[1].filter((event) => (event.status & 0xf0) === 0x90).map((event) => [event.tick, event.data[0]])).toEqual([[0, 60], [480, 62]])
	})

	it('keeps the order of events at the same tick and sorts by tick', () => {
		const bytes = writeMidi([{ events: [{ tick: 10, bytes: noteOn(1, 64, 80) }, { tick: 0, bytes: noteOn(1, 60, 80) }, { tick: 10, bytes: noteOff(1, 60) }] }])
		const events = readMidi(bytes).tracks[0]
		expect(events.map((event) => [event.tick, event.status, event.data[0]])).toEqual([[0, 0x91, 60], [10, 0x91, 64], [10, 0x81, 60], [10, 0xff, undefined]])
	})
})

// ---- WAV ---------------------------------------------------------------------------------

describe('WAV writer', () => {
	it('writes the canonical 44-byte header', () => {
		const header = wavHeader(1000, 44100, 2)
		const view = new DataView(header.buffer)
		const text = (offset: number) => String.fromCharCode(...header.slice(offset, offset + 4))
		expect(header.length).toBe(44)
		expect(text(0)).toBe('RIFF')
		expect(view.getUint32(4, true)).toBe(36 + 4000)
		expect(text(8)).toBe('WAVE')
		expect(text(12)).toBe('fmt ')
		expect(view.getUint32(16, true)).toBe(16)
		expect(view.getUint16(20, true)).toBe(1)
		expect(view.getUint16(22, true)).toBe(2)
		expect(view.getUint32(24, true)).toBe(44100)
		expect(view.getUint32(28, true)).toBe(44100 * 4)
		expect(view.getUint16(32, true)).toBe(4)
		expect(view.getUint16(34, true)).toBe(16)
		expect(text(36)).toBe('data')
		expect(view.getUint32(40, true)).toBe(4000)
		expect(wavBytes(1000, 2)).toBe(4044)
	})

	it('writes 16-bit samples, clipped, mono or the same signal on both channels', () => {
		const samples = new Float32Array([0, 0.5, -0.5, 1, -1, 2, -2])
		const mono = encodeWav(samples, 8000, 1)
		const monoView = new DataView(mono.buffer)
		expect(mono.length).toBe(44 + 14)
		expect(Array.from({ length: 7 }, (_, i) => monoView.getInt16(44 + 2 * i, true))).toEqual([0, 16384, -16383, 32767, -32767, 32767, -32767])
		const stereo = encodeWav(samples, 8000, 2)
		const stereoView = new DataView(stereo.buffer)
		expect(stereo.length).toBe(44 + 28)
		expect(stereoView.getUint16(22, true)).toBe(2)
		// frames: left, right
		expect(Array.from({ length: 6 }, (_, i) => stereoView.getInt16(44 + 2 * i, true))).toEqual([0, 0, 16384, 16384, -16383, -16383])
	})

	it('normalizes the peak level of a rendered file', () => {
		const loud = new Float32Array([0.5, -1.78, 0.1])
		expect(normalizePeak(loud, 0.89, 4)).toBeCloseTo(0.5)
		expect(Math.max(...loud.map(Math.abs))).toBeCloseTo(0.89)
		const quiet = new Float32Array([0.01, -0.02])
		expect(normalizePeak(quiet, 0.89, 4)).toBe(4)
		expect(quiet[1]).toBeCloseTo(-0.08)
		const silent = new Float32Array(3)
		expect(normalizePeak(silent, 0.89, 4)).toBe(1)
		expect([...silent]).toEqual([0, 0, 0])
	})
})

// ---- file names -------------------------------------------------------------------------

describe('file names', () => {
	it('spell out umlauts and drop accents', () => {
		expect(asciiText('Ännchen von Tharau – Großer Gott, Église')).toBe('Aennchen von Tharau - Grosser Gott, Eglise')
		expect(fileNamePart('Kein schöner Land')).toBe('Kein-schoener-Land')
		expect(fileNamePart('Tenor I/II')).toBe('Tenor-I-II')
		expect(fileNamePart('Ave verum corpus (KV 618)')).toBe('Ave-verum-corpus-KV-618')
		expect(fileNamePart('__Bass_2__')).toBe('Bass-2')
		expect(fileNamePart('Отче наш')).toBe('')
		expect(fileNamePart('a'.repeat(50) + ' b', 40)).toBe('a'.repeat(40))
	})

	it('follow <shelf mark>_<title>_<voice>_T<from>-<to>.<ext>', () => {
		const name = { shelfmark: '2-2', title: 'Kein schöner Land', label: 'Tenor I', from: '0', to: '11', ext: 'wav' }
		expect(practiceFileName(name)).toBe('2-2_Kein-schoener-Land_Tenor-I_T0-11.wav')
		expect(practiceFileName({ ...name, label: 'Tutti', ext: 'mid' })).toBe('2-2_Kein-schoener-Land_Tutti_T0-11.mid')
		expect(practiceFileName({ ...name, title: 'Der Mond ist aufgegangen, Strophen 1–3: „Abendlied“ / Claudius', label: 'Alle Stimmen', ext: 'zip' }))
			.toBe('2-2_Der-Mond-ist-aufgegangen-Strophen-1-3-Ab_Alle-Stimmen_T0-11.zip')
		expect(practiceFileName({ ...name, title: '', from: 'X1', to: '12a' })).toBe('2-2_Tenor-I_TX1-12a.wav')
		expect(practiceFileName({ ...name, title: '../../etc/passwd' })).toMatch(/^[A-Za-z0-9._-]+$/)
	})
})

// ---- modes and loudness -----------------------------------------------------------------

function voice(key: string, name: string): Voice {
	const members = key.split('+').map((member) => {
		const [staff, layer] = member.split('.').map(Number)
		return { staff, layer }
	})
	return { key, name, part: name, number: 1, members, notes: 1 }
}

const SOPRANO = voice('1.1', 'Sopran')
const ALTO = voice('1.2', 'Alt')
const BASS = voice('2.1+3.1', 'Bass')

describe('modes', () => {
	it('give the voices the loudness of the playback', () => {
		const tutti = partGains({ voice: null, mode: 'all', label: 'Tutti' })
		expect([tutti('1.1'), tutti('1.2'), tutti('2.1')]).toEqual([0.55, 0.55, 0.55])
		const louder = partGains({ voice: BASS, mode: 'focus', label: 'Bass' })
		expect([louder('2.1'), louder('3.1'), louder('1.1')]).toEqual([0.9, 0.9, 0.16])
		const only = partGains({ voice: ALTO, mode: 'solo', label: 'Alt' })
		expect([only('1.2'), only('1.1'), only('2.1')]).toEqual([0.8, 0, 0])
		// the same function as the playback in the score view
		const playback = voiceGains(BASS.members, 'focus')
		expect(['1.1', '1.2', '2.1', '3.1'].map(louder)).toEqual(['1.1', '1.2', '2.1', '3.1'].map(playback))
	})

	it('map the loudness to the MIDI channel volume', () => {
		expect(midiVolume(voiceGain('focus', true, true))).toBe(127)
		expect(midiVolume(voiceGain('all', false, false))).toBe(99)
		expect(midiVolume(voiceGain('focus', false, true))).toBe(54)
		expect(midiVolume(voiceGain('solo', true, true))).toBe(120)
		expect(midiVolume(voiceGain('solo', false, true))).toBe(0)
	})

	it('make one file, or one per voice plus a tutti', () => {
		const labelOf = (v: Voice) => v.name ?? ''
		const voices = [SOPRANO, ALTO, BASS]
		const one = practiceParts(voices, labelOf, { allVoices: false, voice: ALTO, mode: 'solo', tutti: 'Tutti' })
		expect(one.map((part) => [part.label, part.mode])).toEqual([['Alt', 'solo']])
		const tutti = practiceParts(voices, labelOf, { allVoices: false, voice: ALTO, mode: 'all', tutti: 'Tutti' })
		expect(tutti.map((part) => [part.voice, part.label, part.mode])).toEqual([[null, 'Tutti', 'all']])
		const all = practiceParts(voices, labelOf, { allVoices: true, voice: null, mode: 'focus', tutti: 'Tutti' })
		expect(all.map((part) => [part.label, part.mode])).toEqual([['Sopran', 'focus'], ['Alt', 'focus'], ['Bass', 'focus'], ['Tutti', 'all']])
		// every voice "all equal" would give identical files: the voices are louder instead
		expect(practiceParts(voices, labelOf, { allVoices: true, voice: null, mode: 'all', tutti: 'Tutti' })[0].mode).toBe('focus')
	})
})

// ---- pieces of the MIDI file -------------------------------------------------------------

describe('MIDI file details', () => {
	it('finds a time signature for every measure length', () => {
		expect(fittingSignature(3, [3, 4])).toEqual([3, 4])
		expect(fittingSignature(1.5, [3, 4])).toEqual([3, 8])
		expect(fittingSignature(1, [3, 4])).toEqual([1, 4])
		expect(fittingSignature(3, [6, 8])).toEqual([6, 8])
		expect(fittingSignature(1.5, [6, 8])).toEqual([3, 8])
		expect(fittingSignature(2.5, null)).toEqual([5, 8])
		expect(fittingSignature(1 / 3, [4, 4])).toBeNull()
	})

	it('removes overlaps of the same pitch within a track', () => {
		const spans = withoutOverlaps([
			{ pitch: 60, start: 0, end: 960 },
			{ pitch: 60, start: 480, end: 720 },
			{ pitch: 64, start: 0, end: 480 },
			{ pitch: 64, start: 0, end: 240 },
		])
		expect(spans).toEqual([
			{ pitch: 60, start: 0, end: 480 },
			{ pitch: 64, start: 0, end: 480 },
			{ pitch: 60, start: 480, end: 720 },
		])
	})

	it('estimates sizes roughly', () => {
		expect(estimateBytes('wav', 10, 1, 0, 1)).toEqual({ files: 44 + 441000 * 2, memory: 441000 * 4 + 44 + 441000 * 2 })
		const zip = estimateBytes('wav', 10, 2, 0, 5)
		expect(zip.files).toBeGreaterThan(5 * (44 + 441000 * 4))
		expect(zip.memory).toBeGreaterThan(2 * zip.files)
		expect(estimateBytes('midi', 60, 1, 100, 1).files).toBeLessThan(2000)
	})

	it('keeps the quarter-note positions of the measures', () => {
		const timing = linearTiming([
			{ tstamp: 0, qstamp: 0, measureOn: 'm0', tempo: 90, on: ['a'] },
			{ tstamp: 667, qstamp: 1, measureOn: 'm1', off: ['a'], on: ['b'] },
			{ tstamp: 2667, qstamp: 4, measureOn: 'm2', off: ['b'] },
			{ tstamp: 4667, qstamp: 7 },
		], ['m0', 'm1', 'm2'])
		expect(timing.quarters).toEqual([{ start: 0, end: 1 }, { start: 1, end: 4 }, { start: 4, end: 7 }])
		expect(timing.measures).toEqual([{ start: 0, end: 667 }, { start: 667, end: 2667 }, { start: 2667, end: 4667 }])
		expect(linearTiming([{ tstamp: 0, measureOn: 'm0' }], ['m0']).quarters).toBeUndefined()
	})
})

// ---- ZIP ---------------------------------------------------------------------------------

describe('ZIP writer', () => {
	it('computes the CRC-32 of ZIP', () => {
		expect(crc32(new TextEncoder().encode('123456789'))).toBe(0xcbf43926)
		expect(crc32(new Uint8Array(0))).toBe(0)
	})

	it('writes stored entries that the app\'s own reader reads back', async () => {
		const score = '<?xml version="1.0"?><score-partwise version="4.0"><part-list/></score-partwise>'
		const archive = zipStore([
			{ name: 'a.wav', data: new Uint8Array([1, 2, 3]) },
			{ name: 'score.xml', data: new TextEncoder().encode(score) },
		], new Date(2026, 9, 22, 12, 30, 10))
		const view = new DataView(archive.buffer)
		expect(view.getUint32(0, true)).toBe(0x04034b50)
		// stored, CRC and sizes of the first entry
		expect(view.getUint16(8, true)).toBe(0)
		expect(view.getUint32(14, true)).toBe(crc32(new Uint8Array([1, 2, 3])))
		expect([view.getUint32(18, true), view.getUint32(22, true)]).toEqual([3, 3])
		// DOS date and time: 2026-10-22 12:30:10
		expect(view.getUint16(12, true)).toBe(((2026 - 1980) << 9) | (10 << 5) | 22)
		expect(view.getUint16(10, true)).toBe((12 << 11) | (30 << 5) | 5)
		const end = archive.length - 22
		expect(view.getUint32(end, true)).toBe(0x06054b50)
		expect(view.getUint16(end + 10, true)).toBe(2)
		expect(await readScoreText(archive)).toBe(score)
	})
})

// ---- the same events as the playback -----------------------------------------------------

const SCORE = `<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0">
  <part-list>
    <score-part id="P1"><part-name>Sopran/Alt</part-name></score-part>
  </part-list>
  <part id="P1">
    <measure number="0" implicit="yes">
      <attributes><divisions>1</divisions><key><fifths>1</fifths><mode>major</mode></key><time><beats>3</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>
      <direction placement="above"><direction-type><metronome><beat-unit>quarter</beat-unit><per-minute>90</per-minute></metronome></direction-type><sound tempo="90"/></direction>
      <note><pitch><step>D</step><octave>5</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type></note>
      <backup><duration>1</duration></backup>
      <note><pitch><step>B</step><octave>4</octave></pitch><duration>1</duration><voice>2</voice><type>quarter</type></note>
    </measure>
    <measure number="1">
      <barline location="left"><repeat direction="forward"/></barline>
      <note><pitch><step>A</step><octave>4</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type></note>
      <note><pitch><step>G</step><octave>4</octave></pitch><duration>2</duration><tie type="start"/><voice>1</voice><type>half</type><notations><tied type="start"/></notations></note>
      <backup><duration>3</duration></backup>
      <note><pitch><step>E</step><octave>4</octave></pitch><duration>3</duration><voice>2</voice><type>half</type><dot/></note>
    </measure>
    <measure number="2">
      <note><pitch><step>G</step><octave>4</octave></pitch><duration>1</duration><tie type="stop"/><voice>1</voice><type>quarter</type><notations><tied type="stop"/></notations></note>
      <note><pitch><step>F</step><alter>1</alter><octave>4</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type><accidental>sharp</accidental></note>
      <note><pitch><step>G</step><octave>4</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type></note>
      <backup><duration>3</duration></backup>
      <note><pitch><step>D</step><octave>4</octave></pitch><duration>2</duration><voice>2</voice><type>half</type></note>
      <note><pitch><step>D</step><octave>4</octave></pitch><duration>1</duration><voice>2</voice><type>quarter</type></note>
      <barline location="right"><repeat direction="backward"/></barline>
    </measure>
    <measure number="3">
      <note><pitch><step>G</step><octave>4</octave></pitch><duration>3</duration><voice>1</voice><type>half</type><dot/></note>
      <backup><duration>3</duration></backup>
      <note><pitch><step>B</step><octave>3</octave></pitch><duration>3</duration><voice>2</voice><type>half</type><dot/></note>
      <barline location="right"><bar-style>light-heavy</bar-style></barline>
    </measure>
  </part>
</score-partwise>`

/**
 * Seconds of every note-on of a MIDI file per track, from its tempo map.
 *
 * @param midi - the read file
 */
function noteOnSeconds(midi: ReadMidi): { pitch: number, seconds: number }[][] {
	const tempos = midi.tracks[0].filter((event) => event.meta === 0x51)
		.map((event) => ({ tick: event.tick, micros: (event.data[0] << 16) | (event.data[1] << 8) | event.data[2] }))
	const secondsAt = (tick: number) => {
		let seconds = 0
		for (let i = 0; i < tempos.length && tempos[i].tick < tick; i++) {
			const until = Math.min(tick, tempos[i + 1]?.tick ?? Infinity)
			seconds += (until - tempos[i].tick) / midi.division * tempos[i].micros / 1e6
		}
		return seconds
	}
	return midi.tracks.slice(1).map((track) => track.filter((event) => (event.status & 0xf0) === 0x90)
		.map((event) => ({ pitch: event.data[0], seconds: secondsAt(event.tick) })))
}

describe('practice files from a score (Verovio)', () => {
	it('use the playing order of the playback, with repeats and ties', async () => {
		const toolkit = await createToolkit()
		try {
			const model = loadScore(toolkit, SCORE)
			expect(model.voices.map((v) => v.name)).toEqual(['Sopran', 'Alt'])
			expect(model.fullOrder).toEqual([0, 1, 2, 1, 2, 3])

			// The export takes the events of the playback: same function, same result
			const range = { start: 1, end: 3 }
			for (const [r, repeats] of [[null, true], [null, false], [range, true], [range, false]] as const) {
				expect(playbackTimeline(model, r, repeats)).toEqual(timelineFor(model, playingOrder(model, r, repeats)))
			}
			const timeline: Timeline = playbackTimeline(model, null, true)
			expect(timeline.measures.map((slot) => model.measures[slot.index].number)).toEqual(['0', '1', '2', '1', '2', '3'])

			const labelOf = (v: Voice) => v.name ?? ''
			const part: PracticePart = { voice: model.voices[0], mode: 'focus', label: 'Sopran' }
			const rate = 0.8
			const midi = readMidi(practiceMidi(model, timeline, part, { rate, title: '1-1 Übung', labelOf }))
			expect(midi.format).toBe(1)
			expect(midi.tracks).toHaveLength(3)

			// Conductor track: name, key, time signatures (pickup 1/4, then 3/4) and the tempo at 80 %
			const conductor = midi.tracks[0]
			expect(String.fromCharCode(...conductor[0].data)).toBe('1-1 Uebung')
			expect(conductor.filter((event) => event.meta === 0x59).map((event) => event.data)).toEqual([[1, 0]])
			expect(conductor.filter((event) => event.meta === 0x58).map((event) => [event.tick, event.data[0], 2 ** event.data[1]]))
				.toEqual([[0, 1, 4], [480, 3, 4]])
			const tempos = conductor.filter((event) => event.meta === 0x51)
			expect(tempos).toHaveLength(1)
			expect((tempos[0].data[0] << 16) | (tempos[0].data[1] << 8) | tempos[0].data[2]).toBe(Math.round(60e6 / (90 * rate)))

			// One track per voice with its name and the balance of the mode
			const voiceTracks = midi.tracks.slice(1)
			expect(voiceTracks.map((track) => String.fromCharCode(...track[0].data))).toEqual(['Sopran', 'Alt'])
			expect(voiceTracks.map((track) => track.find((event) => (event.status & 0xf0) === 0xb0)?.data)).toEqual([[7, 127], [7, 54]])

			// As many notes per voice as the playback strikes, in the same order and at the same time
			const seconds = noteOnSeconds(midi)
			model.voices.forEach((v, index) => {
				const keys = new Set(v.members.map((member) => memberKey(member.staff, member.layer)))
				const struck = timeline.events.filter((event) => event.sound && keys.has(event.voice))
				expect(seconds[index].map((note) => note.pitch)).toEqual(struck.map((event) => event.pitch))
				seconds[index].forEach((note, i) => expect(note.seconds).toBeCloseTo(struck[i].start / rate, 2))
			})
			// the tied G4 is struck once per pass: 12 events of the soprano, 10 struck
			expect(timeline.events.filter((event) => event.voice === '1.1')).toHaveLength(12)
			expect(seconds[0]).toHaveLength(10)
			expect(seconds[1]).toHaveLength(8)

			// The rendered WAV is scheduled with the playback's own function
			const notes: { pitch: number, start: number, end: number, gain: number }[] = []
			const target = { note: (pitch: number, start: number, end: number, gain: number) => notes.push({ pitch, start, end, gain }) }
			const solo = partGains({ voice: model.voices[1], mode: 'solo', label: 'Alt' })
			scheduleEvents(target, timeline.events, 0, { startAudio: 0, startScore: 0, rate }, Infinity, 0, solo)
			const altoEvents = timeline.events.filter((event) => event.sound && event.voice === '1.2')
			expect(notes.map((note) => note.pitch)).toEqual(altoEvents.map((event) => event.pitch))
			expect(notes.map((note) => note.start)).toEqual(altoEvents.map((event) => event.start / rate))
			expect(notes.map((note) => note.end)).toEqual(altoEvents.map((event) => event.soundEnd / rate))
			expect(new Set(notes.map((note) => note.gain))).toEqual(new Set([0.8]))
		} finally {
			toolkit.destroy()
		}
	}, 30000)
})
