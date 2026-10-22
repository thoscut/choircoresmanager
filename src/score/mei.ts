/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What the score view needs from the MEI that Verovio produces when it
 * imports MusicXML: measure ids, and every note with staff, layer (= MusicXML
 * voice), MIDI pitch and measure. The ids are the ones used in Verovio's SVG
 * and timemap, so notes can be highlighted and played.
 */
import type { XmlElement } from './xml.ts'

import { childElements, descendants, firstChild, parseXml, textOf } from './xml.ts'

export interface MeiMeasure {
	id: string
	/** `measure@n`: Verovio copies the MusicXML measure number */
	n: string
}

export interface MeiNote {
	id: string
	/** index into `MeiScore.measures` */
	measure: number
	staff: number
	layer: number
	/** MIDI pitch, null if the note has no pitch */
	pitch: number | null
	grace: boolean
}

export interface MeiTie {
	start: string
	end: string
}

export interface MeiStaff {
	n: number
	/** label of the staff or of its group (multi-staff part) */
	label: string
}

export interface MeiScore {
	measures: MeiMeasure[]
	notes: MeiNote[]
	ties: MeiTie[]
	staves: MeiStaff[]
}

const STEPS: Record<string, number> = { c: 0, d: 2, e: 4, f: 5, g: 7, a: 9, b: 11 }

const ALTERATIONS: Record<string, number> = {
	n: 0,
	s: 1,
	f: -1,
	ss: 2,
	x: 2,
	ff: -2,
	xs: 3,
	sx: 3,
	ts: 3,
	tf: -3,
	nf: -1,
	ns: 1,
	su: 1,
	sd: 1,
	fu: -1,
	fd: -1,
	nu: 0,
	nd: 0,
}

/**
 * MIDI pitch of an MEI note: gestural values first (they include the key
 * signature and accidentals carried through the measure), then written ones.
 *
 * @param note - `<note>` element
 * @param transposition - `trans.semi` of the staff
 */
export function notePitch(note: XmlElement, transposition = 0): number | null {
	const pname = (note.attrs['pname.ges'] ?? note.attrs.pname ?? '').toLowerCase()
	const octave = Number(note.attrs['oct.ges'] ?? note.attrs.oct)
	if (!(pname in STEPS) || !Number.isFinite(octave)) {
		return null
	}
	let accid: string | undefined = note.attrs['accid.ges'] ?? note.attrs.accid
	if (accid === undefined) {
		const child = firstChild(note, 'accid')
		accid = child?.attrs['accid.ges'] ?? child?.attrs.accid
	}
	const alter = accid !== undefined ? (ALTERATIONS[accid] ?? 0) : 0
	return 12 * (octave + 1) + STEPS[pname] + alter + transposition
}

/**
 * Staves from the first `<scoreDef>`, with labels and transposition.
 *
 * @param scoreDef - the score definition
 */
function readStaves(scoreDef: XmlElement | undefined): { staves: MeiStaff[], transposition: Map<number, number> } {
	const staves: MeiStaff[] = []
	const transposition = new Map<number, number>()
	const visit = (element: XmlElement, groupLabel: string) => {
		for (const child of childElements(element)) {
			if (child.name === 'staffGrp') {
				const label = textOf(firstChild(child, 'label')) || child.attrs.label || ''
				visit(child, label || groupLabel)
			} else if (child.name === 'staffDef') {
				const n = Number(child.attrs.n)
				const label = textOf(firstChild(child, 'label')) || child.attrs.label || groupLabel
				staves.push({ n, label })
				const semi = Number(child.attrs['trans.semi'])
				if (Number.isFinite(semi) && semi !== 0) {
					transposition.set(n, semi)
				}
			}
		}
	}
	if (scoreDef) {
		visit(scoreDef, '')
	}
	return { staves, transposition }
}

/**
 * Parse Verovio's MEI output.
 *
 * @param mei - MEI text from `toolkit.getMEI()`
 */
export function parseMei(mei: string): MeiScore {
	const root = parseXml(mei)
	const { staves, transposition } = readStaves(descendants(root, 'scoreDef')[0])
	const measures: MeiMeasure[] = []
	const notes: MeiNote[] = []
	const ties: MeiTie[] = []

	const collectNotes = (element: XmlElement, measure: number, staff: number, layer: number) => {
		for (const child of childElements(element)) {
			if (child.name === 'note') {
				const id = child.attrs['xml:id']
				if (id) {
					notes.push({
						id,
						measure,
						staff,
						layer,
						pitch: notePitch(child, transposition.get(staff) ?? 0),
						grace: child.attrs.grace !== undefined,
					})
				}
			}
			collectNotes(child, measure, staff, layer)
		}
	}

	const visit = (element: XmlElement) => {
		for (const child of childElements(element)) {
			if (child.name !== 'measure') {
				visit(child)
				continue
			}
			const index = measures.length
			measures.push({ id: child.attrs['xml:id'] ?? '', n: child.attrs.n ?? '' })
			for (const staff of childElements(child, 'staff')) {
				const staffN = Number(staff.attrs.n)
				for (const layer of childElements(staff, 'layer')) {
					collectNotes(layer, index, staffN, Number(layer.attrs.n))
				}
			}
			for (const tie of descendants(child, 'tie')) {
				const start = (tie.attrs.startid ?? '').replace(/^#/, '')
				const end = (tie.attrs.endid ?? '').replace(/^#/, '')
				if (start !== '' && end !== '' && start !== end) {
					ties.push({ start, end })
				}
			}
		}
	}
	visit(root)
	return { measures, notes, ties, staves }
}
