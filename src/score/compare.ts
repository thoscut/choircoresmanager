/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Comparing two revisions of a MusicXML score measure by measure and voice
 * by voice (milestone 0.8, module "version_states"): what a choir member
 * needs to know, e.g. "m. 12, Tenor II: D4 → E4", a changed syllable, a note
 * value, or a measure added. Not a complete comparison of the files: layout,
 * dynamics, slurs and the like are left out.
 *
 * Measures are matched by their printed number (`measure@number`), parts by
 * their id (else their name), voices by staff and `<voice>`. Uses the small
 * XML parser of the score view (xml.ts), no browser API, so that it runs in
 * the tests as it runs in the app. The words come translated from the caller.
 */
import type { XmlElement } from './xml.ts'

import { splitPartName } from './voices.ts'
import { childElements, firstChild, parseXml, textOf } from './xml.ts'

export type ChangeKind = 'pitch' | 'accidental' | 'lyric' | 'rhythm' | 'notes' | 'measure_added' | 'measure_removed' | 'part_added' | 'part_removed'

/** One difference between two revisions. */
export interface ScoreChange {
	/** printed measure number; null for a part added or removed */
	measure: string | null
	/** name of the voice; null for a whole measure or part */
	voice: string | null
	kind: ChangeKind
	/** `number` of the lyric line (kind lyric), or null */
	verse: string | null
	/** before and after as text; '' for "nothing" */
	old: string
	new: string
}

/** Translated words and formats of the comparison. */
export interface CompareWords {
	/** a pitch, e.g. "F♯4" */
	pitch: (step: string, alter: number, octave: number) => string
	/** a rest */
	rest: string
	/** a note value, e.g. "quarter" plus dots */
	noteValue: (type: string, dots: number) => string
	/** a voice without a name of its own: "Tenor I/II, voice 2" */
	voiceOf: (part: string, number: number) => string
	/** a part without a name: "Part 3" */
	part: (number: number) => string
}

export interface Comparison {
	changes: ScoreChange[]
	/** more changes than `max`: the list stops there */
	truncated: boolean
}

/** A note or rest of a voice in a measure. */
interface Token {
	/** pitch text, the rest word, or "x" for an unpitched note */
	sound: string
	accidental: string
	rhythm: string
	/** lyric number => text */
	lyrics: Map<string, string>
}

interface PartData {
	key: string
	name: string
	/** measure key ("12", "12#2" for the second measure numbered 12) => notes per voice key */
	measures: Map<string, Map<string, Token[]>>
	/** printed number of each measure key */
	numbers: Map<string, string>
	order: string[]
}

/** The document is no MusicXML score. */
export class CompareFormatError extends Error {}

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
 * The notes of a measure grouped by voice ("staff|voice"), in document order.
 *
 * @param measure - <measure> (partwise) or <part> within a <measure> (timewise)
 * @param words - formats
 */
function voicesOf(measure: XmlElement, words: CompareWords): Map<string, Token[]> {
	const voices = new Map<string, Token[]>()
	for (const note of childElements(measure, 'note')) {
		const staff = textOf(firstChild(note, 'staff')) || '1'
		const voice = textOf(firstChild(note, 'voice')) || '1'
		const pitch = firstChild(note, 'pitch')
		let sound = 'x'
		if (firstChild(note, 'rest')) {
			sound = words.rest
		} else if (pitch) {
			sound = words.pitch(textOf(firstChild(pitch, 'step')).toUpperCase(), alterValue(textOf(firstChild(pitch, 'alter'))),
				Number.parseInt(textOf(firstChild(pitch, 'octave')), 10) || 0)
		}
		const type = textOf(firstChild(note, 'type'))
		const dots = childElements(note, 'dot').length
		const lyrics = new Map<string, string>()
		for (const lyric of childElements(note, 'lyric')) {
			const text = childElements(lyric, 'text').map((element) => textOf(element)).join(' ')
			lyrics.set(lyric.attrs.number ?? '1', text)
		}
		const key = `${staff}|${voice}`
		const tokens = voices.get(key) ?? []
		tokens.push({
			sound,
			accidental: textOf(firstChild(note, 'accidental')),
			rhythm: type !== '' ? words.noteValue(type, dots) : (textOf(firstChild(note, 'duration')) || ''),
			lyrics,
		})
		voices.set(key, tokens)
	}
	return voices
}

