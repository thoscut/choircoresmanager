/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Compact score notation of the demo data and checks of a four-part setting.
//
// A score spec (tools/demo/scores/*.json) describes staves, voices and
// measure structure. Each voice is a string of measures separated by "|";
// a note is `<pitch>:<duration>` with sounding pitch (C4 = middle C), e.g.
// `Bb3:q`, `F#4:e.`, a rest is `r:q`. Durations: s, e, q, h, w, each
// optionally dotted (`q.`). Marks: a leading `(` starts a slur, a trailing
// `)` ends it, `~` ties to the next note, `^` adds a fermata.
//
// Lyrics are given per voice as verses: syllables separated by blanks, a
// trailing `-` joins a syllable to the next one, `*` leaves a note without a
// syllable in this verse. Notes inside a slur (after its first note) and
// tied continuations never get a syllable.

/** Divisions per quarter note in the generated MusicXML */
export const DIVISIONS = 4

const DURATIONS = {
	s: { divisions: 1, type: '16th', dots: 0 },
	e: { divisions: 2, type: 'eighth', dots: 0 },
	'e.': { divisions: 3, type: 'eighth', dots: 1 },
	q: { divisions: 4, type: 'quarter', dots: 0 },
	'q.': { divisions: 6, type: 'quarter', dots: 1 },
	h: { divisions: 8, type: 'half', dots: 0 },
	'h.': { divisions: 12, type: 'half', dots: 1 },
	w: { divisions: 16, type: 'whole', dots: 0 },
}

