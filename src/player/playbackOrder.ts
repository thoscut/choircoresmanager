// Playback order of a MusicXML score: repeats, voltas and jumps (D.C., D.S., Fine, Coda)
//
// The rules follow MuseScore 4's playback. They were checked against the MIDI output of
// MuseScore 4.7 for 92 choir pieces with repeats and jumps:
// - A backward repeat :‖ jumps back to the last forward repeat ‖: before it, or to the start of
//   the piece if there is none. It does not jump to the measure after an earlier :‖.
// - The pass counter runs per repeat section. A :‖ inside a volta bracket jumps back while the
//   pass is lower than the repeat's times or the highest volta number of that bracket group, so
//   "1. :‖ 2. :‖ 3." plays three passes.
// - D.C. jumps to the start, D.S. to the first segno. After the jump no repeat is taken again and
//   of each bracket group only the last volta is played. "al Fine" stops at the Fine,
//   "al Coda" continues at the To Coda mark with the coda.
// - Jump marks may appear in any part, often only in the first one, sometimes only in another.
//
// Only <sound> elements make a measure jump, as in MuseScore's import. Texts like "D.C. al Fine"
// without them are reported as warnings. Segno and coda symbols are used as jump targets when the
// score has no <sound> mark for them.

/**
 * Playback instruction of a <sound> element
 */
export type JumpMark = 'segno' | 'coda' | 'tocoda' | 'dacapo' | 'dalsegno' | 'fine'

/**
 * Repeat and jump information of one measure, merged over all parts
 */
export interface ScoreMeasure {
	/** The measure's number attribute */
	number: string
	/** The measure starts with a forward repeat ‖: */
	repeatStart: boolean
	/** Number of passes of a backward repeat :‖ at the end of the measure, null without one */
	repeatEnd: number | null
	/** Numbers of the volta bracket the measure lies in, null outside of volta brackets */
	ending: number[] | null
	/** Playback instructions from <sound> elements */
	jumps: Set<JumpMark>
	/** Printed segno and coda symbols */
	symbols: Set<'segno' | 'coda'>
	/** Texts of <words> directions */
	words: string[]
}

export interface PlaybackOrderOptions {
	/**
	 * Numbers of measures whose backward repeat is printed but not played
	 * ("repeat_play_once", MuseScore's play count 1)
	 */
	playOnce?: Iterable<string>
}

export interface PlaybackOrder {
	/** Indices into the measure list, in the order they are played */
	measures: number[]
	/** Notation that is probably not played as intended */
	warnings: string[]
}

const JUMP_MARKS: JumpMark[] = ['segno', 'coda', 'tocoda', 'dacapo', 'dalsegno', 'fine']
const DEFAULT_REPEAT_TIMES = 2
const ELEMENT_NODE = 1
const CODA_WORD = /coda|\u{1D10C}|⊕/u
const JUMP_TEXT = /\b(?:d\.\s*[cs]\b|da\s+capo|dal\s+segno)/i

/**
 * MusicXML measure of one part
 */
interface PartMeasure {
	number: string
	content: Element
}

/**
 * Parse a MusicXML document with the DOMParser of the environment.
 *
 * @param text - Content of an uncompressed MusicXML file
 * @throws {Error} if the text is not well-formed XML
 */
export function parseMusicXml(text: string): Document {
	const doc = new DOMParser().parseFromString(text, 'application/xml')
	if (doc.getElementsByTagName('parsererror').length > 0) {
		throw new Error('The MusicXML document is not well-formed')
	}
	return doc
}

/**
 * @param parent - Element or document
 * @param name - Only return elements with this name
 */
function childElements(parent: Node, name?: string): Element[] {
	const result: Element[] = []
	for (const node of Array.from(parent.childNodes)) {
		if (node.nodeType === ELEMENT_NODE && (name === undefined || nameOf(node as Element) === name)) {
			result.push(node as Element)
		}
	}
	return result
}

/**
 * @param element - An element without namespace
 */
function nameOf(element: Element): string {
	return element.localName || element.nodeName
}

/**
 * Measures of every part, for partwise and timewise scores
 *
 * @param root - The score element
 */
