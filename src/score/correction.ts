/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Small corrections of a MusicXML score in the browser (milestone 0.6,
 * guideline 4): the notes of a range of measures in one voice as a list,
 * and changes of exactly what the server accepts – the pitch (step,
 * alteration, octave), the visible accidental and the text of lyric
 * syllables. Note values, notes, measures and everything else stay as they
 * are; the server compares both documents again and writes the old document
 * with only these changes as a new file (see docs/api.md).
 *
 * Works on the DOM of the browser (DOMParser, XMLSerializer); partwise
 * MusicXML only, as written by MuseScore, Capella, Finale and the tool chain.
 */
import type { MeasureRange } from './range.ts'
import type { PartStaves, VoiceMember } from './voices.ts'

import { pitchName } from '../states.ts'

export const STEPS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'] as const

/** Languages that call the step B "H" (and B flat "B"). */
const H_LANGUAGES = ['de', 'pl', 'cs', 'sk', 'hu', 'da', 'nb', 'no', 'sv', 'fi']

/**
 * The name of a step for people: "H" instead of "B" in German, Polish and
 * other languages with these note names, so that B never means B flat.
 *
 * @param step - step A–G as in MusicXML
 * @param language - language of the interface, e.g. "de_DE"
 */
export function stepLabel(step: string, language: string): string {
	return step === 'B' && H_LANGUAGES.includes(language.toLowerCase().split(/[-_]/)[0]) ? 'H' : step
}

/** Alterations offered in the form: double flat to double sharp. */
export const ALTERATIONS = [-2, -1, 0, 1, 2] as const

/** Values of a visible accidental that the server accepts. */
export const ACCIDENTALS = ['sharp', 'natural', 'flat', 'double-sharp', 'sharp-sharp', 'flat-flat', 'natural-sharp', 'natural-flat'] as const

/** Elements that follow <accidental> in a <note> (MusicXML order). */
const AFTER_ACCIDENTAL = ['time-modification', 'stem', 'notehead', 'notehead-text', 'staff', 'beam', 'notations', 'lyric', 'play', 'listen']

/** Color of changed notes in the preview (Verovio draws them in it; the view adds its own style). */
export const PREVIEW_COLOR = '#c9302c'

/** A MusicXML part, staff and voice that belong to a voice of the score view. */
export interface VoiceTarget {
	/** index of the <part> in the document */
	part: number
	/** staff within the part, from 1 */
	staff: number
	/** text of <voice> */
	voice: string
}

export interface LyricField {
	/** `number` of the <lyric>, or '' */
	number: string
	/** texts of its <text> elements (usually one syllable) */
	texts: string[]
}

/** A note (or rest) of the list. */
export interface NoteRow {
	/** "part.measure.note": indices in the document, stable for the same text */
	key: string
	part: number
	measureIndex: number
	noteIndex: number
	/** printed measure number */
	measure: string
	rest: boolean
	chord: boolean
	grace: boolean
	/** has a <pitch> that can be changed */
	pitched: boolean
	step: string
	alter: number
	octave: number
	accidental: string | null
	/** note type, e.g. "quarter"; null if not given */
	type: string | null
	dots: number
	lyrics: LyricField[]
}

/** What the form may change of a note. */
export interface NoteValues {
	step: string
	alter: number
	octave: number
	accidental: string | null
	/** lyrics[i][j]: text j of the i-th <lyric> */
	lyrics: string[][]
}

export interface NoteChange {
	key: string
	measure: string
	kind: 'pitch' | 'accidental' | 'lyric'
	/** `number` of the lyric (kind lyric) */
	verse: string | null
	old: string
	new: string
}

/** The document is no partwise MusicXML the form can change. */
export class CorrectionFormatError extends Error {}

/**
 * Child elements with a name.
 *
 * @param element - parent
 * @param name - local name
 */
function children(element: Element, name: string): Element[] {
	return Array.from(element.children).filter((child) => child.localName === name)
}

