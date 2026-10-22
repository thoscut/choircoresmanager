// Pure helpers turning Verovio output into voices and note events for playback

import type { VerovioTimemapEntry } from 'verovio/esm'

/**
 * A single voice (one layer of one staff), e.g. "Tenor 1"
 */
export interface Voice {
	key: string
	label: string
}

/**
 * A note to be played, with times in milliseconds at the score's original tempo
 */
export interface NoteEvent {
	id: string
	voiceKey: string
	pitch: number
	start: number
	duration: number
}

/**
 * Staff and layer a note belongs to, looked up by its xml:id
 */
export type NoteVoiceMap = Map<string, string>

/**
 * A measure of Verovio's MEI output
 */
export interface MeiMeasure {
	id: string
	/** The measure number, taken from the MusicXML measure's number */
	n: string
}

/**
 * Time span of a measure, in milliseconds at the score's original tempo
 */
export interface MeasureSpan {
	start: number
	end: number
}

const XML_ID = 'xml:id'

/**
 * @param mei - MEI document as returned by the Verovio toolkit
 */
export function parseMei(mei: string): Document {
	return new DOMParser().parseFromString(mei, 'application/xml')
}

/**
 * Read the voices of a score and the voice of every note from Verovio's MEI output.
 *
 * Each layer of each staff is one voice. Its label is the staff's label (the part
 * name from MusicXML), numbered when the staff contains more than one layer,
 * e.g. "Tenor 1" and "Tenor 2" for a TTBB score on two staves.
 *
 * @param doc - MEI document, see parseMei
 * @param fallbackLabel - Function creating a label for staves without a name
 */
export function extractVoices(doc: Document, fallbackLabel: (staffN: string) => string): { voices: Voice[], noteVoices: NoteVoiceMap } {
	const staffLabels = new Map<string, string>()
	for (const staffDef of Array.from(doc.getElementsByTagName('staffDef'))) {
		const n = staffDef.getAttribute('n')
		if (!n || staffLabels.has(n)) {
			continue
		}
		const labelElement = Array.from(staffDef.children).find((child) => child.localName === 'label')
		const label = (labelElement?.textContent ?? staffDef.getAttribute('label') ?? '').trim()
		staffLabels.set(n, label || fallbackLabel(n))
	}

	const layersPerStaff = new Map<string, Set<string>>()
	const noteVoices: NoteVoiceMap = new Map()
	for (const note of Array.from(doc.getElementsByTagName('note'))) {
		const id = note.getAttribute(XML_ID)
		const layer = note.closest('layer')
		const staff = note.closest('staff')
		if (!id || !layer || !staff) {
			continue
		}
		const staffN = staff.getAttribute('n') ?? '1'
		const layerN = layer.getAttribute('n') ?? '1'
		if (!layersPerStaff.has(staffN)) {
			layersPerStaff.set(staffN, new Set())
		}
		layersPerStaff.get(staffN)!.add(layerN)
		noteVoices.set(id, `${staffN}.${layerN}`)
	}

	const voices: Voice[] = []
	const sortedStaves = [...layersPerStaff.keys()].sort((a, b) => Number(a) - Number(b))
	for (const staffN of sortedStaves) {
		const layers = [...layersPerStaff.get(staffN)!].sort((a, b) => Number(a) - Number(b))
		const staffLabel = staffLabels.get(staffN) ?? fallbackLabel(staffN)
		layers.forEach((layerN, index) => {
			voices.push({
				key: `${staffN}.${layerN}`,
				label: layers.length > 1 ? `${staffLabel} ${index + 1}` : staffLabel,
			})
		})
	}

	return { voices, noteVoices }
}

/**
 * Read the measures of Verovio's MEI output in score order
 *
 * @param doc - MEI document, see parseMei
 */
export function extractMeasures(doc: Document): MeiMeasure[] {
	return Array.from(doc.getElementsByTagName('measure')).map((measure) => ({
		id: measure.getAttribute(XML_ID) ?? '',
		n: measure.getAttribute('n') ?? '',
	}))
}

