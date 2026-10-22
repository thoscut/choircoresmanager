/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Writes a parsed score (see notation.mjs) as MusicXML 4.0 (partwise).
// Every staff is one part; two voices on a staff are MusicXML voices 1 and
// 2 with stems up and down. Pitches are sounding pitches; a tenor staff
// uses the treble clef with octave change.
import { DIVISIONS, beatLength } from './notation.mjs'

const SHARP_ORDER = ['F', 'C', 'G', 'D', 'A', 'E', 'B']
const FLAT_ORDER = ['B', 'E', 'A', 'D', 'G', 'C', 'F']
const ACCIDENTALS = { '-2': 'flat-flat', '-1': 'flat', 0: 'natural', 1: 'sharp', 2: 'double-sharp' }
const CLEFS = {
	treble: '<sign>G</sign><line>2</line>',
	'treble-8': '<sign>G</sign><line>2</line><clef-octave-change>-1</clef-octave-change>',
	bass: '<sign>F</sign><line>4</line>',
}

/** Escapes text for XML content and attributes */
export function xml(text) {
	return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/** Alteration of each step by the key signature */
function keyAlterations(fifths) {
	const alter = { C: 0, D: 0, E: 0, F: 0, G: 0, A: 0, B: 0 }
	if (fifths > 0) {
		SHARP_ORDER.slice(0, fifths).forEach((step) => { alter[step] = 1 })
	} else if (fifths < 0) {
		FLAT_ORDER.slice(0, -fifths).forEach((step) => { alter[step] = -1 })
	}
	return alter
}

/**
 * Marks the accidentals to print: per staff and measure, a note shows an
 * accidental when its alteration differs from the key signature or from an
 * earlier note on the same line and octave in this measure.
 */
function markAccidentals(voices, measureIndex, fifths) {
	const base = keyAlterations(fifths)
	const state = new Map()
	const notes = voices.flatMap((voice) => voice.measures[measureIndex]).filter((note) => !note.rest)
	notes.sort((a, b) => a.onset - b.onset)
	for (const note of notes) {
		const key = `${note.pitch.step}${note.pitch.octave}`
		const current = state.has(key) ? state.get(key) : base[note.pitch.step]
		note.accidental = note.pitch.alter !== current && !note.tieStop ? ACCIDENTALS[note.pitch.alter] : null
		state.set(key, note.pitch.alter)
	}
}

/** Beam values per note for one voice in one measure, grouped by beat */
function markBeams(notes, beat) {
	for (const note of notes) {
		note.beams = []
	}
	const groups = new Map()
	for (const note of notes) {
		if (note.rest || note.divisions >= DIVISIONS) {
			continue
		}
		const group = Math.floor(note.offset / beat)
		if (!groups.has(group)) {
			groups.set(group, [])
		}
		groups.get(group).push(note)
	}
	for (const group of groups.values()) {
		// only consecutive notes within the beat
		const runs = []
		for (const note of group) {
			const last = runs.at(-1)?.at(-1)
			if (last && last.offset + last.divisions === note.offset) {
				runs.at(-1).push(note)
			} else {
				runs.push([note])
			}
		}
		for (const run of runs) {
			if (run.length < 2) {
				continue
			}
			run.forEach((note, i) => {
				note.beams.push([1, i === 0 ? 'begin' : (i === run.length - 1 ? 'end' : 'continue')])
			})
			// second level for sixteenths
			let i = 0
			while (i < run.length) {
				if (run[i].divisions !== 1) {
					i++
					continue
				}
				let j = i
				while (j + 1 < run.length && run[j + 1].divisions === 1) {
					j++
				}
				if (j > i) {
					for (let k = i; k <= j; k++) {
						run[k].beams.push([2, k === i ? 'begin' : (k === j ? 'end' : 'continue')])
					}
				} else {
					run[i].beams.push([2, i === 0 ? 'forward hook' : 'backward hook'])
				}
				i = j + 1
			}
		}
	}
}

function noteXml(note, voiceNumber, stem) {
	const parts = ['<note>']
	if (note.rest) {
		parts.push('<rest/>')
	} else {
		const { step, alter, octave } = note.pitch
		parts.push(`<pitch><step>${step}</step>${alter !== 0 ? `<alter>${alter}</alter>` : ''}<octave>${octave}</octave></pitch>`)
	}
	parts.push(`<duration>${note.divisions}</duration>`)
	if (note.tieStop) {
		parts.push('<tie type="stop"/>')
	}
	if (note.tieStart) {
		parts.push('<tie type="start"/>')
	}
	parts.push(`<voice>${voiceNumber}</voice>`, `<type>${note.type}</type>`)
	for (let i = 0; i < note.dots; i++) {
		parts.push('<dot/>')
	}
	if (note.accidental) {
		parts.push(`<accidental>${note.accidental}</accidental>`)
	}
	if (stem && !note.rest) {
		parts.push(`<stem>${stem}</stem>`)
	}
	for (const [number, value] of note.beams ?? []) {
		parts.push(`<beam number="${number}">${value}</beam>`)
	}
	const notations = []
	if (note.tieStop) {
		notations.push('<tied type="stop"/>')
	}
	if (note.tieStart) {
		notations.push('<tied type="start"/>')
	}
	if (note.slurStart) {
		notations.push(`<slur type="start" number="1"${stem ? ` placement="${stem === 'up' ? 'above' : 'below'}"` : ''}/>`)
	}
	if (note.slurStop) {
		notations.push('<slur type="stop" number="1"/>')
	}
	if (note.fermata) {
		notations.push(`<fermata type="${stem === 'down' ? 'inverted' : 'upright'}"/>`)
	}
	if (notations.length > 0) {
		parts.push(`<notations>${notations.join('')}</notations>`)
	}
	for (const lyric of note.lyrics ?? []) {
		const extend = (lyric.syllabic === 'single' || lyric.syllabic === 'end') && (note.slurStart || note.tieStart) ? '<extend/>' : ''
		parts.push(`<lyric number="${lyric.number}"><syllabic>${lyric.syllabic}</syllabic><text>${xml(lyric.text)}</text>${extend}</lyric>`)
	}
	parts.push('</note>')
	return parts.join('')
}

function barlines(measure, isLast) {
	const left = []
	const right = []
	if (measure.repeatStart) {
		left.push('<bar-style>heavy-light</bar-style><repeat direction="forward"/>')
	}
	if (measure.ending?.start) {
		left.push(`<ending number="${measure.ending.number}" type="start">${measure.ending.number}.</ending>`)
	}
	const style = measure.repeatEnd || isLast ? 'light-heavy' : (measure.doubleBar ? 'light-light' : null)
	const stop = measure.ending?.stop ? `<ending number="${measure.ending.number}" type="${measure.ending.stop}"/>` : ''
	const repeat = measure.repeatEnd ? '<repeat direction="backward"/>' : ''
	if (style || stop || repeat) {
		right.push(`${style ? `<bar-style>${style}</bar-style>` : ''}${stop}${repeat}`)
	}
	return {
		left: left.length > 0 ? `<barline location="left">${left.join('')}</barline>` : '',
		right: right.length > 0 ? `<barline location="right">${right.join('')}</barline>` : '',
	}
}

function tempoXml(tempo) {
	const unit = `<beat-unit>${tempo.beatUnit}</beat-unit>${tempo.dots ? '<beat-unit-dot/>' : ''}`
	const quarterTempo = tempo.perMinute * (tempo.beatUnit === 'quarter' ? 1 : tempo.beatUnit === 'half' ? 2 : 0.5) * (tempo.dots ? 1.5 : 1)
	return '<direction placement="above">'
		+ (tempo.words ? `<direction-type><words font-weight="bold">${xml(tempo.words)}</words></direction-type>` : '')
		+ `<direction-type><metronome parentheses="no">${unit}<per-minute>${tempo.perMinute}</per-minute></metronome></direction-type>`
		+ `<sound tempo="${quarterTempo}"/></direction>`
}

function dynamicsXml(mark) {
	return `<direction placement="below"><direction-type><dynamics><${mark}/></dynamics></direction-type></direction>`
}

/**
 * @param {ReturnType<import('./notation.mjs').parseScore>} score parsed score
 * @param {{ software: string, date: string }} encoding
 * @returns {string} MusicXML document
 */
export function toMusicXml(score, encoding) {
	const { spec } = score
	const beat = beatLength(spec.time)
	const [beats, beatType] = spec.time.split('/')
	const out = []
	out.push('<?xml version="1.0" encoding="UTF-8" standalone="no"?>')
	out.push('<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">')
	out.push('<score-partwise version="4.0">')
	out.push(`<work><work-title>${xml(spec.title)}</work-title></work>`)
	out.push('<identification>')
	for (const [type, name] of [['composer', spec.composer], ['lyricist', spec.lyricist], ['arranger', spec.arranger]]) {
		if (name) {
			out.push(`<creator type="${type}">${xml(name)}</creator>`)
		}
	}
	out.push(`<rights>${xml(spec.rights)}</rights>`)
	out.push(`<encoding><software>${xml(encoding.software)}</software><encoding-date>${encoding.date}</encoding-date>`
		+ '<supports element="accidental" type="yes"/><supports element="beam" type="yes"/><supports element="stem" type="yes"/></encoding>')
	if (spec.source) {
		out.push(`<source>${xml(spec.source)}</source>`)
	}
	out.push('</identification>')

	// Page layout (A4 at 7 mm staff height) and title block
	out.push('<defaults><scaling><millimeters>7</millimeters><tenths>40</tenths></scaling>'
		+ '<page-layout><page-height>1697</page-height><page-width>1200</page-width>'
		+ '<page-margins type="both"><left-margin>85</left-margin><right-margin>85</right-margin>'
		+ '<top-margin>85</top-margin><bottom-margin>85</bottom-margin></page-margins></page-layout></defaults>')
	// Text and music in one right-aligned block: MuseScore places a separate
	// lyricist credit on the title line.
	const credits = [
		['title', spec.title, 600, 1612, 'center', 22],
		['subtitle', spec.subtitle, 600, 1555, 'center', 14],
		['composer', [spec.creditLeft, spec.creditRight].filter(Boolean).join('\n'), 1115, 1500, 'right', 10],
	]
	for (const [type, text, x, y, justify, size] of credits) {
		if (text) {
			out.push(`<credit page="1"><credit-type>${type}</credit-type><credit-words default-x="${x}" default-y="${y}" `
				+ `justify="${justify}" valign="top" font-size="${size}">${xml(text)}</credit-words></credit>`)
		}
	}

	out.push('<part-list>')
	out.push('<part-group type="start" number="1"><group-symbol>bracket</group-symbol><group-barline>yes</group-barline></part-group>')
	spec.staves.forEach((staff, i) => {
		const id = `P${i + 1}`
		out.push(`<score-part id="${id}"><part-name>${xml(staff.name)}</part-name>`
			+ (staff.abbreviation ? `<part-abbreviation>${xml(staff.abbreviation)}</part-abbreviation>` : '')
			+ `<score-instrument id="${id}-I1"><instrument-name>Choir Aahs</instrument-name></score-instrument>`
			+ `<midi-instrument id="${id}-I1"><midi-channel>${i + 1}</midi-channel><midi-program>53</midi-program><volume>80</volume><pan>0</pan></midi-instrument>`
			+ '</score-part>')
	})
	out.push('<part-group type="stop" number="1"/>')
	out.push('</part-list>')

	spec.staves.forEach((staff, staffIndex) => {
		const voices = staff.voices.map((name) => score.voices[name])
		const twoVoices = voices.length > 1
		out.push(`<part id="P${staffIndex + 1}">`)
		score.measures.forEach((measure, measureIndex) => {
			markAccidentals(voices, measureIndex, spec.key.fifths)
			const attrs = [`number="${measure.number}"`]
			if (measure.implicit) {
				attrs.push('implicit="yes"')
			}
			out.push(`<measure ${attrs.join(' ')}>`)
			if (measure.newSystem && measureIndex > 0) {
				out.push('<print new-system="yes"/>')
			}
			const { left, right } = barlines(measure, measureIndex === score.measures.length - 1)
			out.push(left)
			if (measureIndex === 0) {
				out.push(`<attributes><divisions>${DIVISIONS}</divisions><key><fifths>${spec.key.fifths}</fifths><mode>${spec.key.mode}</mode></key>`
					+ `<time><beats>${beats}</beats><beat-type>${beatType}</beat-type></time><clef>${CLEFS[staff.clef]}</clef></attributes>`)
				if (staffIndex === 0 && spec.tempo) {
					out.push(tempoXml(spec.tempo))
				}
			}
			if (measure.dynamics) {
				out.push(dynamicsXml(measure.dynamics))
			}
			voices.forEach((voice, voiceIndex) => {
				if (voiceIndex > 0) {
					out.push(`<backup><duration>${measure.length}</duration></backup>`)
				}
				const notes = voice.measures[measureIndex]
				markBeams(notes, beat)
				const stem = twoVoices ? (voiceIndex === 0 ? 'up' : 'down') : null
				for (const note of notes) {
					out.push(noteXml(note, voiceIndex + 1, stem))
				}
			})
			out.push(right)
			out.push('</measure>')
		})
		out.push('</part>')
	})
	out.push('</score-partwise>')
	return out.filter((line) => line !== '').join('\n') + '\n'
}
