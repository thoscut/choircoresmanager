/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Singing along with a pitch display (milestone 0.14): the microphone is
 * asked for only on a click, the pitch is found in the browser (YIN) and
 * shown as a line next to the tones of the own voice. Nothing is recorded,
 * scored or sent anywhere.
 */
import type { Timeline } from './timeline.ts'
import type { Voice } from './voices.ts'

import { memberKey } from './voices.ts'

/** Range of singing voices that is looked for, in Hz (about C♯2 to C♯6) */
export const MIN_HZ = 70
export const MAX_HZ = 1100
/** Below this level (RMS) nobody sings */
const SILENCE = 0.01
/** Threshold of the YIN algorithm: lower is stricter */
const YIN_THRESHOLD = 0.15
/** Within this many cents a tone counts as in tune */
export const IN_TUNE = 30

/**
 * The pitch of a piece of sound in Hz, or null when it is too quiet or has
 * no clear pitch (YIN: de Cheveigné and Kawahara, 2002).
 *
 * @param samples - the sound, -1 … 1
 * @param sampleRate - samples per second
 */
export function detectPitch(samples: Float32Array, sampleRate: number): number | null {
	let energy = 0
	for (const sample of samples) {
		energy += sample * sample
	}
	if (samples.length === 0 || Math.sqrt(energy / samples.length) < SILENCE) {
		return null
	}
	const maxTau = Math.min(Math.floor(sampleRate / MIN_HZ), Math.floor(samples.length / 2))
	const minTau = Math.max(2, Math.floor(sampleRate / MAX_HZ))
	const width = samples.length - maxTau
	// Cumulative mean normalized difference
	const difference = new Float32Array(maxTau + 1)
	let running = 0
	difference[0] = 1
	for (let tau = 1; tau <= maxTau; tau++) {
		let sum = 0
		for (let i = 0; i < width; i++) {
			const delta = samples[i] - samples[i + tau]
			sum += delta * delta
		}
		running += sum
		difference[tau] = running === 0 ? 1 : sum * tau / running
	}
	for (let tau = minTau; tau < maxTau; tau++) {
		if (difference[tau] < YIN_THRESHOLD) {
			while (tau + 1 < maxTau && difference[tau + 1] < difference[tau]) {
				tau++
			}
			// Parabolic interpolation between the neighbours
			const before = difference[tau - 1]
			const after = difference[tau + 1]
			const bend = before + after - 2 * difference[tau]
			const shift = bend === 0 ? 0 : (before - after) / (2 * bend)
			return sampleRate / (tau + shift)
		}
	}
	return null
}

/**
 * A frequency as a MIDI pitch with fractions (440 Hz = 69).
 *
 * @param hz - frequency
 */
export function frequencyToMidi(hz: number): number {
	return 69 + 12 * Math.log2(hz / 440)
}

/** A tone of the own voice to sing */
export interface TargetNote {
	start: number
	end: number
	pitch: number
}

/**
 * The tones of a voice in playing order, a tie as one tone.
 *
 * @param timeline - playback events (already transposed)
 * @param voice - the own voice
 */
export function targetNotes(timeline: Timeline, voice: Voice): TargetNote[] {
	const keys = new Set(voice.members.map((member) => memberKey(member.staff, member.layer)))
	const notes: TargetNote[] = []
	for (const event of timeline.events) {
		if (!keys.has(event.voice)) {
			continue
		}
		const tied = !event.sound && notes.findLast((note) => note.pitch === event.pitch && Math.abs(note.end - event.start) < 1e-6)
		if (tied) {
			tied.end = event.end
		} else {
			notes.push({ start: event.start, end: event.end, pitch: event.pitch })
		}
	}
	return notes.sort((a, b) => a.start - b.start)
}

/**
 * The tone to sing at a time, the highest of a chord; null in a rest.
 *
 * @param notes - targetNotes
 * @param time - seconds of the timeline
 */
