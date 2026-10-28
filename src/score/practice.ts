/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Practice audio files from the score view: which files an export makes,
 * their names, the loudness of the voices, MIDI files from the playback
 * events and rough size estimates. Rendering WAV files needs Web Audio and
 * is in render.ts.
 *
 * Everything starts from the events of the playback (model.playbackTimeline)
 * and the loudness of the playback (player.voiceGains), so that a file
 * sounds like playing the same choice in the score view.
 */
import type { CountIn } from './countIn.ts'
import type { MidiEvent, MidiTrack } from './midi.ts'
import type { ScoreModel } from './model.ts'
import type { PlayMode } from './player.ts'
import type { Timeline } from './timeline.ts'
import type { Voice } from './voices.ts'

import {
	controlChange,
	keySignatureEvent,
	noteOff,
	noteOn,
	PPQ,
	programChange,
	tempoEvent,
	textEvent,
	timeSignatureEvent,
	trackName,
	writeMidi,
} from './midi.ts'
import { voiceGain, voiceGains } from './player.ts'
import { transposedFifths } from './practising.ts'
import { RELEASE_TAIL } from './synth.ts'
import { memberKey } from './voices.ts'
import { WAV_HEADER_BYTES } from './wav.ts'
import { zipBytes } from './zip.ts'

export type PracticeFormat = 'wav' | 'midi'

/** One file of an export: a focus voice in a mode, or all voices equal. */
export interface PracticePart {
	/** focus voice; null: all voices equal (tutti) */
	voice: Voice | null
	mode: PlayMode
	/** name of the voice, or "Tutti", for the file name */
	label: string
}

/** Sample rate of the WAV files */
export const SAMPLE_RATE = 44100

/** From this length (seconds, at the chosen tempo) the dialog warns before an export. */
export const LONG_EXPORT_SECONDS = 600

/** General MIDI program of every voice: reed organ, close to the synthesizer's tone */
export const MIDI_PROGRAM = 20

/** Velocity of every note; the balance of the voices is in the channel volume */
export const MIDI_VELOCITY = 80

/** General MIDI drum channel (10, counted from 0) and the sounds of a count-in: high and low wood block */
const DRUM_CHANNEL = 9
const CLICK_ACCENT = 76
const CLICK_BEAT = 77

/** Loudest gain of the playback (focus voice louder), channel volume 127 */
const FULL_GAIN = voiceGain('focus', true, true)

/** Most characters of the title and the voice in a file name */
const TITLE_CHARS = 40
const LABEL_CHARS = 30

/**
 * The files of an export.
 *
 * @param voices - voices of the score
 * @param labelOf - display name of a voice
 * @param choice - one file (with a focus voice or tutti) or one per voice plus a tutti
 * @param choice.allVoices - one file per voice plus a tutti
 * @param choice.voice - focus voice of a single file; null for a tutti
 * @param choice.mode - mode of a single file, or of the voice files
 * @param choice.tutti - label of the file with all voices equal
 */
export function practiceParts(voices: Voice[], labelOf: (voice: Voice) => string,
	choice: { allVoices: boolean, voice: Voice | null, mode: PlayMode, tutti: string }): PracticePart[] {
	const tutti: PracticePart = { voice: null, mode: 'all', label: choice.tutti }
	if (choice.allVoices) {
		const mode = choice.mode === 'all' ? 'focus' : choice.mode
		return [...voices.map((voice) => ({ voice, mode, label: labelOf(voice) })), tutti]
	}
	if (choice.voice === null || choice.mode === 'all') {
		return [tutti]
	}
	return [{ voice: choice.voice, mode: choice.mode, label: labelOf(choice.voice) }]
}

/**
 * Gain of every voice member key in a file, as during playback.
 *
 * @param part - the file
 */
export function partGains(part: PracticePart): (voice: string) => number {
	return voiceGains(part.voice?.members ?? [], part.mode)
}

