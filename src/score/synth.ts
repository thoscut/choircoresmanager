/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A small Web Audio synthesizer for playing scores in the browser: a soft,
 * organ-like tone (a few harmonics, gentle vibrato, low-pass filter) with an
 * envelope. No sound fonts, nothing is loaded from a server.
 *
 * The same synthesizer renders practice audio files into an
 * OfflineAudioContext, so that a file sounds like the playback.
 */

interface SoundingNote {
	oscillator: OscillatorNode
	envelope: GainNode
}

const ATTACK = 0.035
const RELEASE = 0.08

/** How long a note sounds after its end (release of the envelope), in seconds. */
export const RELEASE_TAIL = RELEASE * 2

export class Synth {

	readonly context: BaseAudioContext
	private readonly bus: GainNode
	private readonly master: GainNode
	private readonly wave: PeriodicWave
	private readonly vibrato: OscillatorNode
	private readonly vibratoDepth: GainNode
	private readonly sounding = new Set<SoundingNote>()

	/**
	 * @param volume - overall volume 0 … 1
	 * @param target - context to play into; default: a new AudioContext for the speakers
	 */
	constructor(volume = 0.7, target?: BaseAudioContext) {
		const context = target ?? new AudioContext({ latencyHint: 'playback' })
		this.context = context

		// Harmonics of a mellow reed/organ tone
		const imag = new Float32Array([0, 1, 0.42, 0.2, 0.12, 0.06, 0.035, 0.02])
		this.wave = context.createPeriodicWave(new Float32Array(imag.length), imag)

		this.bus = context.createGain()
		this.bus.gain.value = 0.32
		const filter = context.createBiquadFilter()
		filter.type = 'lowpass'
		filter.frequency.value = 2600
		filter.Q.value = 0.4
		const compressor = context.createDynamicsCompressor()
		compressor.threshold.value = -16
		compressor.knee.value = 12
		compressor.ratio.value = 4
		compressor.attack.value = 0.004
		compressor.release.value = 0.2
		this.master = context.createGain()
		this.master.gain.value = volume
		this.bus.connect(filter).connect(compressor).connect(this.master).connect(context.destination)

		// Shared vibrato: 5 Hz, ±5 cent
		this.vibrato = context.createOscillator()
		this.vibrato.frequency.value = 5
		this.vibratoDepth = context.createGain()
		this.vibratoDepth.gain.value = 5
		this.vibrato.connect(this.vibratoDepth)
		this.vibrato.start()
	}

	/** Start audio output; browsers allow this only after a user action. */
	async resume(): Promise<void> {
		if (this.context instanceof AudioContext && this.context.state !== 'running') {
			await this.context.resume()
		}
	}

	/**
	 * Overall volume.
	 *
	 * @param value - 0 … 1
	 */
	setVolume(value: number): void {
		this.master.gain.setTargetAtTime(Math.max(0, Math.min(1, value)), this.context.currentTime, 0.02)
	}

	/**
	 * Schedule one note.
	 *
	 * @param pitch - MIDI pitch
	 * @param start - start in AudioContext time
	 * @param end - end in AudioContext time
	 * @param gain - loudness 0 … 1
	 */
	note(pitch: number, start: number, end: number, gain: number): void {
		const context = this.context
		const oscillator = context.createOscillator()
		oscillator.setPeriodicWave(this.wave)
		oscillator.frequency.value = 440 * 2 ** ((pitch - 69) / 12)
		this.vibratoDepth.connect(oscillator.detune)

		const envelope = context.createGain()
		const release = Math.max(start + ATTACK, end - RELEASE / 2)
		envelope.gain.setValueAtTime(0, start)
		envelope.gain.linearRampToValueAtTime(gain, start + ATTACK)
		envelope.gain.setTargetAtTime(gain * 0.72, start + ATTACK, 0.18)
		envelope.gain.setTargetAtTime(0, release, RELEASE / 3)

		oscillator.connect(envelope).connect(this.bus)
		oscillator.start(start)
		oscillator.stop(release + RELEASE_TAIL)
		const sounding: SoundingNote = { oscillator, envelope }
		this.sounding.add(sounding)
		oscillator.onended = () => {
			this.vibratoDepth.disconnect(oscillator.detune)
			envelope.disconnect()
			this.sounding.delete(sounding)
		}
	}

	/**
	 * Schedule one click of a count-in: a short, bright tick, higher on the
	 * first beat of a bar.
	 *
	 * @param time - start in AudioContext time
	 * @param accent - first beat of a bar
	 */
	click(time: number, accent: boolean): void {
		const context = this.context
		const oscillator = context.createOscillator()
		oscillator.frequency.value = accent ? 1760 : 1320
		const envelope = context.createGain()
		envelope.gain.setValueAtTime(0, time)
		envelope.gain.linearRampToValueAtTime(accent ? 0.9 : 0.6, time + 0.002)
		envelope.gain.setTargetAtTime(0, time + 0.006, 0.015)
		oscillator.connect(envelope).connect(this.bus)
		oscillator.start(time)
		oscillator.stop(time + 0.12)
		const sounding: SoundingNote = { oscillator, envelope }
		this.sounding.add(sounding)
		oscillator.onended = () => {
			envelope.disconnect()
			this.sounding.delete(sounding)
		}
	}

	/** Fade out everything that sounds or is scheduled. */
	silence(): void {
		const now = this.context.currentTime
		for (const { oscillator, envelope } of this.sounding) {
			envelope.gain.cancelScheduledValues(now)
			envelope.gain.setTargetAtTime(0, now, 0.012)
			try {
				oscillator.stop(now + 0.06)
			} catch {
				// not started yet: stop() before start() throws in some browsers
				oscillator.disconnect()
			}
		}
	}

	async close(): Promise<void> {
		this.silence()
		this.vibrato.stop()
		if (this.context instanceof AudioContext) {
			await this.context.close()
		}
	}

}