export function targetAt(notes: TargetNote[], time: number): number | null {
	let pitch: number | null = null
	for (const note of notes) {
		if (note.start > time) {
			break
		}
		if (note.end > time && (pitch === null || note.pitch > pitch)) {
			pitch = note.pitch
		}
	}
	return pitch
}

/**
 * The sung pitch moved by whole octaves next to the target, so that a man
 * singing a soprano line along sees it at the line.
 *
 * @param sung - MIDI pitch with fractions
 * @param target - MIDI pitch
 */
export function nearOctave(sung: number, target: number): number {
	return sung - 12 * Math.round((sung - target) / 12)
}

/** How a sung tone lies next to the target */
export type Tuning = 'in-tune' | 'bit-high' | 'bit-low' | 'high' | 'low'

/**
 * How far off a sung tone is, in the octave of the target.
 *
 * @param sung - MIDI pitch with fractions
 * @param target - MIDI pitch
 */
export function tuningOf(sung: number, target: number): { cents: number, tuning: Tuning } {
	const cents = Math.round((nearOctave(sung, target) - target) * 100)
	if (Math.abs(cents) <= IN_TUNE) {
		return { cents, tuning: 'in-tune' }
	}
	if (Math.abs(cents) <= 100) {
		return { cents, tuning: cents > 0 ? 'bit-high' : 'bit-low' }
	}
	return { cents, tuning: cents > 0 ? 'high' : 'low' }
}

/**
 * The pitches the display shows: the tones of the voice with some room.
 *
 * @param notes - targetNotes
 */
export function pitchRange(notes: TargetNote[]): { low: number, high: number } {
	if (notes.length === 0) {
		return { low: 55, high: 79 }
	}
	const pitches = notes.map((note) => note.pitch)
	return { low: Math.min(...pitches) - 3, high: Math.max(...pitches) + 3 }
}

/** A sung pitch at a time of the playback */
export interface SungPoint {
	time: number
	pitch: number | null
}

/**
 * Whether this browser can listen here, and if not, why.
 *
 * @param scope - the window (for tests)
 * @param scope.isSecureContext - false on plain http
 * @param scope.navigator - for its media devices
 * @param scope.navigator.mediaDevices - the microphone access
 * @param scope.AudioContext - Web Audio
 * @return null when it works
 */
export function listeningProblem(scope: { isSecureContext?: boolean, navigator?: { mediaDevices?: unknown }, AudioContext?: unknown } = globalThis): 'insecure' | 'unsupported' | null {
	if (scope.isSecureContext === false) {
		return 'insecure'
	}
	if (!scope.navigator?.mediaDevices || typeof scope.AudioContext !== 'function') {
		return 'unsupported'
	}
	return null
}

/** Listens to the microphone and gives the sung pitch. */
export class PitchListener {

	private stream: MediaStream | null = null
	private context: AudioContext | null = null
	private analyser: AnalyserNode | null = null
	private buffer = new Float32Array(0)

	/**
	 * Ask for the microphone and start listening.
	 *
	 * @throws the error of getUserMedia (see recording.problemOf)
	 */
	async start(): Promise<void> {
		this.stop()
		// Echo cancellation keeps the accompaniment from the speakers out where it can
		const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: false, autoGainControl: false } })
		this.stream = stream
		const context = new AudioContext()
		const analyser = context.createAnalyser()
		analyser.fftSize = 2048
		context.createMediaStreamSource(stream).connect(analyser)
		this.context = context
		this.analyser = analyser
		this.buffer = new Float32Array(analyser.fftSize)
	}

	/** The sung pitch now as MIDI pitch with fractions, null when nobody sings */
	read(): number | null {
		if (!this.analyser || !this.context) {
			return null
		}
		this.analyser.getFloatTimeDomainData(this.buffer)
		const hz = detectPitch(this.buffer, this.context.sampleRate)
		return hz === null ? null : frequencyToMidi(hz)
	}

	/** Stop listening and release the microphone. */
	stop(): void {
		this.stream?.getTracks().forEach((track) => track.stop())
		this.stream = null
		this.context?.close().catch(() => undefined)
		this.context = null
		this.analyser = null
	}

}