/**
 * The first child element with a name.
 *
 * @param element - parent
 * @param name - local name
 */
function child(element: Element, name: string): Element | null {
	return children(element, name)[0] ?? null
}

/**
 * Trimmed text of the first child element with a name, or ''.
 *
 * @param element - parent
 * @param name - local name
 */
function childText(element: Element, name: string): string {
	return child(element, name)?.textContent?.trim() ?? ''
}

/**
 * Parse MusicXML text. Only partwise documents can be changed here.
 *
 * @param text - MusicXML text
 */
export function parseScoreDocument(text: string): Document {
	const document = new DOMParser().parseFromString(text, 'application/xml')
	if (document.getElementsByTagName('parsererror').length > 0) {
		throw new CorrectionFormatError('The file could not be read as MusicXML.')
	}
	if (document.documentElement?.localName !== 'score-partwise') {
		throw new CorrectionFormatError('Only partwise MusicXML can be corrected here.')
	}
	return document
}

/**
 * Serialize a document (without XML declaration; the server keeps the one of
 * the old file).
 *
 * @param document - the document
 */
export function serializeScore(document: Document): string {
	return new XMLSerializer().serializeToString(document)
}

/**
 * The MusicXML parts, staves and voices of a voice of the score view. A
 * member is an MEI staff (numbered across all parts) and layer; Verovio
 * gives a layer the number of the MusicXML voice.
 *
 * @param parts - parts of the score with their MEI staff numbers
 * @param members - staff/layer pairs of the voice
 */
export function voiceTargets(parts: readonly PartStaves[], members: readonly VoiceMember[]): VoiceTarget[] {
	const targets: VoiceTarget[] = []
	for (const member of members) {
		parts.forEach((part, index) => {
			const local = part.staves.indexOf(member.staff)
			if (local !== -1) {
				targets.push({ part: index, staff: local + 1, voice: String(member.layer) })
			}
		})
	}
	return targets
}

/**
 * The <note> elements of a measure of a part, or [] when it does not exist.
 *
 * @param document - the score
 * @param part - index of the part
 * @param measureIndex - index of the measure
 */
function measureNotes(document: Document, part: number, measureIndex: number): { measure: Element | null, notes: Element[] } {
	const partElement = children(document.documentElement, 'part')[part]
	const measure = partElement ? (children(partElement, 'measure')[measureIndex] ?? null) : null
	return { measure, notes: measure ? children(measure, 'note') : [] }
}

/**
 * Lyrics of a note: each <lyric> with the texts of its <text> elements.
 *
 * @param note - <note> element
 */
function readLyrics(note: Element): LyricField[] {
	return children(note, 'lyric').map((lyric) => ({
		number: lyric.getAttribute('number') ?? '',
		texts: children(lyric, 'text').map((text) => text.textContent ?? ''),
	}))
}

/**
 * Number of an alteration ("1", "-1", "0.5"); 0 when missing.
 *
 * @param value - text of <alter>
 */
function alterValue(value: string): number {
	const number = Number.parseFloat(value)
	return Number.isFinite(number) ? number : 0
}

/**
 * The notes and rests of a voice in a range of measures, in the order of
 * the measures and, within a measure, of the document.
 *
 * @param document - the score
 * @param range - measure indices
 * @param targets - parts, staves and voices of the voice
 */