function readParts(root: Element): PartMeasure[][] {
	if (nameOf(root) !== 'score-timewise') {
		return childElements(root, 'part').map((part) => childElements(part, 'measure')
			.map((measure) => ({ number: measure.getAttribute('number') ?? '', content: measure })))
	}

	const parts = new Map<string, PartMeasure[]>()
	for (const measure of childElements(root, 'measure')) {
		const number = measure.getAttribute('number') ?? ''
		for (const part of childElements(measure, 'part')) {
			const id = part.getAttribute('id') ?? ''
			if (!parts.has(id)) {
				parts.set(id, [])
			}
			parts.get(id)!.push({ number, content: part })
		}
	}
	return [...parts.values()]
}

/**
 * Number of passes of a backward repeat
 *
 * @param times - The repeat's times attribute
 */
function parseTimes(times: string | null): number {
	const value = Number.parseInt(times ?? '', 10)
	return Number.isNaN(value) ? DEFAULT_REPEAT_TIMES : Math.max(1, value)
}

/**
 * Passes a volta bracket is played in, e.g. "1, 2" -> [1, 2].
 * Ranges like "1-3" are not valid MusicXML but accepted as well.
 *
 * @param number - The ending's number attribute
 */
function parseEndingNumbers(number: string | null): number[] {
	const numbers: number[] = []
	for (const match of (number ?? '').matchAll(/(\d+)\s*[-–]\s*(\d+)|\d+/g)) {
		if (match[1] !== undefined) {
			for (let n = Number(match[1]); n <= Number(match[2]); n++) {
				numbers.push(n)
			}
		} else {
			numbers.push(Number(match[0]))
		}
	}
	return numbers.length > 0 ? numbers : [1]
}

/**
 * @param sound - A <sound> element
 * @param jumps - Set the found playback instructions are added to
 */
function readSound(sound: Element, jumps: Set<JumpMark>): void {
	for (const mark of JUMP_MARKS) {
		const value = sound.getAttribute(mark)?.trim()
		if (value && value.toLowerCase() !== 'no') {
			jumps.add(mark)
		}
	}
}

/**
 * @param element - A <direction> or <barline> element
 * @param symbols - Set the found symbols are added to
 */
function readSymbols(element: Element, symbols: Set<'segno' | 'coda'>): void {
	for (const symbol of ['segno', 'coda'] as const) {
		if (element.getElementsByTagName(symbol).length > 0) {
			symbols.add(symbol)
		}
	}
}

/**
 * Repeat and jump information of the measures of one part
 *
 * @param measures - The part's measures in score order
 */
function readPartMeasures(measures: PartMeasure[]): ScoreMeasure[] {
	// A volta bracket may span several measures: it is open from its start to its stop or discontinue
	let ending: number[] | null = null
	return measures.map(({ number, content }) => {
		const measure: ScoreMeasure = {
			number,
			repeatStart: false,
			repeatEnd: null,
			ending: null,
			jumps: new Set(),
			symbols: new Set(),
			words: [],
		}
		let endingCloses = false
		for (const element of childElements(content)) {
			switch (nameOf(element)) {
				case 'barline': {
					const repeat = childElements(element, 'repeat')[0]
					if (repeat) {
						if (repeat.getAttribute('direction') === 'forward') {
							measure.repeatStart = true
						} else {
							measure.repeatEnd = parseTimes(repeat.getAttribute('times'))
						}
					}
					const endingElement = childElements(element, 'ending')[0]
					const type = endingElement?.getAttribute('type')
					if (type === 'start') {
						ending = parseEndingNumbers(endingElement.getAttribute('number'))
					} else if (type === 'stop' || type === 'discontinue') {
						endingCloses = true
					}
					readSymbols(element, measure.symbols)
					break
				}
				case 'direction':
					for (const sound of childElements(element, 'sound')) {
						readSound(sound, measure.jumps)
					}
					for (const words of Array.from(element.getElementsByTagName('words'))) {
						if (words.textContent) {
							measure.words.push(words.textContent)
						}
					}
					readSymbols(element, measure.symbols)
					break
				case 'sound':
					readSound(element, measure.jumps)
					break
			}
		}
		measure.ending = ending
		if (endingCloses) {
			ending = null
		}
		return measure
	})
}

