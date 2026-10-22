/**
 * The parts of a MusicXML file that Verovio's MEI does not keep or keeps
 * incompletely: part names and staff counts, printed measure numbers, and
 * the playback structure (repeats with `times`, endings, D.C./D.S./Fine/Coda
 * from `<sound>`), plus the tempo marking.
 */
import type { XmlElement } from './xml.ts'

import { childElements, descendants, firstChild, parseXml, textOf } from './xml.ts'

export interface PartInfo {
	id: string
	/** part name as in the file, also when it is not printed */
	name: string
	/** number of staves of the part (piano: 2) */
	staves: number
}

/** Structure marks of one measure, read from the first part. */
export interface MeasureMarks {
	/** `measure@number` – the printed measure number */
	number: string
	/** `implicit="yes"`: not counted (pickup, measure split by a repeat) */
	implicit: boolean
	repeatStart: boolean
	repeatEnd: boolean
	/** `times` of the backward repeat; null = not given */
	repeatTimes: number | null
	/** passes of an ending that starts in this measure */
	endingStart: number[] | null
	/** an ending ends in this measure (type stop or discontinue) */
	endingStop: boolean
	segno: string | null
	coda: string | null
	toCoda: string | null
	daCapo: boolean
	dalSegno: string | null
	fine: boolean
}

export interface TempoMark {
	/** MusicXML note type, e.g. "quarter" */
	beatUnit: string
	dots: number
	perMinute: number
	/** false if the marking is not printed (editorial playback tempo) */
	printed: boolean
}

/** Key and time signature at the beginning of the first part. */
export interface Signatures {
	/** sharps (positive) or flats (negative); null = unknown */
	fifths: number | null
	/** "major", "minor", … or '' */
	mode: string
	/** e.g. "3/4"; '' = unknown */
	time: string
}

export interface MusicXmlStructure {
	parts: PartInfo[]
	measures: MeasureMarks[]
	signatures: Signatures
	/** first metronome marking of the first part */
	tempo: TempoMark | null
	/** first `<sound tempo>` (quarter notes per minute) */
	soundTempo: number | null
}

/**
 * Pass numbers of an ending: "1", "1, 2", "1.2.", "1-3".
 *
 * @param value - `ending@number`
 */
export function parseEndingNumbers(value: string): number[] {
	const numbers: number[] = []
	for (const match of value.matchAll(/(\d+)\s*[-–]\s*(\d+)|(\d+)/g)) {
		if (match[1] !== undefined) {
			const from = Number(match[1])
			const to = Math.min(Number(match[2]), from + 20)
			for (let k = from; k <= to; k++) {
				numbers.push(k)
			}
		} else {
			numbers.push(Number(match[3]))
		}
	}
	return [...new Set(numbers)].sort((a, b) => a - b)
}

/**
 * Structure marks of one measure.
 *
 * @param content - the `<measure>` of the first part (partwise) or the
 *   first `<part>` inside a `<measure>` (timewise)
 * @param measure - the `<measure>` element itself (number, implicit)
 */
function readMeasure(content: XmlElement, measure: XmlElement): MeasureMarks {
	const marks: MeasureMarks = {
		number: (measure.attrs.number ?? '').trim(),
		implicit: measure.attrs.implicit === 'yes',
		repeatStart: false,
		repeatEnd: false,
		repeatTimes: null,
		endingStart: null,
		endingStop: false,
		segno: null,
		coda: null,
		toCoda: null,
		daCapo: false,
		dalSegno: null,
		fine: false,
	}
	for (const barline of childElements(content, 'barline')) {
		for (const repeat of childElements(barline, 'repeat')) {
			if (repeat.attrs.direction === 'forward') {
				marks.repeatStart = true
			} else if (repeat.attrs.direction === 'backward') {
				marks.repeatEnd = true
				const times = Number(repeat.attrs.times)
				if (Number.isInteger(times) && times > 0) {
					marks.repeatTimes = times
				}
			}
		}
		for (const ending of childElements(barline, 'ending')) {
			if (ending.attrs.type === 'start') {
				marks.endingStart = parseEndingNumbers(ending.attrs.number ?? '')
			} else if (ending.attrs.type === 'stop' || ending.attrs.type === 'discontinue') {
				marks.endingStop = true
			}
		}
	}
	for (const sound of descendants(content, 'sound')) {
		const attrs = sound.attrs
		if (attrs.segno !== undefined) {
			marks.segno = attrs.segno
		}
		if (attrs.coda !== undefined) {
			marks.coda = attrs.coda
		}
		if (attrs.tocoda !== undefined) {
			marks.toCoda = attrs.tocoda
		}
		if (attrs.dalsegno !== undefined) {
			marks.dalSegno = attrs.dalsegno
		}
		if (attrs.dacapo === 'yes') {
			marks.daCapo = true
		}
		if (attrs.fine !== undefined && attrs.fine !== 'no') {
			marks.fine = true
		}
	}
	return marks
}

