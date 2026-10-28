/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Recording oneself while practising (milestone 0.13): the microphone is
 * asked for only on a click, the recording stays in the browser's memory
 * and can only be listened to, played with the accompaniment or saved as a
 * file by the person. Nothing is sent anywhere.
 */

/** Formats the browsers record in, the most common first */
const TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']

/** Why a recording cannot start */
export type RecordingProblem = 'unsupported' | 'insecure' | 'denied' | 'no-microphone' | 'failed'

/**
 * Whether this browser can record here, and if not, why.
 *
 * @param scope - the window (for tests)
 * @param scope.isSecureContext - false on plain http
 * @param scope.navigator - for its media devices
 * @param scope.navigator.mediaDevices - the microphone access
 * @param scope.MediaRecorder - the recorder of the browser
 * @return null when recording works
 */
export function recordingProblem(scope: { isSecureContext?: boolean, navigator?: { mediaDevices?: unknown }, MediaRecorder?: unknown } = globalThis): RecordingProblem | null {
	if (scope.isSecureContext === false) {
		return 'insecure'
	}
	if (!scope.navigator?.mediaDevices || typeof scope.MediaRecorder !== 'function') {
		return 'unsupported'
	}
	return null
}

/**
 * The problem behind an error of getUserMedia or MediaRecorder.
 *
 * @param error - the error thrown
 */
export function problemOf(error: unknown): RecordingProblem {
	const name = error instanceof Error || (typeof error === 'object' && error !== null && 'name' in error) ? String((error as { name: unknown }).name) : ''
	if (name === 'NotAllowedError' || name === 'SecurityError') {
		return 'denied'
	}
	if (name === 'NotFoundError' || name === 'OverconstrainedError') {
		return 'no-microphone'
	}
	return 'failed'
}

/**
 * The format to record in: the first that this browser supports, or '' to
 * let the browser choose.
 *
 * @param supported - MediaRecorder.isTypeSupported
 */
export function recordingType(supported: (type: string) => boolean): string {
	return TYPES.find((type) => {
		try {
			return supported(type)
		} catch {
			return false
		}
	}) ?? ''
}

/**
 * The file extension for a recorded format.
 *
 * @param type - MIME type of the recording, e.g. "audio/webm;codecs=opus"
 */
export function recordingExtension(type: string): string {
	if (type.startsWith('audio/ogg')) {
		return 'ogg'
	}
	if (type.startsWith('audio/mp4')) {
		return 'm4a'
	}
	return 'webm'
}

/** One recording from the microphone. */
export class SelfRecorder {

	private stream: MediaStream | null = null
	private recorder: MediaRecorder | null = null
	private chunks: Blob[] = []

	get isRecording(): boolean {
		return this.recorder?.state === 'recording'
	}

	/**
	 * Ask for the microphone and start recording.
	 *
	 * @throws the error of getUserMedia or MediaRecorder (see problemOf)
	 */
	async start(): Promise<void> {
		this.cancel()
		// Singing: no noise suppression or level control that would swallow notes
		const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: false, autoGainControl: false } })
		this.stream = stream
		const type = recordingType((candidate) => MediaRecorder.isTypeSupported(candidate))
		const recorder = new MediaRecorder(stream, type === '' ? undefined : { mimeType: type })
		this.chunks = []
		recorder.ondataavailable = (event) => {
			if (event.data.size > 0) {
				this.chunks.push(event.data)
			}
		}
		this.recorder = recorder
		recorder.start()
	}

	/**
	 * Stop and hand over the recording; the microphone is released.
	 */
	stop(): Promise<Blob | null> {
		const recorder = this.recorder
		if (!recorder || recorder.state === 'inactive') {
			this.release()
			return Promise.resolve(null)
		}
		return new Promise((resolve) => {
			recorder.onstop = () => {
				const blob = new Blob(this.chunks, { type: recorder.mimeType || 'audio/webm' })
				this.chunks = []
				this.release()
				resolve(blob.size > 0 ? blob : null)
			}
			recorder.stop()
		})
	}

	/** Stop without keeping anything and release the microphone. */
	cancel(): void {
		if (this.recorder && this.recorder.state !== 'inactive') {
			this.recorder.onstop = null
			this.recorder.stop()
		}
		this.chunks = []
		this.release()
	}

	private release(): void {
		this.stream?.getTracks().forEach((track) => track.stop())
		this.stream = null
		this.recorder = null
	}

}
