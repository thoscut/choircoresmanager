/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { problemOf, recordingExtension, recordingProblem, recordingType } from '../score/recording.ts'

describe('recording oneself', () => {
	it('needs a secure page, the microphone access and MediaRecorder', () => {
		const MediaRecorder = function() {}
		expect(recordingProblem({ isSecureContext: true, navigator: { mediaDevices: {} }, MediaRecorder })).toBeNull()
		expect(recordingProblem({ isSecureContext: false, navigator: { mediaDevices: {} }, MediaRecorder })).toBe('insecure')
		expect(recordingProblem({ isSecureContext: true, navigator: {}, MediaRecorder })).toBe('unsupported')
		expect(recordingProblem({ isSecureContext: true, navigator: { mediaDevices: {} } })).toBe('unsupported')
	})

	it('names the problem behind an error', () => {
		expect(problemOf(new DOMException('no', 'NotAllowedError'))).toBe('denied')
		expect(problemOf({ name: 'NotFoundError' })).toBe('no-microphone')
		expect(problemOf(new Error('x'))).toBe('failed')
		expect(problemOf(null)).toBe('failed')
	})

	it('records in the first format the browser supports', () => {
		expect(recordingType(() => true)).toBe('audio/webm;codecs=opus')
		expect(recordingType((type) => type.startsWith('audio/mp4'))).toBe('audio/mp4')
		expect(recordingType(() => false)).toBe('')
		expect(recordingType(() => {
			throw new Error('old browser')
		})).toBe('')
	})

	it('gives the file the extension of the format', () => {
		expect(recordingExtension('audio/webm;codecs=opus')).toBe('webm')
		expect(recordingExtension('audio/ogg;codecs=opus')).toBe('ogg')
		expect(recordingExtension('audio/mp4')).toBe('m4a')
		expect(recordingExtension('')).toBe('webm')
	})
})
