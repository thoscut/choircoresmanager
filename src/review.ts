/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Review points of a transcription (milestone 0.6, see docs/api.md): where a
 * point is in the score (measures, voice), how it is labelled, sorted and
 * filtered, and which decisions an account may take. Pure functions, no Vue,
 * easy to test; the server checks every decision again.
 */
import type { MeasureRange, ScoreMeasure } from './score/range.ts'
import type { Voice } from './score/voices.ts'
import type { ReviewDecision, ReviewPermissions, ReviewPoint, ReviewStatus } from './types.ts'

import { t } from './l10n.ts'
import { resolveRange } from './score/range.ts'

export const REVIEW_STATUSES: readonly ReviewStatus[] = ['open', 'question', 'correct', 'confirmed', 'corrected', 'dismissed']

/** Statuses that still wait for someone: a decision, an answer or the tool chain. */
const UNRESOLVED: readonly string[] = ['open', 'question', 'correct']

/** Words for "no particular voice" in reports (German and English). */
const ALL_VOICES = new Set(['alle', 'all', 'tutti', 'alle stimmen', 'all voices', 'gesamt', 'chor', 'choir'])

/** Abbreviations and other names of voices => one name to compare. */
const VOICE_BASES: Record<string, string> = {
	s: 'sopran',
	sop: 'sopran',
	sopr: 'sopran',
	sopran: 'sopran',
	soprano: 'sopran',
	a: 'alt',
	alt: 'alt',
	alto: 'alt',
	t: 'tenor',
	ten: 'tenor',
	tenor: 'tenor',
	b: 'bass',
	bs: 'bass',
	bass: 'bass',
	basso: 'bass',
	bar: 'bariton',
	bari: 'bariton',
	bariton: 'bariton',
	baritone: 'bariton',
}

const ROMAN: Record<string, string> = { i: '1', ii: '2', iii: '3', iv: '4' }

/**
 * Normalized name of a voice for comparing, e.g. "T1", "T I", "Tenor 1",
 * "1. Tenor" and "TI" all give "tenor 1"; "B" gives "bass". Null for
 * "all voices" or an empty text.
 *
 * @param text - voice as written in a report or the score
 */
export function canonicalVoice(text: string | null | undefined): string | null {
	let value = (text ?? '').toLowerCase().replace(/ß/g, 'ss').replace(/[.]/g, ' ').replace(/\s+/g, ' ').trim()
	if (value === '' || ALL_VOICES.has(value)) {
		return null
	}
	// "1 tenor" → "tenor 1"
	const leading = value.match(/^(iv|i{1,3}|\d)\s*(\p{L}+)$/u)
	if (leading && VOICE_BASES[leading[2]] !== undefined) {
		value = `${leading[2]} ${leading[1]}`
	}
	const numbered = value.match(/^(\p{L}+?)\s*(iv|i{1,3}|\d)$/u)
	if (numbered && (VOICE_BASES[numbered[1]] !== undefined || /\s/.test(value))) {
		const base = VOICE_BASES[numbered[1]] ?? numbered[1]
		return `${base} ${ROMAN[numbered[2]] ?? numbered[2]}`
	}
	return VOICE_BASES[value] ?? value
}

/**
 * The part of a normalized name without its number ("tenor 1" → "tenor").
 *
 * @param canonical - result of canonicalVoice
 */
function baseOf(canonical: string): string {
	return canonical.replace(/ \d$/, '')
}

/**
 * The voice of the score a point names. Only a unique match counts: "T1"
 * finds "Tenor I" (also in "Tenor I/II"), "B" finds "Bass" but not one of
 * "Bass 1" and "Bass 2". A numbered name finds a voice without a number when
 * it is the only one of its kind ("T1" in a score with one tenor voice).
 *
 * @param voices - voices of the score
 * @param text - voice as written in the point
 * @param label - display name of a voice (e.g. "Tenor I/II, voice 2")
 */
export function matchVoice(voices: readonly Voice[], text: string | null | undefined, label?: (voice: Voice) => string): Voice | null {
	const wanted = canonicalVoice(text)
	if (wanted === null) {
		return null
	}
	const names = (voice: Voice): string[] => [voice.name, label?.(voice)]
		.map((name) => canonicalVoice(name))
		.filter((name): name is string => name !== null)
	const exact = voices.filter((voice) => names(voice).includes(wanted))
	if (exact.length === 1) {
		return exact[0]
	}
	if (exact.length > 1) {
		return null
	}
	const sameBase = voices.filter((voice) => names(voice).some((name) => baseOf(name) === baseOf(wanted)))
	return sameBase.length === 1 ? sameBase[0] : null
}

