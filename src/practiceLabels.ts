/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Translated words of the tools for practising (milestone 0.13): the
 * transposition and the starting tones. The functions are in
 * score/practising.ts.
 */
import type { StartingTone } from './score/practising.ts'

import { getLanguage } from '@nextcloud/l10n'
import { n, t } from './l10n.ts'
import { midiPitchName } from './score/practising.ts'

/**
 * The transposition for people, e.g. "2 semitones higher".
 *
 * @param semitones - semitones up (negative: down)
 */
export function transposeText(semitones: number): string {
	if (semitones === 0) {
		return t('cantoria', 'as written')
	}
	return semitones > 0
		? n('cantoria', '%n semitone higher', '%n semitones higher', semitones)
		: n('cantoria', '%n semitone lower', '%n semitones lower', -semitones)
}

/**
 * The starting tones for the screen reader and the hint below the button,
 * e.g. "Starting tones: Bass D3, Tenor A3, Alto F4, Soprano D5".
 *
 * @param tones - lowest first
 * @param labelOf - display name of a voice
 * @param fifths - key signature of the sound (after transposing)
 */
export function startingTonesText(tones: StartingTone[], labelOf: (voice: StartingTone['voice']) => string, fifths: number | null): string {
	if (tones.length === 0) {
		return t('cantoria', 'No voice sings from here on.')
	}
	const german = getLanguage().toLowerCase().startsWith('de')
	const list = tones.map((tone) => `${labelOf(tone.voice)} ${midiPitchName(tone.pitch, fifths, german)}`).join(', ')
	return t('cantoria', 'Starting tones: {tones}', { tones: list })
}