/**
 * Read the repeat and jump information of a MusicXML score.
 *
 * The measures are those of the first part. Repeat barlines and volta brackets are taken from
 * the first part, jump marks and words from all parts.
 *
 * @param doc - Parsed MusicXML document (score-partwise or score-timewise)
 */
export function readScoreMeasures(doc: Document): ScoreMeasure[] {
	const parts = readParts(doc.documentElement).map(readPartMeasures)
	if (parts.length === 0) {
		return []
	}
	return parts[0].map((first, index) => {
		const measure: ScoreMeasure = { ...first, jumps: new Set(), symbols: new Set(), words: [] }
		for (const part of parts) {
			const other = part[index]
			if (!other) {
				continue
			}
			other.jumps.forEach((jump) => measure.jumps.add(jump))
			other.symbols.forEach((symbol) => measure.symbols.add(symbol))
			measure.words.push(...other.words)
		}
		return measure
	})
}

/**
 * Highest volta number of each group of consecutive measures in volta brackets,
 * 0 for measures outside of volta brackets
 *
 * @param measures - The score's measures
 */
function voltaGroupMaxima(measures: ScoreMeasure[]): number[] {
	const maxima = measures.map(() => 0)
	let start = 0
	while (start < measures.length) {
		if (!measures[start].ending) {
			start++
			continue
		}
		let end = start
		let max = 0
		while (end < measures.length && measures[end].ending) {
			max = Math.max(max, ...measures[end].ending!)
			end++
		}
		maxima.fill(max, start, end)
		start = end
	}
	return maxima
}

/**
 * Jump marks of every measure. Printed segno and coda symbols stand in for missing <sound> marks:
 * a segno symbol is a segno, a coda symbol before the first D.C./D.S. a To Coda mark and one
 * after it the coda.
 *
 * @param measures - The score's measures
 */
function resolveJumpMarks(measures: ScoreMeasure[]): Set<JumpMark>[] {
	const marks = measures.map((measure) => new Set(measure.jumps))
	const hasMark = (mark: JumpMark) => marks.some((set) => set.has(mark))

	if (!hasMark('segno')) {
		measures.forEach((measure, index) => {
			if (measure.symbols.has('segno')) {
				marks[index].add('segno')
			}
		})
	}

	const firstJump = marks.findIndex((set) => set.has('dacapo') || set.has('dalsegno'))
	if (firstJump >= 0) {
		const needsToCoda = !hasMark('tocoda')
		const needsCoda = !hasMark('coda')
		measures.forEach((measure, index) => {
			if (!measure.symbols.has('coda') || marks[index].has('coda') || marks[index].has('tocoda')) {
				return
			}
			if (needsToCoda && index < firstJump) {
				marks[index].add('tocoda')
			} else if (needsCoda && index > firstJump) {
				marks[index].add('coda')
			}
		})
	}
	return marks
}

/**
 * Notes about repeats and jumps that are probably not played as intended
 *
 * @param measures - The score's measures
 * @param marks - Jump marks of every measure
 */
function findWarnings(measures: ScoreMeasure[], marks: Set<JumpMark>[]): string[] {
	const warnings: string[] = []
	let openRepeat = false
	let earlierRepeatEnd = false
	measures.forEach((measure, index) => {
		if (measure.repeatStart) {
			openRepeat = true
		}
		if (measure.repeatEnd !== null) {
			if (!openRepeat && earlierRepeatEnd) {
				warnings.push(`Measure ${measure.number}: the backward repeat has no forward repeat after the previous one, so it jumps back to the last forward repeat or the start of the piece`)
			}
			openRepeat = false
			earlierRepeatEnd = true
		}
		if (!marks[index].has('dacapo') && !marks[index].has('dalsegno')) {
			const text = measure.words.find((words) => JUMP_TEXT.test(words))
			if (text) {
				warnings.push(`Measure ${measure.number}: "${text.trim()}" has no playback instruction and is not played`)
			}
		}
	})
	return warnings
}