/**
 * Channel volume (controller 7) for a playback gain. The General MIDI
 * volume curve is 40·log10(v/127) dB, so the square root keeps the
 * proportions of the playback: louder voice 127, all equal 99, the others
 * next to a louder voice 54, silent 0.
 *
 * @param gain - playback gain 0 … 1
 */
export function midiVolume(gain: number): number {
	if (gain <= 0) {
		return 0
	}
	return Math.max(1, Math.min(127, Math.round(127 * Math.sqrt(gain / FULL_GAIN))))
}

const TRANSLITERATION: Record<string, string> = {
	ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss', ẞ: 'SS', æ: 'ae', Æ: 'Ae', ø: 'o', Ø: 'O', œ: 'oe', Œ: 'Oe', ł: 'l', Ł: 'L', đ: 'd', Đ: 'D',
}

/**
 * Text in printable ASCII: German umlauts and ß spelled out, accents
 * dropped, other characters replaced by "-".
 *
 * @param text - any text
 */
export function asciiText(text: string): string {
	return [...text.normalize('NFC')]
		.map((char) => TRANSLITERATION[char] ?? char)
		.join('')
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[^\x20-\x7e]/g, '-')
}

/**
 * One part of a file name: ASCII letters, digits, "-" and "."; everything
 * else (spaces, "_", "/", …) becomes "-".
 *
 * @param text - title, voice, shelf mark or measure number
 * @param max - most characters
 */
export function fileNamePart(text: string, max = 60): string {
	return asciiText(text)
		.replace(/[^A-Za-z0-9.-]+/g, '-')
		.replace(/-{2,}/g, '-')
		.replace(/^[-.]+|[-.]+$/g, '')
		.slice(0, max)
		.replace(/[-.]+$/, '')
}

/**
 * File name of a practice file: `<shelf mark>_<title>_<voice>_T<from>-<to>.<ext>`,
 * ASCII only. Empty parts are left out.
 *
 * @param name - parts of the name
 * @param name.shelfmark - shelf mark, e.g. "2-2"
 * @param name.title - title of the work
 * @param name.label - voice or "Tutti"
 * @param name.from - printed number of the first measure
 * @param name.to - printed number of the last measure
 * @param name.ext - extension without the dot
 */
export function practiceFileName(name: { shelfmark: string, title: string, label: string, from: string, to: string, ext: string }): string {
	const measures = `T${fileNamePart(name.from, 16) || '0'}-${fileNamePart(name.to, 16) || '0'}`
	const parts = [fileNamePart(name.shelfmark, 16), fileNamePart(name.title, TITLE_CHARS), fileNamePart(name.label, LABEL_CHARS), measures]
	return `${parts.filter((part) => part !== '').join('_')}.${name.ext}`
}

/**
 * Length of a rendered file in seconds: the count-in and the timeline at
 * the chosen tempo, and the release of the last notes.
 *
 * @param timeline - playback events
 * @param rate - tempo factor
 * @param lead - seconds of count-in at 100 % tempo
 */
export function renderedSeconds(timeline: Timeline, rate: number, lead = 0): number {
	return (lead + timeline.duration) / rate + RELEASE_TAIL + 0.1
}

/**
 * Rough size of an export: the files and the memory the browser needs
 * while making them (render buffer, the files, the archive).
 *
 * @param format - WAV or MIDI
 * @param seconds - length of one file (renderedSeconds)
 * @param channels - 1 or 2 (WAV)
 * @param notes - sounding notes of the timeline (MIDI)
 * @param files - number of files
 */
export function estimateBytes(format: PracticeFormat, seconds: number, channels: number, notes: number, files: number): { files: number, memory: number } {
	const frames = Math.ceil(seconds * SAMPLE_RATE)
	const one = format === 'wav' ? WAV_HEADER_BYTES + frames * channels * 2 : 200 + notes * 8
	const total = files > 1 ? zipBytes(Array.from({ length: files }, () => ({ nameBytes: 60, dataBytes: one }))) : one
	const render = format === 'wav' ? frames * 4 : 0
	return { files: total, memory: render + (files > 1 ? 2 * total : total) }
}

