/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Everything the score view knows about one file, computed once after
 * loading: measures with printed numbers, voices, note timings and the
 * playing order. Used by the UI and by tools/score-check alike.
 */
import type { MeiNote, MeiScore, MeiTie } from './mei.ts'
import type { MusicXmlStructure, Signatures, TempoMark } from './musicxml.ts'
import type { MeasureRange, ScoreMeasure } from './range.ts'
import type { LinearTiming, TimemapEntry, Timeline } from './timeline.ts'
import type { VerovioToolkit } from './toolkit.ts'
import type { PartStaves, Voice } from './voices.ts'

import { parseMei } from './mei.ts'
import { parseMusicXml } from './musicxml.ts'
import { unfoldRepeats } from './playOrder.ts'
import { hasPickup, orderInRange } from './range.ts'
import { buildTimeline, linearTiming } from './timeline.ts'
import { buildVoices, memberKey } from './voices.ts'

/** Verovio options for loading and rendering. */
export const VEROVIO_OPTIONS = {
	// always read the file as MusicXML: Verovio would otherwise guess the
	// format from the content (e.g. SVG, MEI or Humdrum)
	inputFrom: 'musicxml',
	header: 'none',
	footer: 'none',
	breaks: 'auto',
	adjustPageHeight: true,
	pageHeight: 60000,
	pageMarginTop: 30,
	pageMarginBottom: 30,
	pageMarginLeft: 30,
	pageMarginRight: 30,
	// ids in data-id (no clash with ids of the page), staff and layer number on their <g>;
	// the staff of control events and the start note of slurs and ties, so that
	// they follow their voice when a focus voice is chosen (emphasis.ts)
	svgHtml5: true,
	svgAdditionalAttribute: [
		'staff@n',
		'layer@n',
		...['dir', 'dynam', 'hairpin', 'fermata', 'breath', 'trill', 'mordent', 'turn', 'octave'].map((name) => `${name}@staff`),
		'slur@startid',
		'tie@startid',
	],
	// the timemap stays linear; the playing order is computed by playOrder.ts
	expandNever: true,
	// ids derived from the file content: the same file version gives the same ids
	xmlIdChecksum: true,
	// music symbols in texts: font embedded in the SVG, never linked from verovio.org
	smuflTextFont: 'embedded',
}

export interface ScoreModel {
	measures: ScoreMeasure[]
	parts: PartStaves[]
	voices: Voice[]
	notesByMeasure: MeiNote[][]
	ties: MeiTie[]
	timing: LinearTiming
	/** playing order of the whole piece with repeats */
	fullOrder: number[]
	/** playing order without repeats (last ending of each bracket group) */
	straightOrder: number[]
	/** 'musicxml': own playing order; 'verovio': fallback to Verovio's expansion of repeats */
	orderSource: 'musicxml' | 'verovio'
	pickup: boolean
	signatures: Signatures
	/** metronome marking from the file */
	tempo: TempoMark | null
	/** tempo Verovio plays at the beginning (quarter notes per minute) */
	quarterTempo: number | null
	/** false: the file gives no tempo and Verovio's default is used */
	tempoInFile: boolean
	noteCount: number
}

/**
 * Parts with their MEI staff numbers: from the MusicXML part list if the
 * staff counts agree with Verovio, otherwise from the MEI labels.
 *
 * @param structure - MusicXML structure
 * @param mei - parsed MEI
 */
function partStaves(structure: MusicXmlStructure, mei: MeiScore): PartStaves[] {
	const total = structure.parts.reduce((sum, part) => sum + part.staves, 0)
	if (total > 0 && total === mei.staves.length) {
		let next = 0
		return structure.parts.map((part) => ({
			name: part.name,
			staves: Array.from({ length: part.staves }, () => mei.staves[next++].n),
		}))
	}
	const parts: PartStaves[] = []
	for (const staff of mei.staves) {
		const last = parts[parts.length - 1]
		if (last && staff.label !== '' && last.name === staff.label) {
			last.staves.push(staff.n)
		} else {
			parts.push({ name: staff.label, staves: [staff.n] })
		}
	}
	return parts
}

/**
 * Verovio's own playing order (its expansion of repeats), as measure
 * indices. Verovio prepares the expansion while loading, so this loads the
 * file into the toolkit with expansion enabled; load it again with
 * `VEROVIO_OPTIONS` before rendering.
 *
 * @param toolkit - a toolkit
 * @param text - MusicXML text
 */
export function verovioOrder(toolkit: VerovioToolkit, text: string): number[] {
	toolkit.setOptions({ ...VEROVIO_OPTIONS, expandNever: false })
	if (!toolkit.loadData(text)) {
		return []
	}
	const indexOf = new Map(parseMei(toolkit.getMEI()).measures.map((measure, index) => [measure.id, index]))
	const timemap = toolkit.renderToTimemap({ includeMeasures: true }) as TimemapEntry[]
	const order: number[] = []
	for (const entry of timemap) {
		const index = entry.measureOn ? indexOf.get(entry.measureOn.replace(/-rend\d+$/, '')) : undefined
		if (index !== undefined) {
			order.push(index)
		}
	}
	return order
}