const STEP_SEMITONES = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 }
const ALTERS = { '': 0, '#': 1, '##': 2, b: -1, bb: -2 }
const NOTE = /^(\()?(r|[A-G](?:##|#|bb|b)?-?\d):(s|e\.?|q\.?|h\.?|w)([~^)]*)$/

/**
 * @param {string} text e.g. "F#4"
 * @returns {{ step: string, alter: number, octave: number, midi: number }}
 */
export function parsePitch(text) {
	const m = /^([A-G])(##|#|bb|b)?(-?\d)$/.exec(text)
	if (!m) {
		throw new Error(`Invalid pitch "${text}"`)
	}
	const step = m[1]
	const alter = ALTERS[m[2] ?? '']
	const octave = Number(m[3])
	return { step, alter, octave, midi: (octave + 1) * 12 + STEP_SEMITONES[step] + alter }
}

/**
 * @param {string} text duration code
 */
export function parseDuration(text) {
	const duration = DURATIONS[text]
	if (!duration) {
		throw new Error(`Invalid duration "${text}"`)
	}
	return duration
}

/** Length of a measure in divisions from a time signature like "3/4" */
export function measureLength(time) {
	const [beats, beatType] = time.split('/').map(Number)
	return beats * DIVISIONS * 4 / beatType
}

/** Length of a beat group for beaming: dotted quarter in compound time */
export function beatLength(time) {
	const [beats, beatType] = time.split('/').map(Number)
	if (beatType === 8 && beats % 3 === 0) {
		return DIVISIONS * 3 / 2
	}
	return DIVISIONS * 4 / beatType
}

/**
 * Parses one voice into measures of notes.
 *
 * @param {string} name voice name (for messages)
 * @param {string} text measures separated by "|"
 */
export function parseVoice(name, text) {
	const measures = text.split('|').map((measure) => measure.trim())
	let slurOpen = false
	let tiePending = null
	return measures.map((measure, index) => {
		const tokens = measure === '' ? [] : measure.split(/\s+/)
		return tokens.map((token) => {
			const m = NOTE.exec(token)
			if (!m) {
				throw new Error(`${name}, measure index ${index}: invalid note "${token}"`)
			}
			const marks = m[4]
			const rest = m[2] === 'r'
			const note = {
				token,
				rest,
				pitch: rest ? null : parsePitch(m[2]),
				...parseDuration(m[3]),
				slurStart: m[1] === '(',
				slurStop: marks.includes(')'),
				tieStart: marks.includes('~'),
				tieStop: false,
				fermata: marks.includes('^'),
				syllableNote: false,
			}
			if (tiePending !== null) {
				if (rest || tiePending.pitch.midi !== note.pitch.midi) {
					throw new Error(`${name}, measure index ${index}: tie from ${tiePending.token} to ${token} does not connect equal pitches`)
				}
				note.tieStop = true
			}
			tiePending = note.tieStart ? note : null
			if (note.slurStart && slurOpen) {
				throw new Error(`${name}, measure index ${index}: slur starts inside a slur at ${token}`)
			}
			note.syllableNote = !rest && !note.tieStop && !slurOpen
			if (note.slurStart) {
				slurOpen = true
			}
			if (note.slurStop) {
				if (!slurOpen) {
					throw new Error(`${name}, measure index ${index}: slur ends without a start at ${token}`)
				}
				slurOpen = false
			}
			return note
		})
	})
}

/**
 * Assigns the verses of a voice to its syllable-bearing notes.
 *
 * @param {Array<Array<object>>} measures parsed voice
 * @param {string[]} verses lyric lines
 * @returns {string[]} problems found
 */
export function attachLyrics(measures, verses) {
	const problems = []
	const notes = measures.flat().filter((note) => note.syllableNote)
	verses.forEach((verse, verseIndex) => {
		const tokens = verse.trim() === '' ? [] : verse.trim().split(/\s+/)
		if (tokens.length > notes.length) {
			problems.push(`verse ${verseIndex + 1}: ${tokens.length} syllables for ${notes.length} notes`)
		}
		let previousJoins = false
		tokens.forEach((token, i) => {
			const note = notes[i]
			if (!note || token === '*') {
				previousJoins = false
				return
			}
			const joins = token.endsWith('-')
			const text = joins ? token.slice(0, -1) : token
			const syllabic = previousJoins ? (joins ? 'middle' : 'end') : (joins ? 'begin' : 'single')
			note.lyrics ??= []
			note.lyrics.push({ number: verseIndex + 1, text: text.replace(/~/g, ' '), syllabic })
			previousJoins = joins
		})
	})
	return problems
}

/**
 * Parses a score spec into staves, voices and measures with timing.
 *
 * @param {object} spec score spec
 */
export function parseScore(spec) {
	const fullLength = measureLength(spec.time)
	const voices = {}
	const problems = []
	for (const [name, voice] of Object.entries(spec.voices)) {
		const measures = parseVoice(name, voice.notes)
		if (measures.length !== spec.measures.length) {
			throw new Error(`${spec.id}: voice ${name} has ${measures.length} measures, the structure ${spec.measures.length}`)
		}
		for (const problem of attachLyrics(measures, voice.lyrics ?? [])) {
			problems.push(`${name} ${problem}`)
		}
		voices[name] = { name, range: voice.range ?? null, measures }
	}
	let start = 0
	const measures = spec.measures.map((info, index) => {
		const length = info.length ? parseDuration(info.length).divisions : fullLength
		for (const voice of Object.values(voices)) {
			const sum = voice.measures[index].reduce((total, note) => total + note.divisions, 0)
			if (sum !== length) {
				throw new Error(`${spec.id}: voice ${voice.name}, measure ${info.number}: ${sum} divisions instead of ${length}`)
			}
			let onset = start
			for (const note of voice.measures[index]) {
				note.onset = onset
				note.offset = onset - start
				onset += note.divisions
			}
		}
		const measure = { ...info, index, start, length }
		start += length
		return measure
	})
	return { spec, voices, measures, problems }
}

const INTERVAL_NAMES = { 0: 'octave/unison', 7: 'fifth' }
const CHORDS = [
	[0, 4, 7], [0, 3, 7], [0, 3, 6], [0, 4, 8],
	[0, 4, 7, 10], [0, 3, 7, 10], [0, 3, 6, 10], [0, 3, 6, 9], [0, 4, 7, 11],
]

/** Whether a set of pitch classes is part of a common chord */
function isChord(pitchClasses) {
	const set = [...new Set(pitchClasses)]
	for (const root of set) {
		const intervals = set.map((pc) => (pc - root + 12) % 12)
		if (CHORDS.some((chord) => intervals.every((interval) => chord.includes(interval)))) {
			return true
		}
	}
	return false
}

/**
 * Checks the voice leading of a choral setting: parallel fifths and octaves
 * (also by contrary motion), direct octaves and fifths in the outer voices
 * reached by a leap of the top voice, voice crossing, spacing of the upper
 * voices, ranges, and sonorities on the beat that are no common chord.
 *
 * @param {ReturnType<typeof parseScore>} score parsed score
 * @param {string[]} order voice names from top to bottom
 * @returns {{ errors: string[], warnings: string[] }}
 */
export function checkVoiceLeading(score, order) {
	const errors = []
	const warnings = [...score.problems]
	const beat = beatLength(score.spec.time)
	const label = (onset) => {
		const measure = [...score.measures].reverse().find((m) => m.start <= onset)
		const offset = (onset - measure.start) / DIVISIONS
		return `m. ${measure.number} beat ${1 + offset}`
	}

	// Ranges
	for (const name of order) {
		const voice = score.voices[name]
		if (!voice.range) {
			continue
		}
		const [low, high] = voice.range.map((p) => parsePitch(p).midi)
		for (const note of voice.measures.flat()) {
			if (!note.rest && (note.pitch.midi < low || note.pitch.midi > high)) {
				errors.push(`${name} ${note.token} at ${label(note.onset)} outside ${voice.range.join('–')}`)
			}
		}
	}

	// Time slices at every onset: the sounding pitch of each voice
	const onsets = [...new Set(order.flatMap((name) => score.voices[name].measures.flat().map((note) => note.onset)))].sort((a, b) => a - b)
	const soundingAt = (name, onset) => score.voices[name].measures.flat().find((note) => note.onset <= onset && onset < note.onset + note.divisions)
	const slices = onsets.map((onset) => ({
		onset,
		notes: order.map((name) => soundingAt(name, onset)),
	}))

	// Playing order matters for parallels: a repeat leads back to its start.
	const sequence = []
	for (const measure of score.measures) {
		sequence.push(...slices.filter((slice) => slice.onset >= measure.start && slice.onset < measure.start + measure.length))
	}
	const pairs = []
	for (let i = 0; i < order.length; i++) {
		for (let j = i + 1; j < order.length; j++) {
			pairs.push([i, j])
		}
	}

	const transitions = []
	for (let k = 0; k + 1 < sequence.length; k++) {
		transitions.push([sequence[k], sequence[k + 1]])
	}
	// Repeats and endings: also check the jump back to the start
	for (const measure of score.measures) {
		if (measure.repeatEnd) {
			const back = [...score.measures].slice(0, measure.index).reverse().find((m) => m.repeatStart) ?? score.measures[0]
			const from = sequence.filter((slice) => slice.onset >= measure.start && slice.onset < measure.start + measure.length).at(-1)
			const to = sequence.find((slice) => slice.onset >= back.start)
			transitions.push([from, to])
		}
	}
	// Entering a second ending: from the measure before the first ending
	for (const measure of score.measures) {
		if (measure.ending && measure.ending.number !== '1' && measure.ending.start) {
			const first = [...score.measures].slice(0, measure.index).reverse().find((m) => m.ending?.number === '1' && m.ending.start)
			if (first) {
				const from = sequence.filter((slice) => slice.onset < first.start).at(-1)
				const to = sequence.find((slice) => slice.onset >= measure.start)
				transitions.push([from, to])
			}
		}
	}
	// Leaving the first ending forward is not a transition when it repeats
	const skipped = new Set()
	for (const measure of score.measures) {
		if (measure.repeatEnd) {
			const from = sequence.filter((slice) => slice.onset >= measure.start && slice.onset < measure.start + measure.length).at(-1)
			const to = sequence.find((slice) => slice.onset >= measure.start + measure.length)
			if (from && to) {
				skipped.add(`${from.onset}>${to.onset}`)
			}
		}
	}

	for (const [a, b] of transitions) {
		if (!a || !b || skipped.has(`${a.onset}>${b.onset}`)) {
			continue
		}
		for (const [i, j] of pairs) {
			const [hiA, loA, hiB, loB] = [a.notes[i], a.notes[j], b.notes[i], b.notes[j]]
			if (!hiA || !loA || !hiB || !loB || hiA.rest || loA.rest || hiB.rest || loB.rest) {
				continue
			}
			const ia = (hiA.pitch.midi - loA.pitch.midi) % 12
			const ib = (hiB.pitch.midi - loB.pitch.midi) % 12
			const moveHi = hiB.pitch.midi - hiA.pitch.midi
			const moveLo = loB.pitch.midi - loA.pitch.midi
			const where = `${order[i]}/${order[j]} ${label(a.onset)} → ${label(b.onset)}`
			if (moveHi !== 0 && moveLo !== 0 && ia === ib && (ia === 0 || ia === 7)) {
				const same = Math.sign(moveHi) === Math.sign(moveLo)
				const text = `${same ? 'parallel' : 'contrary'} ${INTERVAL_NAMES[ia]}s ${where}`
				if (same) {
					errors.push(text)
				} else {
					warnings.push(text)
				}
			}
			const outer = i === 0 && j === order.length - 1
			if (outer && moveHi !== 0 && Math.sign(moveHi) === Math.sign(moveLo) && (ib === 0 || ib === 7) && ia !== ib && Math.abs(moveHi) > 2) {
				warnings.push(`direct ${INTERVAL_NAMES[ib]} in the outer voices with a leap in the top voice ${where}`)
			}
		}
	}

	for (const slice of sequence) {
		const notes = slice.notes
		for (let i = 0; i + 1 < notes.length; i++) {
			const hi = notes[i]
			const lo = notes[i + 1]
			if (!hi || !lo || hi.rest || lo.rest) {
				continue
			}
			if (hi.pitch.midi < lo.pitch.midi) {
				warnings.push(`voice crossing ${order[i]}/${order[i + 1]} at ${label(slice.onset)}`)
			}
			if (i + 2 < notes.length && hi.pitch.midi - lo.pitch.midi > 12) {
				warnings.push(`spacing above an octave ${order[i]}/${order[i + 1]} at ${label(slice.onset)}`)
			}
		}
		const sounding = notes.filter((note) => note && !note.rest)
		const measure = [...score.measures].reverse().find((m) => m.start <= slice.onset)
		const onBeat = (slice.onset - measure.start) % beat === 0
		if (sounding.length >= 3 && !isChord(sounding.map((note) => note.pitch.midi % 12))) {
			const text = `no common chord at ${label(slice.onset)}: ${sounding.map((note) => note.token.replace(/[()~^]|:.*$/g, '')).join(' ')}`
			if (onBeat) {
				warnings.push(text)
			}
		}
	}
	return { errors, warnings }
}
