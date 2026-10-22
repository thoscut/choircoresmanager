/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Translated words of the revisions of a version (milestone 0.8, module
 * "version_states"): the version line, the differences of a comparison and
 * where a revision came from. The functions that use them are in states.ts
 * and score/compare.ts.
 */
import type { CompareWords } from './score/compare.ts'
import type { ChangeWords, VersionLineWords } from './states.ts'
import type { DataRecord, RevisionSummary } from './types.ts'

import { getLanguage } from '@nextcloud/l10n'
import { t } from './l10n.ts'
import { formatDate } from './format.ts'
import { pitchName, versionLine } from './states.ts'
import { choiceLabel } from './store.ts'

/** Words of the version line. */
export function versionLineWords(): VersionLineWords {
	return {
		revision: (number) => t('notenarchiv', 'Revision {number}', { number }),
		validSince: (date) => t('notenarchiv', 'valid since {date}', { date }),
	}
}

/**
 * The version as named in the version line: kind and arranger, e.g.
 * "Arrangement, Satz: A. Beispiel".
 *
 * @param version - the version record
 */
export function versionName(version: DataRecord): string {
	const kind = choiceLabel('version', 'kind', typeof version.kind === 'string' ? version.kind : null)
	const arranger = typeof version.arranger_text === 'string' ? version.arranger_text.trim() : ''
	return [kind, arranger].filter((part) => part !== '').join(', ')
}

/**
 * The version line of a copy with a revision: shelf mark · version ·
 * revision · valid since. Without a revision only shelf mark and version.
 *
 * @param shelfmark - shelf mark of the copy
 * @param version - the version record
 * @param revision - number of the revision, or null
 * @param validSince - day since when it is the current revision, or null
 */
export function revisionLine(shelfmark: string, version: DataRecord, revision: number | null, validSince: string | null): string {
	return versionLine({ shelfmark, version: versionName(version), revision, validSince: validSince ? formatDate(validSince) : null }, versionLineWords())
}

/**
 * The revision an output of the score shown names: the revision of the
 * shown file, with "valid since" when it is the current one; null when the
 * module is off or the file has no revision yet.
 *
 * @param summary - revisions of the version from the copy detail
 */
export function shownRevision(summary: RevisionSummary | null | undefined): { number: number, validSince: string | null } | null {
	if (!summary || summary.shown === null) {
		return null
	}
	const current = summary.current
	return { number: summary.shown, validSince: current !== null && current.number === summary.shown ? current.valid_since : null }
}

/** Words of a difference between two revisions. */
export function changeWords(): ChangeWords {
	return {
		measure: (measure) => t('notenarchiv', 'm. {measure}', { measure }),
		verse: (verse) => t('notenarchiv', 'verse {verse}', { verse }),
		nothing: '–',
		syllable: (before, after) => t('notenarchiv', 'syllable “{before}” → “{after}”', { before, after }),
		noteValue: t('notenarchiv', 'note value'),
		accidental: t('notenarchiv', 'accidental'),
		measureAdded: t('notenarchiv', 'measure added'),
		measureRemoved: t('notenarchiv', 'measure removed'),
		partAdded: (name) => t('notenarchiv', 'part added: {name}', { name }),
		partRemoved: (name) => t('notenarchiv', 'part removed: {name}', { name }),
	}
}

/**
 * A note value, e.g. "quarter" or "dotted half".
 *
 * @param type - MusicXML note type
 * @param dots - number of dots
 */
export function noteValueLabel(type: string, dots: number): string {
	const names: Record<string, string> = {
		breve: t('notenarchiv', 'breve'),
		whole: t('notenarchiv', 'whole'),
		half: t('notenarchiv', 'half'),
		quarter: t('notenarchiv', 'quarter'),
		eighth: t('notenarchiv', 'eighth'),
		'16th': t('notenarchiv', '16th'),
		'32nd': t('notenarchiv', '32nd'),
		'64th': t('notenarchiv', '64th'),
	}
	const name = names[type] ?? type
	if (dots === 1) {
		return t('notenarchiv', 'dotted {value}', { value: name })
	}
	return dots > 1 ? t('notenarchiv', 'double-dotted {value}', { value: name }) : name
}

/** Words and formats of the comparison, in the language of the UI. */
export function compareWords(): CompareWords {
	const german = getLanguage().toLowerCase().startsWith('de')
	return {
		pitch: (step, alter, octave) => pitchName(step, alter, octave, german),
		rest: t('notenarchiv', 'rest'),
		noteValue: noteValueLabel,
		voiceOf: (part, number) => t('notenarchiv', '{part}, voice {number}', { part, number }),
		part: (number) => t('notenarchiv', 'Part {number}', { number }),
	}
}

/**
 * Where a revision came from.
 *
 * @param source - first, correction or sync
 */
export function revisionSourceLabel(source: string): string {
	switch (source) {
	case 'first':
		return t('notenarchiv', 'First recorded revision')
	case 'correction':
		return t('notenarchiv', 'Correction in the app')
	case 'sync':
		return t('notenarchiv', 'Change found by the file sync')
	default:
		return source
	}
}