/**
 * Compute the time span of every measure from the measure onsets in Verovio's timemap.
 *
 * The timemap must be rendered without expansion (Verovio option expandNever) and with
 * includeMeasures and includeRests, so that each measure appears once and the last entry
 * marks the end of the score. Measures missing from the timemap, e.g. the measures Verovio
 * merges into a multi-measure rest, get an empty span.
 *
 * @param measures - The measures of the MEI output, see extractMeasures
 * @param timemap - Timemap as returned by the Verovio toolkit
 * @return The spans in the order of the measures, or null if the timemap has no measure onsets
 */
export function measureSpansFromTimemap(measures: MeiMeasure[], timemap: VerovioTimemapEntry[]): MeasureSpan[] | null {
	const onsets = new Map<string, number>()
	let end = 0
	for (const entry of timemap) {
		if (entry.measureOn && !onsets.has(entry.measureOn)) {
			onsets.set(entry.measureOn, entry.tstamp)
		}
		end = Math.max(end, entry.tstamp)
	}
	if (onsets.size === 0) {
		return null
	}

	const spans: MeasureSpan[] = new Array(measures.length)
	let following = end
	for (let index = measures.length - 1; index >= 0; index--) {
		const start = Math.min(onsets.get(measures[index].id) ?? following, following)
		spans[index] = { start, end: following }
		following = start
	}
	return spans
}

/**
 * Find the MEI measure of every MusicXML measure.
 *
 * Verovio creates one MEI measure per MusicXML measure, except for multi-measure rests, which
 * become a single measure. If the counts differ, the measures are matched by their numbers.
 *
 * @param numbers - Numbers of the MusicXML measures in score order
 * @param measures - The measures of the MEI output, see extractMeasures
 * @return Index of the MEI measure for each MusicXML measure, null for measures without one
 */
export function matchMeasureNumbers(numbers: string[], measures: MeiMeasure[]): (number | null)[] {
	if (numbers.length === measures.length) {
		return numbers.map((_, index) => index)
	}
	let next = 0
	return numbers.map((number) => {
		for (let index = next; index < measures.length; index++) {
			if (measures[index].n === number) {
				next = index + 1
				return index
			}
		}
		return null
	})
}

/**
 * Build the list of notes to play from Verovio's timemap.
 *
 * Start times come from the timemap, which is also used for highlighting.
 * Pitch and duration come from Verovio's MIDI values, which already merge tied notes.
 *
 * @param timemap - Timemap as returned by the Verovio toolkit
 * @param noteVoices - Voice of each note
 * @param getMidiValues - Function returning MIDI pitch and duration (ms) of a note, or null if it has none
 */
export function buildNoteEvents(
	timemap: VerovioTimemapEntry[],
	noteVoices: NoteVoiceMap,
	getMidiValues: (id: string) => { pitch: number, duration: number } | null,
): NoteEvent[] {
	const events: NoteEvent[] = []
	for (const entry of timemap) {
		for (const id of entry.on ?? []) {
			const voiceKey = noteVoices.get(id)
			const values = getMidiValues(id)
			if (voiceKey === undefined || values === null || values.pitch <= 0 || values.duration <= 0) {
				continue
			}
			events.push({ id, voiceKey, pitch: values.pitch, start: entry.tstamp, duration: values.duration })
		}
	}
	return events.sort((a, b) => a.start - b.start)
}

/**
 * Parse Verovio SVG output into an element safe to insert into the page.
 *
 * Verovio escapes text coming from the score, but the file comes from an upload,
 * so scripts, foreign content, event handlers and script URLs are removed anyway.
 *
 * @param svg - SVG markup
 */
export function sanitizeSvg(svg: string): SVGSVGElement | null {
	const doc = new DOMParser().parseFromString(svg, 'image/svg+xml')
	const root = doc.documentElement
	if (root.localName !== 'svg' || doc.getElementsByTagName('parsererror').length > 0) {
		return null
	}

	for (const element of Array.from(root.querySelectorAll('script, foreignObject, iframe, object, embed'))) {
		element.remove()
	}
	for (const element of [root, ...Array.from(root.querySelectorAll('*'))]) {
		for (const attribute of Array.from(element.attributes)) {
			const name = attribute.name.toLowerCase()
			const value = attribute.value.trim().toLowerCase()
			if (name.startsWith('on') || ((name === 'href' || name === 'xlink:href') && !value.startsWith('#'))) {
				element.removeAttribute(attribute.name)
			}
		}
	}

	return document.importNode(root, true) as unknown as SVGSVGElement
}
