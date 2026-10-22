/**
 * Writing WAV files: PCM, 16 bit, little endian, mono or stereo, with the
 * canonical 44-byte header (RIFF, "fmt " and "data" chunks).
 */

/** Bytes of the header before the samples */
export const WAV_HEADER_BYTES = 44

/**
 * Size of a WAV file in bytes.
 *
 * @param frames - samples per channel
 * @param channels - 1 (mono) or 2 (stereo)
 */
export function wavBytes(frames: number, channels: number): number {
	return WAV_HEADER_BYTES + frames * channels * 2
}

/**
 * The 44-byte header of a 16-bit PCM WAV file.
 *
 * @param frames - samples per channel
 * @param sampleRate - samples per second, e.g. 44100
 * @param channels - 1 (mono) or 2 (stereo)
 */
export function wavHeader(frames: number, sampleRate: number, channels: number): Uint8Array {
	const dataBytes = frames * channels * 2
	if (dataBytes + WAV_HEADER_BYTES - 8 > 0xffffffff) {
		throw new RangeError('The audio is too long for a WAV file.')
	}
	const header = new Uint8Array(WAV_HEADER_BYTES)
	const view = new DataView(header.buffer)
	const ascii = (offset: number, text: string) => {
		for (let i = 0; i < text.length; i++) {
			header[offset + i] = text.charCodeAt(i)
		}
	}
	ascii(0, 'RIFF')
	view.setUint32(4, dataBytes + WAV_HEADER_BYTES - 8, true)
	ascii(8, 'WAVE')
	ascii(12, 'fmt ')
	view.setUint32(16, 16, true)
	// PCM
	view.setUint16(20, 1, true)
	view.setUint16(22, channels, true)
	view.setUint32(24, sampleRate, true)
	// bytes per second and per frame
	view.setUint32(28, sampleRate * channels * 2, true)
	view.setUint16(32, channels * 2, true)
	view.setUint16(34, 16, true)
	ascii(36, 'data')
	view.setUint32(40, dataBytes, true)
	return header
}

/**
 * A WAV file from mono samples; for stereo both channels get the same signal
 * (the synthesizer plays in mono).
 *
 * @param samples - samples -1 … 1; values outside are clipped
 * @param sampleRate - samples per second
 * @param channels - 1 (mono) or 2 (stereo)
 */
export function encodeWav(samples: Float32Array, sampleRate: number, channels: 1 | 2): Uint8Array {
	const bytes = new Uint8Array(wavBytes(samples.length, channels))
	bytes.set(wavHeader(samples.length, sampleRate, channels), 0)
	const view = new DataView(bytes.buffer)
	let offset = WAV_HEADER_BYTES
	for (let i = 0; i < samples.length; i++) {
		const value = Math.round(Math.max(-1, Math.min(1, samples[i])) * 32767)
		for (let channel = 0; channel < channels; channel++) {
			view.setInt16(offset, value, true)
			offset += 2
		}
	}
	return bytes
}
