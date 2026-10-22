/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Small pure helpers of the excerpt view for choir members and public links
 * (milestone 0.7): the tempo of a link, the voice of a link, and names as
 * the server accepts them. No Vue, so they can be tested on their own.
 */
import type { Voice } from '../score/voices.ts'

/** Tempo range of the excerpt view, in percent of the score's tempo */
export const TEMPO_MIN = 25
export const TEMPO_MAX = 200

/**
 * The tempo of a link as the slider of the excerpt view offers it: 25 to
 * 200 %, in steps of 5; 100 % when the link gives none.
 *
 * @param percent - tempo of the link (20 to 300 % are allowed in a link)
 */
export function excerptTempo(percent: number | null | undefined): number {
	if (percent === null || percent === undefined || !Number.isFinite(percent) || percent <= 0) {
		return 100
	}
	return Math.min(TEMPO_MAX, Math.max(TEMPO_MIN, Math.round(percent / 5) * 5))
}

/**
 * The voice a link names, found by its display name or by its name from
 * the part list (case does not matter), as the score view does it.
 *
 * @param voices - voices of the score
 * @param wanted - voice of the link
 * @param labelOf - display name of a voice
 */
export function voiceOfLink(voices: readonly Voice[], wanted: string | null | undefined, labelOf: (voice: Voice) => string): Voice | null {
	const name = (wanted ?? '').trim().toLowerCase()
	if (name === '') {
		return null
	}
	return voices.find((voice) => labelOf(voice).trim().toLowerCase() === name)
		?? voices.find((voice) => (voice.name ?? '').trim().toLowerCase() === name)
		?? null
}

/**
 * A voice name as the server accepts it: no control characters, at most 100
 * characters.
 *
 * @param label - display name of the voice
 */
export function voiceForServer(label: string): string {
	return [...label].filter((char) => char >= ' ' && char !== '\x7f').join('').trim().slice(0, 100)
}