// ---- MIDI -------------------------------------------------------------------------

interface Slot {
	/** start in seconds at 100 % tempo */
	start: number
	/** first tick */
	tick: number
	/** ticks per second at 100 % tempo */
	ticksPerSecond: number
	/** quarter notes per minute at 100 % tempo */
	tempo: number
	/** length in quarter notes */
	quarters: number
}

/** Largest gap between the MIDI time and the playback time of a measure end (seconds) */
const MAX_DRIFT = 0.002

/**
 * Measure slots of the timeline on the tick axis; bars and beats come from
 * the quarter-note positions of the timemap. Each measure gets the tempo
 * that makes it last as long as in the playback. The timemap rounds to
 * milliseconds, so a tempo that is within 1 % of the previous one and keeps
 * the measure end within 2 ms is kept: a piece in one tempo gets one tempo.
 *
 * @param model - the score
 * @param timeline - playback events
 */
function slotsOf(model: ScoreModel, timeline: Timeline): Slot[] {
	const slots: Slot[] = []
	let tick = 0
	let tempo = model.timing.tempo ?? model.quarterTempo ?? 120
	let midiTime = timeline.measures[0]?.start ?? 0
	for (const measure of timeline.measures) {
		const seconds = measure.end - measure.start
		const span = model.timing.quarters?.[measure.index]
		const quarters = Math.max(0, span ? span.end - span.start : seconds * tempo / 60)
		if (seconds > 0 && quarters > 0) {
			const exact = quarters / seconds * 60
			const kept = Math.abs(tempo / exact - 1) < 0.01 && Math.abs(midiTime + quarters * 60 / tempo - measure.end) <= MAX_DRIFT
			if (!kept) {
				tempo = exact
			}
			midiTime += quarters * 60 / tempo
		}
		slots.push({ start: measure.start, tick, ticksPerSecond: tempo / 60 * PPQ, tempo, quarters })
		tick += Math.round(quarters * PPQ)
	}
	return slots
}

/**
 * Tick of a time in seconds of the timeline.
 *
 * @param slots - measure slots
 * @param time - seconds at 100 % tempo
 */
function tickAt(slots: Slot[], time: number): number {
	let low = 0
	let high = slots.length - 1
	if (high < 0) {
		return 0
	}
	while (low < high) {
		const middle = (low + high + 1) >> 1
		if (slots[middle].start <= time + 1e-9) {
			low = middle
		} else {
			high = middle - 1
		}
	}
	const slot = slots[low]
	return Math.max(0, slot.tick + Math.round((time - slot.start) * slot.ticksPerSecond))
}

/**
 * Time signature that fits a measure of this length: the written one if it
 * fits, otherwise the same beat unit (or a smaller one) with as many beats
 * as the measure holds, e.g. 3/8 for a pickup of three eighths in 3/4.
 *
 * @param quarters - length in quarter notes
 * @param written - time signature of the score, e.g. [3, 4]
 */
export function fittingSignature(quarters: number, written: [number, number] | null): [number, number] | null {
	if (written && Math.abs(quarters - written[0] * 4 / written[1]) < 1e-6) {
		return written
	}
	for (let denominator = written?.[1] ?? 4; denominator <= 64; denominator *= 2) {
		const numerator = quarters * denominator / 4
		if (Math.abs(numerator - Math.round(numerator)) < 1e-6 && Math.round(numerator) >= 1 && Math.round(numerator) <= 255) {
			return [Math.round(numerator), denominator]
		}
	}
	return null
}

/**
 * The written time signature, e.g. "3/4" → [3, 4].
 *
 * @param time - from the score's signatures
 */
function writtenSignature(time: string): [number, number] | null {
	const match = time.match(/^(\d+)\/(\d+)$/)
	if (!match) {
		return null
	}
	const numerator = Number(match[1])
	const denominator = Number(match[2])
	return numerator >= 1 && numerator <= 255 && [1, 2, 4, 8, 16, 32, 64].includes(denominator) ? [numerator, denominator] : null
}