export function listNotes(document: Document, range: MeasureRange, targets: readonly VoiceTarget[]): NoteRow[] {
	const rows: NoteRow[] = []
	for (let measureIndex = range.start; measureIndex <= range.end; measureIndex++) {
		const parts = [...new Set(targets.map((target) => target.part))].sort((a, b) => a - b)
		for (const part of parts) {
			const { measure, notes } = measureNotes(document, part, measureIndex)
			notes.forEach((note, noteIndex) => {
				const staff = childText(note, 'staff') || '1'
				const voice = childText(note, 'voice') || '1'
				if (!targets.some((target) => target.part === part && String(target.staff) === staff && target.voice === voice)) {
					return
				}
				const pitch = child(note, 'pitch')
				rows.push({
					key: `${part}.${measureIndex}.${noteIndex}`,
					part,
					measureIndex,
					noteIndex,
					measure: measure?.getAttribute('number')?.trim() ?? '',
					rest: child(note, 'rest') !== null,
					chord: child(note, 'chord') !== null,
					grace: child(note, 'grace') !== null,
					pitched: pitch !== null,
					step: pitch ? childText(pitch, 'step').toUpperCase() : '',
					alter: pitch ? alterValue(childText(pitch, 'alter')) : 0,
					octave: pitch ? Number.parseInt(childText(pitch, 'octave'), 10) || 0 : 0,
					accidental: child(note, 'accidental')?.textContent?.trim() || null,
					type: childText(note, 'type') || null,
					dots: children(note, 'dot').length,
					lyrics: readLyrics(note),
				})
			})
		}
	}
	return rows
}

/**
 * The values of a row as the form starts with them.
 *
 * @param row - note of the list
 */
export function rowValues(row: NoteRow): NoteValues {
	return {
		step: row.step,
		alter: row.alter,
		octave: row.octave,
		accidental: row.accidental,
		lyrics: row.lyrics.map((lyric) => [...lyric.texts]),
	}
}

/**
 * A pitch as text: "F#4", "Bb3" (ASCII, like the server's record) or with
 * music signs "F♯4" for display.
 *
 * @param step - step A–G
 * @param alter - alteration in semitones
 * @param octave - octave
 * @param signs - ♯/♭ instead of #/b
 */
export function pitchText(step: string, alter: number, octave: number, signs = false): string {
	const names: Record<string, [string, string]> = {
		'-2': ['bb', '𝄫'],
		'-1': ['b', '♭'],
		0: ['', ''],
		1: ['#', '♯'],
		2: ['##', '𝄪'],
	}
	const known = names[String(alter)]
	const sign = known ? known[signs ? 1 : 0] : `(${alter > 0 ? '+' : ''}${alter})`
	return `${step}${sign}${octave}`
}

/**
 * Text of a number for <alter> ("1", "-1", "0.5").
 *
 * @param alter - alteration
 */
function alterText(alter: number): string {
	return String(alter)
}

/**
 * Insert an element before a reference, keeping the indentation: the white
 * space before the reference is repeated before the new element.
 *
 * @param parent - parent element
 * @param element - new element
 * @param reference - element to insert before, or null to append
 */
function insertElement(parent: Element, element: Element, reference: Element | null): void {
	const space = reference?.previousSibling
	parent.insertBefore(element, reference)
	if (reference && space && space.nodeType === Node.TEXT_NODE && (space.textContent ?? '').trim() === '') {
		parent.insertBefore(parent.ownerDocument.createTextNode(space.textContent ?? ''), reference)
	}
}

/**
 * Remove an element and the white space before it.
 *
 * @param element - element to remove
 */
function removeElement(element: Element): void {
	const space = element.previousSibling
	if (space && space.nodeType === Node.TEXT_NODE && (space.textContent ?? '').trim() === '') {
		space.parentNode?.removeChild(space)
	}
	element.parentNode?.removeChild(element)
}

/**
 * Change the pitch of a note: step, alteration (added or removed between
 * step and octave) and octave. Changes only what differs.
 *
 * @param pitch - <pitch> element
 * @param values - new values
 */