/**
 * The parts of a score with their measures.
 *
 * @param text - MusicXML text
 * @param words - formats
 */
function readScore(text: string, words: CompareWords): PartData[] {
	let root: XmlElement
	try {
		root = parseXml(text)
	} catch {
		throw new CompareFormatError('The file could not be read as MusicXML.')
	}
	if (root.name !== 'score-partwise' && root.name !== 'score-timewise') {
		throw new CompareFormatError('The file is not MusicXML.')
	}
	const names = new Map<string, string>()
	const partList = firstChild(root, 'part-list')
	for (const scorePart of partList ? childElements(partList, 'score-part') : []) {
		names.set(scorePart.attrs.id ?? '', textOf(firstChild(scorePart, 'part-name')))
	}
	const parts = new Map<string, PartData>()
	const partOf = (id: string): PartData => {
		let part = parts.get(id)
		if (!part) {
			part = { key: id, name: names.get(id) ?? '', measures: new Map(), numbers: new Map(), order: [] }
			parts.set(id, part)
		}
		return part
	}
	const add = (part: PartData, measure: XmlElement, number: string) => {
		let key = number
		for (let n = 2; part.measures.has(key); n++) {
			key = `${number}#${n}`
		}
		part.measures.set(key, voicesOf(measure, words))
		part.numbers.set(key, number)
		part.order.push(key)
	}
	if (root.name === 'score-partwise') {
		childElements(root, 'part').forEach((element, index) => {
			const part = partOf(element.attrs.id ?? `#${index}`)
			for (const measure of childElements(element, 'measure')) {
				add(part, measure, measure.attrs.number ?? '')
			}
		})
	} else {
		for (const measure of childElements(root, 'measure')) {
			childElements(measure, 'part').forEach((element, index) => {
				add(partOf(element.attrs.id ?? `#${index}`), element, measure.attrs.number ?? '')
			})
		}
	}
	return [...parts.values()]
}

/**
 * Names of the voices of a part, by voice key: the part name for a single
 * voice, the names a part name announces ("Tenor I/II") for several voices
 * on one staff, else "part, voice n".
 *
 * @param name - part name
 * @param keys - voice keys of both revisions
 * @param words - formats
 */
function voiceNames(name: string, keys: string[], words: CompareWords): Map<string, string> {
	const sorted = [...keys].sort((a, b) => {
		const [staffA, voiceA] = a.split('|')
		const [staffB, voiceB] = b.split('|')
		return Number(staffA) - Number(staffB) || Number(voiceA) - Number(voiceB) || a.localeCompare(b)
	})
	const result = new Map<string, string>()
	const staves = new Set(sorted.map((key) => key.split('|')[0]))
	const announced = splitPartName(name)
	sorted.forEach((key, index) => {
		if (sorted.length === 1) {
			result.set(key, name)
		} else if (staves.size === 1 && announced.length >= sorted.length) {
			result.set(key, announced[index] ?? name)
		} else {
			result.set(key, words.voiceOf(name, index + 1))
		}
	})
	return result
}

/**
 * The notes of a voice as short text.
 *
 * @param tokens - notes and rests
 */
function soundsText(tokens: Token[] | undefined): string {
	if (!tokens || tokens.length === 0) {
		return ''
	}
	const shown = tokens.slice(0, 16).map((token) => token.sound)
	return shown.join(' ') + (tokens.length > shown.length ? ' …' : '')
}

/**
 * Compare two revisions of a score.
 *
 * @param before - MusicXML text of the older revision
 * @param after - MusicXML text of the newer revision
 * @param words - translated words and formats
 * @param max - most changes listed
 */