const MEASURE = /^[0-9A-Za-z.]{1,16}$/

/**
 * First and last measure of a text like "23", "12–14", "T. 12-14",
 * "Takt 5" or "12, 14"; null if it names no measure.
 *
 * @param text - measures as written
 */
export function parseMeasures(text: string | null | undefined): { from: string, to: string } | null {
	const value = (text ?? '').trim().replace(/^(?:t\.|takte?|mm?\.|bars?|measures?)\s*/i, '')
	const tokens = value.split(/\s*(?:–|—|-|,|;|\/|\bbis\b|\bto\b|\band\b|\bund\b)\s*/i)
		.map((token) => token.trim())
		.filter((token) => token !== '')
	if (tokens.length === 0 || !tokens.every((token) => MEASURE.test(token))) {
		return null
	}
	return { from: tokens[0], to: tokens[tokens.length - 1] }
}

/**
 * Printed measures of a point: measure_from/measure_to, or read from the
 * text of `measures`. Null when the point has no measures (material).
 *
 * @param point - review point
 */
export function pointMeasures(point: Pick<ReviewPoint, 'measure_from' | 'measure_to' | 'measures'>): { from: string, to: string } | null {
	const from = point.measure_from?.trim() || point.measure_to?.trim() || ''
	const to = point.measure_to?.trim() || point.measure_from?.trim() || ''
	if (from !== '' && to !== '') {
		return { from, to }
	}
	return parseMeasures(point.measures)
}

/**
 * Measure indices of a point in a score, or null when it has no measures
 * or names measures the score does not have.
 *
 * @param point - review point
 * @param measures - measures of the score
 */
export function pointRange(point: Pick<ReviewPoint, 'measure_from' | 'measure_to' | 'measures'>, measures: ScoreMeasure[]): MeasureRange | null {
	const printed = pointMeasures(point)
	if (printed === null) {
		return null
	}
	const range = resolveRange(measures, printed.from, printed.to)
	return 'error' in range ? null : range
}

/**
 * Whether a point lies in a range of measures (at least one measure in common).
 *
 * @param point - review point
 * @param measures - measures of the score
 * @param range - the range
 */
export function pointInRange(point: Pick<ReviewPoint, 'measure_from' | 'measure_to' | 'measures'>, measures: ScoreMeasure[], range: MeasureRange): boolean {
	const own = pointRange(point, measures)
	return own !== null && own.start <= range.end && own.end >= range.start
}

/**
 * Whether a point still waits for someone (decision, answer, tool chain).
 *
 * @param point - review point
 */
export function isUnresolved(point: Pick<ReviewPoint, 'status'>): boolean {
	return UNRESOLVED.includes(point.status)
}

/**
 * Whether a point waits for a decision of a person (open or a question).
 *
 * @param point - review point
 */
export function isOpen(point: Pick<ReviewPoint, 'status'>): boolean {
	return point.status === 'open' || point.status === 'question'
}

/**
 * Number of a printed measure for sorting ("12" → 12, "12a" → 12, none → ∞).
 *
 * @param measure - printed measure number
 */
function measureOrder(measure: string | null | undefined): number {
	const match = (measure ?? '').match(/^\d+/)
	return match ? Number(match[0]) : Number.MAX_SAFE_INTEGER
}

/**
 * Points in the order of work: those that wait first (open, questions,
 * correction orders), then the decided ones; each group by measure, points
 * without measures (material) last, then in the order they were created.
 *
 * @param points - review points
 */
export function sortPoints(points: readonly ReviewPoint[]): ReviewPoint[] {
	const group = (point: ReviewPoint): number => {
		const index = REVIEW_STATUSES.indexOf(point.status as ReviewStatus)
		return index === -1 ? REVIEW_STATUSES.length : index
	}
	return [...points].sort((a, b) => Number(!isUnresolved(a)) - Number(!isUnresolved(b))
		|| (isUnresolved(a) ? 0 : group(a) - group(b))
		|| measureOrder(pointMeasures(a)?.from) - measureOrder(pointMeasures(b)?.from)
		|| a.id - b.id)
}