function setPitch(pitch: Element, values: NoteValues): void {
	const step = child(pitch, 'step')
	if (step && (step.textContent ?? '').trim() !== values.step) {
		step.textContent = values.step
	}
	const alter = child(pitch, 'alter')
	const currentAlter = alter ? alterValue((alter.textContent ?? '').trim()) : 0
	if (values.alter === 0 && alter && currentAlter !== 0) {
		removeElement(alter)
	} else if (values.alter !== 0 && alter && currentAlter !== values.alter) {
		alter.textContent = alterText(values.alter)
	} else if (values.alter !== 0 && !alter) {
		const created = pitch.ownerDocument.createElement('alter')
		created.textContent = alterText(values.alter)
		insertElement(pitch, created, child(pitch, 'octave'))
	}
	const octave = child(pitch, 'octave')
	if (octave && Number.parseInt((octave.textContent ?? '').trim(), 10) !== values.octave) {
		octave.textContent = String(values.octave)
	}
}

/**
 * Add, change or remove the visible accidental of a note.
 *
 * @param note - <note> element
 * @param value - accidental value or null for none
 */
function setAccidental(note: Element, value: string | null): void {
	const accidental = child(note, 'accidental')
	if (value === null) {
		if (accidental) {
			removeElement(accidental)
		}
		return
	}
	if (accidental) {
		if ((accidental.textContent ?? '').trim() !== value) {
			accidental.textContent = value
		}
		return
	}
	const created = note.ownerDocument.createElement('accidental')
	created.textContent = value
	const reference = Array.from(note.children).find((element) => AFTER_ACCIDENTAL.includes(element.localName)) ?? null
	insertElement(note, created, reference)
}

/**
 * Apply the values of the form to a document. Only notes whose values differ
 * from the row are touched, and of them only what differs.
 *
 * @param document - the score (changed in place)
 * @param rows - the notes as listed
 * @param values - new values by row key
 * @return the changes, in the order of the rows
 */
export function applyValues(document: Document, rows: readonly NoteRow[], values: ReadonlyMap<string, NoteValues>): NoteChange[] {
	const changes: NoteChange[] = []
	for (const row of rows) {
		const wanted = values.get(row.key)
		if (!wanted) {
			continue
		}
		const note = measureNotes(document, row.part, row.measureIndex).notes[row.noteIndex]
		if (!note) {
			continue
		}
		const pitch = child(note, 'pitch')
		if (pitch && row.pitched
			&& (wanted.step !== row.step || wanted.alter !== row.alter || wanted.octave !== row.octave)) {
			setPitch(pitch, wanted)
			changes.push({
				key: row.key,
				measure: row.measure,
				kind: 'pitch',
				verse: null,
				old: pitchText(row.step, row.alter, row.octave),
				new: pitchText(wanted.step, wanted.alter, wanted.octave),
			})
		}
		if (row.pitched && wanted.accidental !== row.accidental) {
			setAccidental(note, wanted.accidental)
			changes.push({ key: row.key, measure: row.measure, kind: 'accidental', verse: null, old: row.accidental ?? '', new: wanted.accidental ?? '' })
		}
		const lyrics = children(note, 'lyric')
		row.lyrics.forEach((lyric, lyricIndex) => {
			const element = lyrics[lyricIndex]
			if (!element) {
				return
			}
			const texts = children(element, 'text')
			lyric.texts.forEach((old, textIndex) => {
				const text = wanted.lyrics[lyricIndex]?.[textIndex]
				const target = texts[textIndex]
				if (text !== undefined && text !== old && target) {
					target.textContent = text
					changes.push({ key: row.key, measure: row.measure, kind: 'lyric', verse: lyric.number || null, old, new: text })
				}
			})
		})
	}
	return changes
}

/**
 * What the values of the form change, without touching a document (for the
 * list of changes while editing). Same order and kinds as applyValues.
 *
 * @param rows - the notes as listed
 * @param values - new values by row key
 * @param signs - pitches with ♯/♭ instead of #/b
 * @param language - language of the interface; with signs, German note names where B is "H"
 */