/**
 * Load MusicXML into the toolkit and analyse it.
 *
 * @param toolkit - a fresh toolkit
 * @param text - MusicXML text
 */
export function loadScore(toolkit: VerovioToolkit, text: string): ScoreModel {
	const structure = parseMusicXml(text)
	toolkit.setOptions(VEROVIO_OPTIONS)
	if (!toolkit.loadData(text)) {
		throw new Error('Verovio could not read the MusicXML file.')
	}
	const mei = parseMei(toolkit.getMEI())
	if (mei.measures.length === 0) {
		throw new Error('The file contains no measures.')
	}
	const timemap = toolkit.renderToTimemap({ includeMeasures: true }) as TimemapEntry[]

	const sameMeasures = structure.measures.length === mei.measures.length
	let fullOrder: number[] = []
	if (sameMeasures) {
		fullOrder = unfoldRepeats(structure.measures, true)
	} else {
		// Fallback: Verovio's expansion; then load again for rendering (same ids)
		fullOrder = verovioOrder(toolkit, text)
		toolkit.setOptions(VEROVIO_OPTIONS)
		toolkit.loadData(text)
	}
	const measures: ScoreMeasure[] = mei.measures.map((measure, index) => ({
		index,
		id: measure.id,
		number: sameMeasures ? structure.measures[index].number : measure.n,
		implicit: sameMeasures ? structure.measures[index].implicit : false,
	}))

	const notesByMeasure: MeiNote[][] = measures.map(() => [])
	const counts = new Map<string, number>()
	for (const note of mei.notes) {
		notesByMeasure[note.measure]?.push(note)
		const key = memberKey(note.staff, note.layer)
		counts.set(key, (counts.get(key) ?? 0) + 1)
	}
	const parts = partStaves(structure, mei)
	const timing = linearTiming(timemap, measures.map((measure) => measure.id))

	return {
		measures,
		parts,
		voices: buildVoices(parts, counts),
		notesByMeasure,
		ties: mei.ties,
		timing,
		fullOrder: fullOrder.length > 0 ? fullOrder : measures.map((measure) => measure.index),
		straightOrder: sameMeasures ? unfoldRepeats(structure.measures, false) : measures.map((measure) => measure.index),
		orderSource: sameMeasures ? 'musicxml' : 'verovio',
		pickup: hasPickup(measures),
		signatures: structure.signatures,
		tempo: structure.tempo,
		quarterTempo: timing.tempo ?? structure.soundTempo,
		tempoInFile: structure.tempo !== null || structure.soundTempo !== null,
		noteCount: mei.notes.length,
	}
}

/**
 * Playing order for the whole piece or a range.
 *
 * @param model - the score
 * @param range - measure range or null for the whole piece
 * @param repeats - whether to take repeats
 */
export function playingOrder(model: ScoreModel, range: MeasureRange | null, repeats: boolean): number[] {
	if (range === null) {
		return repeats ? model.fullOrder : model.straightOrder
	}
	return orderInRange(model.fullOrder, range, repeats)
}

/**
 * Events for a playing order.
 *
 * @param model - the score
 * @param order - measure indices in playing order
 */
export function timelineFor(model: ScoreModel, order: number[]): Timeline {
	return buildTimeline(order, model.timing, model.notesByMeasure, model.ties)
}

/**
 * What sounds for a range with or without repeats: the events of the
 * playback in the score view and of the practice audio files alike, so that
 * an exported file sounds exactly like playing the same choice.
 *
 * @param model - the score
 * @param range - measure range or null for the whole piece
 * @param repeats - whether to take repeats
 */
export function playbackTimeline(model: ScoreModel, range: MeasureRange | null, repeats: boolean): Timeline {
	return timelineFor(model, playingOrder(model, range, repeats))
}

/**
 * Show only a range of measures (or all) in the rendered pages.
 *
 * @param toolkit - toolkit with the score loaded
 * @param model - the score
 * @param range - measure range or null for all measures
 */
export function selectMeasures(toolkit: VerovioToolkit, model: ScoreModel, range: MeasureRange | null): void {
	const first = model.measures[range?.start ?? 0]
	const last = model.measures[range?.end ?? model.measures.length - 1]
	toolkit.select({ start: first.id, end: last.id })
	toolkit.redoLayout()
	if (range !== null) {
		// For a part of the score Verovio knows the widths of the lyric
		// syllables only after a first pass; without the second pass it puts
		// too many measures on a narrow line and the syllables overlap.
		toolkit.redoLayout()
	}
}

/**
 * SVG of all pages of the current layout.
 *
 * @param toolkit - toolkit with the score loaded and laid out
 */
export function renderPages(toolkit: VerovioToolkit): string[] {
	const pages: string[] = []
	const count = toolkit.getPageCount()
	for (let page = 1; page <= count; page++) {
		pages.push(toolkit.renderToSVG(page, false))
	}
	return pages
}

/**
 * Ids of the measures in rendered SVG, in order.
 *
 * @param svg - SVG text rendered with `svgHtml5`
 */
export function measureIdsInSvg(svg: string): string[] {
	return [...svg.matchAll(/<g data-id="([^"]+)" data-class="measure"/g)].map((match) => match[1])
}
