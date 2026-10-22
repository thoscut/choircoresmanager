/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Revisions of a version (milestone 0.8, module "version_states"): the
 * version line printed on outputs ("2-35 · Arrangement · Revision 3 · valid
 * since 1 Oct 2026"), the text of a difference between two revisions, the
 * short note for choir members and the checksum of a file. Pure functions;
 * the words come translated from the caller, so this module stays free of
 * Vue and l10n and can be tested on its own.
 */
import type { ScoreChange } from './score/compare.ts'

import { SEPARATOR } from './references.ts'

/** What the version line names; every part but the shelf mark is optional. */
export interface VersionLineParts {
	shelfmark: string
	/** kind of version and arranger, e.g. "Arrangement, Satz: A. Beispiel" */
	version?: string | null
	/** number of the revision */
	revision?: number | null
	/** day since when the revision is the current one (YYYY-MM-DD, already formatted by the caller) */
	validSince?: string | null
}

/** Translated words of the version line. */
export interface VersionLineWords {
	/** "Revision {number}" */
	revision: (number: number) => string
	/** "valid since {date}" */
	validSince: (date: string) => string
}

/**
 * The version line: shelf mark · version · revision · valid since.
 *
 * @param parts - what to name
 * @param words - translated words
 */
export function versionLine(parts: VersionLineParts, words: VersionLineWords): string {
	const text = (value: string | null | undefined) => (value ?? '').replace(/\s+/g, ' ').trim()
	return [
		text(parts.shelfmark),
		text(parts.version),
		parts.revision !== null && parts.revision !== undefined ? words.revision(parts.revision) : '',
		text(parts.validSince) !== '' ? words.validSince(text(parts.validSince)) : '',
	].filter((part) => part !== '').join(SEPARATOR)
}

/** Translated words of a difference. */
export interface ChangeWords {
	/** "m. {measure}" */
	measure: (measure: string) => string
	/** "verse {verse}" */
	verse: (verse: string) => string
	/** "–", for nothing before or after */
	nothing: string
	/** 'syllable "Lie-" → "Lieb-"' */
	syllable: (before: string, after: string) => string
	/** "note value" */
	noteValue: string
	/** "accidental" */
	accidental: string
	/** "measure added" */
	measureAdded: string
	/** "measure removed" */
	measureRemoved: string
	/** "part added: {name}" */
	partAdded: (name: string) => string
	/** "part removed: {name}" */
	partRemoved: (name: string) => string
}

/**
 * One difference as a line, e.g. "m. 12, Tenor II: D4 → E4",
 * "m. 14, Bass I, verse 2: syllable "Lie-" → "Lieb-"" or "m. 20: measure added".
 *
 * @param change - the difference
 * @param words - translated words
 */
export function changeText(change: ScoreChange, words: ChangeWords): string {
	if (change.kind === 'part_added') {
		return words.partAdded(change.new)
	}
	if (change.kind === 'part_removed') {
		return words.partRemoved(change.old)
	}
	const place = [
		change.measure !== null ? words.measure(change.measure) : '',
		change.voice ?? '',
		change.verse !== null ? words.verse(change.verse) : '',
	].filter((part) => part !== '').join(', ')
	const side = (value: string) => (value === '' ? words.nothing : value)
	let what: string
	switch (change.kind) {
	case 'measure_added':
		what = words.measureAdded
		break
	case 'measure_removed':
		what = words.measureRemoved
		break
	case 'lyric':
		what = words.syllable(change.old, change.new)
		break
	case 'rhythm':
		what = `${words.noteValue} ${side(change.old)} → ${side(change.new)}`
		break
	case 'accidental':
		what = `${words.accidental} ${side(change.old)} → ${side(change.new)}`
		break
	default:
		what = `${side(change.old)} → ${side(change.new)}`
	}
	return place === '' ? what : `${place}: ${what}`
}

/**
 * The short note for choir members: a heading, then one line per difference
 * with a dash, and a last line when the list was cut.
 *
 * @param heading - e.g. "2-35 Title: changes from revision 2 to revision 3"
 * @param changes - the differences
 * @param words - translated words
 * @param more - last line when the list is incomplete, or null
 */
export function memberNote(heading: string, changes: readonly ScoreChange[], words: ChangeWords, more: string | null = null): string {
	const lines = [heading, ...changes.map((change) => `– ${changeText(change, words)}`)]
	if (more !== null && more !== '') {
		lines.push(more)
	}
	return lines.join('\n')
}

/** German note names of the steps with sharps and flats (H for B, B for B flat). */
const GERMAN: Record<string, Record<string, string>> = {
	C: { '-2': 'Ceses', '-1': 'Ces', 0: 'C', 1: 'Cis', 2: 'Cisis' },
	D: { '-2': 'Deses', '-1': 'Des', 0: 'D', 1: 'Dis', 2: 'Disis' },
	E: { '-2': 'Eses', '-1': 'Es', 0: 'E', 1: 'Eis', 2: 'Eisis' },
	F: { '-2': 'Feses', '-1': 'Fes', 0: 'F', 1: 'Fis', 2: 'Fisis' },
	G: { '-2': 'Geses', '-1': 'Ges', 0: 'G', 1: 'Gis', 2: 'Gisis' },
	A: { '-2': 'Asas', '-1': 'As', 0: 'A', 1: 'Ais', 2: 'Aisis' },
	B: { '-2': 'Heses', '-1': 'B', 0: 'H', 1: 'His', 2: 'Hisis' },
}

const SIGNS: Record<string, string> = { '-2': '𝄫', '-1': '♭', 0: '', 1: '♯', 2: '𝄪' }

/**
 * A pitch for people: "F♯4", "B♭3" or, in German, "Fis4", "B3" (B flat) and
 * "H3" (B), so that B never means two different notes.
 *
 * @param step - step A–G
 * @param alter - alteration in semitones
 * @param octave - octave (scientific, middle C = 4)
 * @param german - German note names
 */
export function pitchName(step: string, alter: number, octave: number, german = false): string {
	const key = String(alter)
	const name = german ? GERMAN[step]?.[key] : undefined
	if (name !== undefined) {
		return `${name}${octave}`
	}
	const sign = SIGNS[key] ?? `(${alter > 0 ? '+' : ''}${alter})`
	return `${step}${sign}${octave}`
}

/**
 * SHA-256 of file content as hex, or null when the browser offers no
 * cryptography here (outside a secure context).
 *
 * @param data - file content
 */
export async function sha256Hex(data: ArrayBuffer): Promise<string | null> {
	const subtle = globalThis.crypto?.subtle
	if (!subtle) {
		return null
	}
	try {
		const digest = await subtle.digest('SHA-256', data)
		return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
	} catch {
		return null
	}
}