export function pendingChanges(rows: readonly NoteRow[], values: ReadonlyMap<string, NoteValues>, signs = false, language = ''): NoteChange[] {
	const named = signs && stepLabel('B', language) === 'H'
	const pitch = (step: string, alter: number, octave: number) => named ? pitchName(step, alter, octave, true) : pitchText(step, alter, octave, signs)
	const changes: NoteChange[] = []
	for (const row of rows) {
		const wanted = values.get(row.key)
		if (!wanted) {
			continue
		}
		if (row.pitched && (wanted.step !== row.step || wanted.alter !== row.alter || wanted.octave !== row.octave)) {
			changes.push({
				key: row.key,
				measure: row.measure,
				kind: 'pitch',
				verse: null,
				old: pitch(row.step, row.alter, row.octave),
				new: pitch(wanted.step, wanted.alter, wanted.octave),
			})
		}
		if (row.pitched && wanted.accidental !== row.accidental) {
			changes.push({ key: row.key, measure: row.measure, kind: 'accidental', verse: null, old: row.accidental ?? '', new: wanted.accidental ?? '' })
		}
		row.lyrics.forEach((lyric, lyricIndex) => {
			lyric.texts.forEach((old, textIndex) => {
				const text = wanted.lyrics[lyricIndex]?.[textIndex]
				if (text !== undefined && text !== old) {
					changes.push({ key: row.key, measure: row.measure, kind: 'lyric', verse: lyric.number || null, old, new: text })
				}
			})
		})
	}
	return changes
}

/**
 * The corrected score: the original text with the values of the form.
 *
 * @param text - original MusicXML text
 * @param rows - the notes as listed
 * @param values - new values by row key
 */
export function correctedScore(text: string, rows: readonly NoteRow[], values: ReadonlyMap<string, NoteValues>): { xml: string, changes: NoteChange[] } {
	const document = parseScoreDocument(text)
	const changes = applyValues(document, rows, values)
	return { xml: serializeScore(document), changes }
}

/**
 * The corrected score for the preview: changed notes carry a color
 * attribute, so that the engraving shows them in color (the text list of
 * changes says the same in words).
 *
 * @param text - original MusicXML text
 * @param rows - the notes as listed
 * @param values - new values by row key
 * @param color - color of changed notes
 */
export function previewScore(text: string, rows: readonly NoteRow[], values: ReadonlyMap<string, NoteValues>, color = PREVIEW_COLOR): { xml: string, changes: NoteChange[] } {
	const document = parseScoreDocument(text)
	const changes = applyValues(document, rows, values)
	const changed = new Set(changes.map((change) => change.key))
	for (const row of rows) {
		if (changed.has(row.key)) {
			measureNotes(document, row.part, row.measureIndex).notes[row.noteIndex]?.setAttribute('color', color)
		}
	}
	return { xml: serializeScore(document), changes }
}

/**
 * Length of every measure of every part in divisions: the furthest point
 * the notes, <backup> and <forward> reach. A guard: a correction never
 * changes it.
 *
 * @param document - the score
 */
export function measureLengths(document: Document): string[] {
	const lengths: string[] = []
	children(document.documentElement, 'part').forEach((part, partIndex) => {
		children(part, 'measure').forEach((measure, measureIndex) => {
			let position = 0
			let furthest = 0
			for (const element of Array.from(measure.children)) {
				const duration = Number.parseFloat(childText(element, 'duration')) || 0
				if (element.localName === 'note') {
					if (child(element, 'chord') === null && child(element, 'grace') === null) {
						position += duration
					}
				} else if (element.localName === 'backup') {
					position -= duration
				} else if (element.localName === 'forward') {
					position += duration
				}
				furthest = Math.max(furthest, position)
			}
			lengths.push(`${partIndex}.${measureIndex}:${furthest}`)
		})
	})
	return lengths
}

/**
 * Whether two scores have the same measures with the same lengths.
 *
 * @param before - original MusicXML text
 * @param after - corrected MusicXML text
 */
export function sameMeasureLengths(before: string, after: string): boolean {
	const a = measureLengths(parseScoreDocument(before))
	const b = measureLengths(parseScoreDocument(after))
	return a.length === b.length && a.every((value, index) => value === b[index])
}