/**
 * MIDI channel of the n-th voice: 0 … 15 without 9 (drums in General MIDI).
 *
 * @param index - voice number from 0
 */
function channelOf(index: number): number {
	return index < 9 ? index : Math.min(15, index + 1)
}

interface NoteSpan {
	pitch: number
	start: number
	end: number
}

/**
 * A MIDI file of a practice part: a first track with tempo, time and key
 * signature, then one track per voice with its name. The balance of the
 * playback mode is in the channel volume of each track (the focus voice
 * louder, or the others at 0), so it can still be changed in a MIDI player.
 *
 * @param model - the score
 * @param timeline - playback events (model.playbackTimeline)
 * @param part - focus voice and mode
 * @param options - tempo factor, title and names
 * @param options.rate - tempo factor (1 = as in the score)
 * @param options.title - name of the first track
 * @param options.labelOf - display name of a voice
 * @param options.countIn - clicks before the music, on a drum track of their own
 * @param options.text - a text in the first track, e.g. the version line (milestone 0.8)
 * @param options.transpose - semitones the timeline is transposed by, for the key signature (milestone 0.13)
 * @param options.gainOf - gain per voice member key instead of the part's mode, e.g. with the mixer (milestone 0.13)
 */
export function practiceMidi(model: ScoreModel, timeline: Timeline, part: PracticePart,
	options: { rate: number, title: string, labelOf: (voice: Voice) => string, countIn?: CountIn | null, text?: string, transpose?: number, gainOf?: (voice: string) => number }): Uint8Array {
	const countIn = options.countIn ?? null
	const lead = countIn ? Math.round(countIn.lead / countIn.quarterSeconds * PPQ) : 0
	const slots = slotsOf(model, timeline).map((slot) => ({ ...slot, tick: slot.tick + lead }))
	const endTick = slots.length > 0 ? slots[slots.length - 1].tick + Math.round(slots[slots.length - 1].quarters * PPQ) : lead
	const written = writtenSignature(model.signatures.time)

	// Conductor track: tempo, time and key signature
	const conductor: MidiEvent[] = [{ tick: 0, bytes: trackName(asciiText(options.title)) }]
	if (options.text) {
		conductor.push({ tick: 0, bytes: textEvent(asciiText(options.text)) })
	}
	if (model.signatures.fifths !== null && Math.abs(model.signatures.fifths) <= 7) {
		const fifths = options.transpose ? transposedFifths(model.signatures.fifths, options.transpose) : model.signatures.fifths
		conductor.push({ tick: 0, bytes: keySignatureEvent(fifths, model.signatures.mode === 'minor') })
	}
	let tempo = -1
	let signature = ''
	if (countIn && lead > 0) {
		// The count-in in the bars of the music and in the tempo where it starts
		const bars = countIn.lead / countIn.quarterSeconds / countIn.barQuarters
		const fitting = fittingSignature(Math.abs(bars - Math.round(bars)) < 1e-3 ? countIn.barQuarters : lead / PPQ, written)
		if (fitting) {
			signature = fitting.join('/')
			conductor.push({ tick: 0, bytes: timeSignatureEvent(fitting[0], fitting[1]) })
		}
		const quarterTempo = 60 / countIn.quarterSeconds * options.rate
		tempo = Math.round(60_000_000 / quarterTempo)
		conductor.push({ tick: 0, bytes: tempoEvent(quarterTempo) })
	}
	for (const slot of slots) {
		const fitting = fittingSignature(slot.quarters, written)
		if (fitting && fitting.join('/') !== signature) {
			signature = fitting.join('/')
			conductor.push({ tick: slot.tick, bytes: timeSignatureEvent(fitting[0], fitting[1]) })
		}
		const micros = Math.round(60_000_000 / (slot.tempo * options.rate))
		if (micros !== tempo) {
			tempo = micros
			conductor.push({ tick: slot.tick, bytes: tempoEvent(slot.tempo * options.rate) })
		}
	}
	if (slots.length === 0) {
		conductor.push({ tick: 0, bytes: tempoEvent((model.quarterTempo ?? 120) * options.rate) })
	}

	// One track per voice; notes of keys without a voice get a track of their own
	const gainOf = options.gainOf ?? partGains(part)
	const trackOf = new Map<string, number>()
	const names: string[] = []
	const keys: string[] = []
	model.voices.forEach((voice, index) => {
		names.push(options.labelOf(voice))
		keys.push(voice.members.map((member) => memberKey(member.staff, member.layer))[0] ?? '')
		for (const member of voice.members) {
			trackOf.set(memberKey(member.staff, member.layer), index)
		}
	})
	const notes: NoteSpan[][] = names.map(() => [])
	for (const event of timeline.events) {
		if (!event.sound) {
			continue
		}
		let index = trackOf.get(event.voice)
		if (index === undefined) {
			index = names.length
			trackOf.set(event.voice, index)
			names.push(event.voice)
			keys.push(event.voice)
			notes.push([])
		}
		const start = tickAt(slots, event.start)
		notes[index].push({ pitch: event.pitch, start, end: Math.max(start + 1, tickAt(slots, event.soundEnd)) })
	}

	const tracks: MidiTrack[] = [{ events: conductor, end: endTick }]
	if (countIn && lead > 0) {
		const clicks: MidiEvent[] = [{ tick: 0, bytes: trackName('Count-in') }]
		for (const click of countIn.clicks) {
			const tick = lead + Math.round(click.time / countIn.quarterSeconds * PPQ)
			const key = click.accent ? CLICK_ACCENT : CLICK_BEAT
			clicks.push({ tick, bytes: noteOn(DRUM_CHANNEL, key, click.accent ? 110 : MIDI_VELOCITY) })
			clicks.push({ tick: tick + PPQ / 8, bytes: noteOff(DRUM_CHANNEL, key) })
		}
		tracks.push({ events: clicks, end: endTick })
	}
	notes.forEach((spans, index) => {
		const channel = channelOf(index)
		const head: MidiEvent[] = [
			{ tick: 0, bytes: trackName(asciiText(names[index])) },
			{ tick: 0, bytes: programChange(channel, MIDI_PROGRAM) },
			{ tick: 0, bytes: controlChange(channel, 7, midiVolume(gainOf(keys[index]))) },
		]
		const noteEvents: (MidiEvent & { order: number })[] = []
		for (const span of withoutOverlaps(spans)) {
			noteEvents.push({ tick: span.start, order: 1, bytes: noteOn(channel, span.pitch, MIDI_VELOCITY) })
			noteEvents.push({ tick: span.end, order: 0, bytes: noteOff(channel, span.pitch) })
		}
		// At the same tick a note ends before the next one starts
		noteEvents.sort((a, b) => a.tick - b.tick || a.order - b.order)
		tracks.push({ events: [...head, ...noteEvents.map(({ tick, bytes }) => ({ tick, bytes }))], end: endTick })
	})
	return writeMidi(tracks)
}

/**
 * Notes of one track without overlaps of the same pitch, which MIDI cannot
 * express on one channel: a note ends where the next one of the same pitch
 * starts; a second note at the same time is dropped (it sounds already).
 *
 * @param spans - notes of a track
 */
export function withoutOverlaps(spans: NoteSpan[]): NoteSpan[] {
	const sorted = spans.map((span) => ({ ...span })).sort((a, b) => a.start - b.start || a.pitch - b.pitch)
	const sounding = new Map<number, NoteSpan>()
	const result: NoteSpan[] = []
	for (const span of sorted) {
		const previous = sounding.get(span.pitch)
		if (previous && previous.end > span.start) {
			if (previous.start === span.start) {
				previous.end = Math.max(previous.end, span.end)
				continue
			}
			previous.end = span.start
		}
		sounding.set(span.pitch, span)
		result.push(span)
	}
	return result
}
