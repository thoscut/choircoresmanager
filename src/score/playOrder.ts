/**
 * The order in which measures sound: repeats, endings (volta brackets),
 * D.C./D.S. al Fine/Coda. Verovio's own expansion misses cases that occur
 * in the archive (a first ending without a second one, `times="3"`), so the
 * order is computed here from the MusicXML marks.
 *
 * Conventions (as MuseScore plays them):
 * - A backward repeat without a forward repeat goes back to the measure after
 *   the previous backward repeat, or to the beginning.
 * - Without `times`, a section is played twice, or as often as the highest
 *   ending number of the bracket group requires.
 * - An ending is played in the passes it is numbered for; in a pass not
 *   covered by any ending of the group, the whole group is skipped.
 * - After D.C./D.S. (and when playing without repeats), repeats are not
 *   taken; of each bracket group only the last ending that does not lead
 *   back is played. Fine ends the piece and "To Coda" jumps to the coda.
 */
import type { MeasureMarks } from './musicxml.ts'

interface EndingSpan {
	start: number
	end: number
	numbers: number[]
	/** index of the bracket group */
	group: number
}

interface EndingGroup {
	spans: EndingSpan[]
	end: number
	maxNumber: number
}

/**
 * Ending spans and bracket groups (consecutive endings).
 *
 * @param measures - structure marks
 */
function endingGroups(measures: MeasureMarks[]): { spanAt: Map<number, EndingSpan>, groups: EndingGroup[], groupOf: Map<number, EndingGroup> } {
	const spans: EndingSpan[] = []
	for (let i = 0; i < measures.length; i++) {
		const numbers = measures[i].endingStart
		if (numbers === null) {
			continue
		}
		let end = i
		while (end < measures.length - 1 && !measures[end].endingStop && measures[end + 1].endingStart === null) {
			end++
		}
		spans.push({ start: i, end, numbers, group: -1 })
		i = end
	}
	const groups: EndingGroup[] = []
	for (const span of spans) {
		const previous = groups[groups.length - 1]
		if (previous && previous.end === span.start - 1) {
			previous.spans.push(span)
			previous.end = span.end
			previous.maxNumber = Math.max(previous.maxNumber, ...span.numbers)
		} else {
			groups.push({ spans: [span], end: span.end, maxNumber: Math.max(0, ...span.numbers) })
		}
		span.group = groups.length - 1
	}
	const spanAt = new Map<number, EndingSpan>()
	const groupOf = new Map<number, EndingGroup>()
	for (const span of spans) {
		spanAt.set(span.start, span)
		for (let i = span.start; i <= span.end; i++) {
			groupOf.set(i, groups[span.group])
		}
	}
	return { spanAt, groups, groupOf }
}

/**
 * Measure indices in playing order.
 *
 * @param measures - structure marks of all measures
 * @param repeats - false: play straight through (no repeats or jumps, only
 *   the last ending of each bracket group)
 */
export function unfoldRepeats(measures: MeasureMarks[], repeats = true): number[] {
	const n = measures.length
	const { spanAt, groupOf } = endingGroups(measures)
	const groupEnds = new Set([...groupOf.values()].map((group) => group.end))
	const order: number[] = []
	let sectionStart = 0
	let pass = 1
	let jumped = !repeats
	let jumpUsed = false
	let cameBack = false
	let i = 0
	let guard = 0

	while (i < n && guard++ < 50 * n + 1000) {
		// Leaving a bracket group ends its repeat section
		if (i > 0 && groupEnds.has(i - 1) && !cameBack) {
			pass = 1
			sectionStart = i
		}
		const span = spanAt.get(i)
		if (span) {
			const group = groupOf.get(i)
			// Final pass: the last ending that does not lead back
			const finalSpan = [...(group?.spans ?? [span])].reverse().find((candidate) => !measures[candidate.end].repeatEnd)
			const take = jumped ? span === finalSpan : span.numbers.includes(pass)
			if (!take) {
				i = span.end + 1
				cameBack = false
				continue
			}
		}
		const marks = measures[i]
		if (marks.repeatStart && !cameBack) {
			sectionStart = i
			pass = 1
		}
		cameBack = false
		order.push(i)

		if (jumped && repeats && marks.fine) {
			break
		}
		if (jumped && repeats && marks.toCoda !== null) {
			const name = marks.toCoda
			let coda = findMark(measures, i + 1, (m) => m.coda !== null && m.coda === name)
			if (coda === -1) {
				coda = findMark(measures, i + 1, (m) => m.coda !== null)
			}
			if (coda !== -1) {
				i = coda
				continue
			}
		}
		if (marks.repeatEnd) {
			if (!jumped) {
				const group = groupOf.get(i)
				const times = marks.repeatTimes ?? Math.max(2, group?.maxNumber ?? 0)
				if (pass < times) {
					pass++
					i = sectionStart
					cameBack = true
					continue
				}
			}
			pass = 1
			sectionStart = i + 1
		}
		if (repeats && !jumpUsed && marks.daCapo) {
			jumped = true
			jumpUsed = true
			i = 0
			continue
		}
		if (repeats && !jumpUsed && marks.dalSegno !== null) {
			const target = findSegno(measures, i, marks.dalSegno)
			if (target !== -1) {
				jumped = true
				jumpUsed = true
				i = target
				continue
			}
		}
		i++
	}
	return order
}

function findMark(measures: MeasureMarks[], from: number, test: (marks: MeasureMarks) => boolean): number {
	for (let i = from; i < measures.length; i++) {
		if (test(measures[i])) {
			return i
		}
	}
	return -1
}

function findSegno(measures: MeasureMarks[], before: number, name: string): number {
	let fallback = -1
	for (let i = 0; i <= before; i++) {
		const segno = measures[i].segno
		if (segno !== null) {
			if (name !== '' && segno === name) {
				return i
			}
			if (fallback === -1) {
				fallback = i
			}
		}
	}
	return fallback
}