export type PointFilter = 'open' | 'all'

/**
 * Points shown with a filter: those that still wait for someone, or all.
 *
 * @param points - review points
 * @param filter - 'open' or 'all'
 */
export function filterPoints(points: readonly ReviewPoint[], filter: PointFilter): ReviewPoint[] {
	return sortPoints(filter === 'all' ? points : points.filter(isUnresolved))
}

/**
 * Whether the account may decide a point: the catalog librarian decides
 * every point, the conductor the musical points (role "conductor").
 *
 * @param point - review point
 * @param may - permissions from the server
 */
export function mayDecide(point: Pick<ReviewPoint, 'role'>, may: ReviewPermissions): boolean {
	return point.role === 'librarian' ? may.decideMaterial : (may.decideMusical || may.decideMaterial)
}

/**
 * Decisions the account can take for a point, in the order of the form.
 * "corrected" is never offered: a correction in the app or a new version
 * sets it.
 *
 * @param point - review point
 * @param may - permissions from the server
 * @param tasksActive - whether questions and tasks are switched on
 */
export function allowedDecisions(point: Pick<ReviewPoint, 'role' | 'status'>, may: ReviewPermissions, tasksActive: boolean): ReviewDecision[] {
	if (!mayDecide(point, may)) {
		return []
	}
	const decisions: ReviewDecision[] = ['confirmed', 'correct']
	if (tasksActive) {
		decisions.push('question')
	}
	decisions.push('dismissed')
	if (point.status !== 'open') {
		decisions.push('open')
	}
	return decisions.filter((decision) => decision !== point.status)
}

/**
 * Label of a status.
 *
 * @param status - status key
 */
export function reviewStatusLabel(status: string): string {
	switch (status) {
	case 'open':
		return t('cantoria', 'Open')
	case 'confirmed':
		return t('cantoria', 'Reading is right')
	case 'correct':
		return t('cantoria', 'Correction order')
	case 'corrected':
		return t('cantoria', 'Corrected')
	case 'question':
		return t('cantoria', 'Question asked')
	case 'dismissed':
		return t('cantoria', 'Dismissed')
	}
	return status
}

/**
 * Label of the button or option that sets a status.
 *
 * @param decision - status to set
 */
export function decisionLabel(decision: ReviewDecision): string {
	switch (decision) {
	case 'confirmed':
		return t('cantoria', 'Reading is right')
	case 'correct':
		return t('cantoria', 'Correction order for the tool chain')
	case 'question':
		return t('cantoria', 'Ask a question')
	case 'dismissed':
		return t('cantoria', 'Dismiss')
	case 'open':
		return t('cantoria', 'Reopen')
	}
	return decision
}

/**
 * Label of a category.
 *
 * @param category - category key
 */
export function reviewCategoryLabel(category: string): string {
	switch (category) {
	case 'uncertain':
		return t('cantoria', 'Uncertain reading')
	case 'omitted':
		return t('cantoria', 'Not transcribed')
	case 'material':
		return t('cantoria', 'Material')
	case 'question':
		return t('cantoria', 'Question')
	}
	return category
}

/**
 * Where a point is, as text: "Measures 12 to 14, Bass", "Measure 23, all
 * voices" or "No place in the score".
 *
 * @param point - review point
 */
export function pointPlace(point: Pick<ReviewPoint, 'measure_from' | 'measure_to' | 'measures' | 'voice'>): string {
	const printed = pointMeasures(point)
	const voice = canonicalVoice(point.voice) === null ? t('cantoria', 'all voices') : (point.voice ?? '').trim()
	if (printed === null) {
		return point.voice && canonicalVoice(point.voice) !== null ? voice : t('cantoria', 'No place in the score')
	}
	const measures = printed.from === printed.to
		? t('cantoria', 'Measure {number}', { number: printed.from })
		: t('cantoria', 'Measures {from} to {to}', { from: printed.from, to: printed.to })
	return `${measures}, ${voice}`
}

/**
 * Counts for a short overview, e.g. "3 open · 1 correction order".
 *
 * @param points - review points
 */
export function countByStatus(points: readonly ReviewPoint[]): Record<string, number> {
	const counts: Record<string, number> = {}
	for (const point of points) {
		counts[point.status] = (counts[point.status] ?? 0) + 1
	}
	return counts
}