export function compareScores(before: string, after: string, words: CompareWords, max = 300): Comparison {
	const a = readScore(before, words)
	const b = readScore(after, words)
	const changes: ScoreChange[] = []
	let truncated = false
	const push = (change: ScoreChange): void => {
		if (changes.length >= max) {
			truncated = true
			return
		}
		changes.push(change)
	}

	// Parts by id, else by name
	const partName = (part: PartData, index: number) => part.name || words.part(index + 1)
	const pairs: [PartData, PartData, string][] = []
	const matched = new Set<PartData>()
	a.forEach((partA, index) => {
		const partB = b.find((candidate) => candidate.key === partA.key && !matched.has(candidate))
			?? b.find((candidate) => candidate.name !== '' && candidate.name === partA.name && !matched.has(candidate))
		if (partB) {
			matched.add(partB)
			pairs.push([partA, partB, partName(partA, index)])
		} else {
			push({ measure: null, voice: null, kind: 'part_removed', verse: null, old: partName(partA, index), new: '' })
		}
	})
	b.forEach((partB, index) => {
		if (!matched.has(partB)) {
			push({ measure: null, voice: null, kind: 'part_added', verse: null, old: '', new: partName(partB, index) })
		}
	})

	// Measures added or removed, once (by the first part of each revision)
	const firstA = a[0]
	const firstB = b[0]
	if (firstA && firstB) {
		for (const key of firstB.order) {
			if (!firstA.measures.has(key)) {
				push({ measure: firstB.numbers.get(key) ?? key, voice: null, kind: 'measure_added', verse: null, old: '', new: '' })
			}
		}
		for (const key of firstA.order) {
			if (!firstB.measures.has(key)) {
				push({ measure: firstA.numbers.get(key) ?? key, voice: null, kind: 'measure_removed', verse: null, old: '', new: '' })
			}
		}
	}

	// Notes of the measures both revisions have, voice by voice
	for (const [partA, partB, name] of pairs) {
		const keys = new Set<string>()
		for (const voices of [...partA.measures.values(), ...partB.measures.values()]) {
			for (const key of voices.keys()) {
				keys.add(key)
			}
		}
		const names = voiceNames(name, [...keys], words)
		for (const measureKey of partA.order) {
			const voicesA = partA.measures.get(measureKey)
			const voicesB = partB.measures.get(measureKey)
			if (!voicesA || !voicesB) {
				continue
			}
			const measure = partA.numbers.get(measureKey) ?? measureKey
			const voiceKeys = [...new Set([...voicesA.keys(), ...voicesB.keys()])].sort((x, y) => (names.get(x) ?? x).localeCompare(names.get(y) ?? y))
			for (const voiceKey of voiceKeys) {
				const voice = names.get(voiceKey) ?? name
				const tokensA = voicesA.get(voiceKey) ?? []
				const tokensB = voicesB.get(voiceKey) ?? []
				if (tokensA.length !== tokensB.length) {
					push({ measure, voice, kind: 'notes', verse: null, old: soundsText(tokensA), new: soundsText(tokensB) })
					continue
				}
				for (let i = 0; i < tokensA.length; i++) {
					const x = tokensA[i]
					const y = tokensB[i]
					const found: ScoreChange[] = []
					if (x.sound !== y.sound) {
						found.push({ measure, voice, kind: 'pitch', verse: null, old: x.sound, new: y.sound })
					} else if (x.accidental !== y.accidental) {
						found.push({ measure, voice, kind: 'accidental', verse: null, old: x.accidental, new: y.accidental })
					}
					if (x.rhythm !== y.rhythm) {
						found.push({ measure, voice, kind: 'rhythm', verse: null, old: x.rhythm, new: y.rhythm })
					}
					const verses = [...new Set([...x.lyrics.keys(), ...y.lyrics.keys()])].sort()
					for (const verse of verses) {
						const oldText = x.lyrics.get(verse) ?? ''
						const newText = y.lyrics.get(verse) ?? ''
						if (oldText !== newText) {
							found.push({ measure, voice, kind: 'lyric', verse, old: oldText, new: newText })
						}
					}
					found.forEach(push)
				}
			}
		}
	}

	// In the order of the measures of the newer revision; measures removed last
	const position = new Map<string, number>()
	for (const [part, order] of [[firstB, firstB?.order ?? []], [firstA, firstA?.order ?? []]] as const) {
		for (const key of order) {
			const number = part?.numbers.get(key) ?? key
			if (!position.has(number)) {
				position.set(number, position.size)
			}
		}
	}
	const at = (change: ScoreChange) => (change.measure === null ? -1 : (position.get(change.measure) ?? position.size))
	changes.sort((x, y) => at(x) - at(y))
	return { changes, truncated }
}