/**
 * Highest staff count announced in a part's `<attributes><staves>`.
 *
 * @param elements - elements that contain `<attributes>` (measures or parts)
 */
function staffCount(elements: XmlElement[]): number {
	let staves = 1
	for (const element of elements) {
		for (const attributes of childElements(element, 'attributes')) {
			const value = Number(textOf(firstChild(attributes, 'staves')))
			if (Number.isInteger(value) && value > staves) {
				staves = value
			}
		}
	}
	return staves
}

/**
 * Key and time signature from the first <attributes> that has them.
 *
 * @param measures - measure contents of the first part
 */
function readSignatures(measures: XmlElement[]): Signatures {
	const result: Signatures = { fifths: null, mode: '', time: '' }
	for (const measure of measures.slice(0, 2)) {
		for (const attributes of childElements(measure, 'attributes')) {
			const key = firstChild(attributes, 'key')
			if (key && result.fifths === null) {
				const fifths = Number(textOf(firstChild(key, 'fifths')))
				result.fifths = Number.isInteger(fifths) ? fifths : null
				result.mode = textOf(firstChild(key, 'mode'))
			}
			const time = firstChild(attributes, 'time')
			if (time && result.time === '') {
				const beats = textOf(firstChild(time, 'beats'))
				const beatType = textOf(firstChild(time, 'beat-type'))
				result.time = beats !== '' && beatType !== '' ? `${beats}/${beatType}` : ''
			}
		}
	}
	return result
}

function readTempo(scope: XmlElement): { tempo: TempoMark | null, soundTempo: number | null } {
	let tempo: TempoMark | null = null
	const metronome = descendants(scope, 'metronome')[0]
	if (metronome) {
		const perMinute = parseFloat(textOf(firstChild(metronome, 'per-minute')).replace(/[^\d.,]/g, '').replace(',', '.'))
		const beatUnit = textOf(firstChild(metronome, 'beat-unit'))
		if (beatUnit !== '' && Number.isFinite(perMinute) && perMinute > 0) {
			tempo = {
				beatUnit,
				dots: childElements(metronome, 'beat-unit-dot').length,
				perMinute,
				printed: metronome.attrs['print-object'] !== 'no',
			}
		}
	}
	let soundTempo: number | null = null
	for (const sound of descendants(scope, 'sound')) {
		const value = parseFloat(sound.attrs.tempo ?? '')
		if (Number.isFinite(value) && value > 0) {
			soundTempo = value
			break
		}
	}
	return { tempo, soundTempo }
}

/**
 * Read the structure of a MusicXML document (partwise or timewise).
 *
 * @param text - MusicXML text
 */
export function parseMusicXml(text: string): MusicXmlStructure {
	const root = parseXml(text)
	if (root.name !== 'score-partwise' && root.name !== 'score-timewise') {
		throw new Error('The file is not MusicXML.')
	}
	const names = new Map<string, string>()
	const partList = firstChild(root, 'part-list')
	for (const scorePart of partList ? childElements(partList, 'score-part') : []) {
		names.set(scorePart.attrs.id ?? '', textOf(firstChild(scorePart, 'part-name')))
	}

	const parts: PartInfo[] = []
	let measures: MeasureMarks[] = []
	let tempoScope: XmlElement | undefined
	let firstContents: XmlElement[] = []

	if (root.name === 'score-partwise') {
		childElements(root, 'part').forEach((part, index) => {
			const partMeasures = childElements(part, 'measure')
			const id = part.attrs.id ?? ''
			parts.push({ id, name: names.get(id) ?? '', staves: staffCount(partMeasures) })
			if (index === 0) {
				measures = partMeasures.map((measure) => readMeasure(measure, measure))
				tempoScope = part
				firstContents = partMeasures
			}
		})
	} else {
		const timewise = childElements(root, 'measure')
		const order: string[] = []
		const contents = new Map<string, XmlElement[]>()
		for (const measure of timewise) {
			for (const part of childElements(measure, 'part')) {
				const id = part.attrs.id ?? ''
				if (!contents.has(id)) {
					contents.set(id, [])
					order.push(id)
				}
				contents.get(id)?.push(part)
			}
		}
		for (const id of order) {
			parts.push({ id, name: names.get(id) ?? '', staves: staffCount(contents.get(id) ?? []) })
		}
		measures = timewise.map((measure) => readMeasure(childElements(measure, 'part')[0] ?? measure, measure))
		firstContents = contents.get(order[0] ?? '') ?? []
		tempoScope = timewise.length > 0 ? root : undefined
	}

	const { tempo, soundTempo } = tempoScope ? readTempo(tempoScope) : { tempo: null, soundTempo: null }
	return { parts, measures, signatures: readSignatures(firstContents), tempo, soundTempo }
}