/**
 * Compute the order in which the measures of a score are played.
 *
 * @param measures - The score's measures, see readScoreMeasures
 * @param options - Playback options
 */
export function computePlaybackOrder(measures: ScoreMeasure[], options: PlaybackOrderOptions = {}): PlaybackOrder {
	const count = measures.length
	const playOnce = new Set(options.playOnce ?? [])
	const groupMax = voltaGroupMaxima(measures)
	const marks = resolveJumpMarks(measures)
	const markedMeasures = (mark: JumpMark) => marks.flatMap((set, index) => (set.has(mark) ? [index] : []))
	const segnos = markedMeasures('segno')
	const codas = markedMeasures('coda')
	const hasToCoda = marks.some((set) => set.has('tocoda'))
	const hasFine = marks.some((set) => set.has('fine'))
	const warnings = findWarnings(measures, marks)

	const order: number[] = []
	// Passes taken back from each backward repeat outside of volta brackets
	const repeatsTaken = new Map<number, number>()
	// Measures whose D.C./D.S. was followed; a jump is only taken once
	const jumpsTaken = new Set<number>()
	let index = 0
	let repeatStart = 0
	let pass = 1
	let jumped = false
	let playUntil: 'end' | 'fine' | 'coda' = 'end'
	let cameBack = false
	// Guards against notation that would loop forever
	const maxSteps = 50 * count

	for (let step = 0; index < count && step < maxSteps; step++) {
		const measure = measures[index]
		if (measure.repeatStart && !cameBack && !jumped) {
			repeatStart = index
			pass = 1
		}
		cameBack = false

		if (measure.ending) {
			// After a D.C./D.S. only the last volta of each bracket group is played
			const wanted = jumped ? groupMax[index] : pass
			if (!measure.ending.includes(wanted)) {
				index++
				continue
			}
		} else if (index > 0 && measures[index - 1].ending && !jumped) {
			pass = 1
		}

		order.push(index)

		if (jumped && playUntil === 'fine' && marks[index].has('fine')) {
			break
		}
		if (jumped && playUntil === 'coda' && marks[index].has('tocoda') && codas.length > 0) {
			index = codas.find((coda) => coda > index) ?? codas[0]
			playUntil = 'end'
			continue
		}

		if (measure.repeatEnd !== null && !jumped && !playOnce.has(measure.number)) {
			const repeatAgain = measure.ending
				? pass < Math.max(measure.repeatEnd, groupMax[index])
				: (repeatsTaken.get(index) ?? 0) < measure.repeatEnd - 1
			if (repeatAgain) {
				repeatsTaken.set(index, (repeatsTaken.get(index) ?? 0) + 1)
				pass++
				index = repeatStart
				cameBack = true
				continue
			}
			if (!measure.ending) {
				pass = 1
			}
		}

		const jump = marks[index].has('dacapo') ? 'dacapo' : (marks[index].has('dalsegno') ? 'dalsegno' : null)
		if (jump && !jumpsTaken.has(index)) {
			jumpsTaken.add(index)
			if (jump === 'dalsegno' && segnos.length === 0) {
				warnings.push(`Measure ${measure.number}: D.S. without a segno is not played`)
				index++
				continue
			}
			const words = measure.words.join(' ').toLowerCase()
			if (CODA_WORD.test(words) || (hasToCoda && !words.includes('fine'))) {
				playUntil = 'coda'
			} else if (hasFine) {
				playUntil = 'fine'
			} else {
				playUntil = 'end'
			}
			jumped = true
			index = jump === 'dalsegno' ? segnos[0] : 0
			repeatStart = index
			pass = 1
			cameBack = true
			continue
		}
		index++
	}

	return { measures: order, warnings }
}

/**
 * Read a MusicXML score and compute the order in which its measures are played.
 *
 * @param source - Parsed MusicXML document or the content of an uncompressed MusicXML file
 * @param options - Playback options
 */
export function getPlaybackOrder(source: Document | string, options: PlaybackOrderOptions = {}): { measures: ScoreMeasure[], order: PlaybackOrder } {
	const measures = readScoreMeasures(typeof source === 'string' ? parseMusicXml(source) : source)
	return { measures, order: computePlaybackOrder(measures, options) }
}
