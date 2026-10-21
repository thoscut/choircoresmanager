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

const XML_ID = 'xml:id'

/**
 * Read the voices of a score and the voice of every note from Verovio's MEI output.
 *
 * Each layer of each staff is one voice. Its label is the staff's label (the part
 * name from MusicXML), numbered when the staff contains more than one layer,
 * e.g. "Tenor 1" and "Tenor 2" for a TTBB score on two staves.
 *
 * @param mei - MEI document as returned by the Verovio toolkit
 * @param fallbackLabel - Function creating a label for staves without a name
 */
export function extractVoices(mei: string, fallbackLabel: (staffN: string) => string): { voices: Voice[], noteVoices: NoteVoiceMap } {
	const doc = new DOMParser().parseFromString(mei, 'application/xml')

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
