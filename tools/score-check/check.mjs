// Checks the score logic of the app against MusicXML files, without a
// browser. It imports the same modules as the score view (src/score/*.ts;
// Node strips the types) and Verovio from node_modules.
//
// Usage:
//   node tools/score-check/check.mjs [--json] [--range=3-6] <file.mxl|file.musicxml> ...
//
// For every file it reports: loading, parts/staves/voices with their labels,
// printed measure numbers (first/last, pickup, duplicates), the playing order
// (own unfolding of repeats compared with Verovio's expansion), the range
// selection (resolved and actually rendered measures), MIDI pitches (own
// computation compared with Verovio) and the length of the event list.
import { readFileSync } from 'node:fs'
import { basename } from 'node:path'
import {
	loadScore,
	measureIdsInSvg,
	playingOrder,
	renderPages,
	selectMeasures,
	timelineFor,
	verovioOrder,
} from '../../src/score/model.ts'
import { readScoreText } from '../../src/score/mxl.ts'
import { duplicateNumbers, resolveRange } from '../../src/score/range.ts'
import { createToolkit } from '../../src/score/toolkit.ts'
import { voiceLabel } from '../../src/score/voices.ts'

const args = process.argv.slice(2)
const json = args.includes('--json')
const rangeArg = (args.find((arg) => arg.startsWith('--range=')) ?? '--range=3-6').slice('--range='.length)
const [rangeFrom, rangeTo] = rangeArg.split('-')
const files = args.filter((arg) => !arg.startsWith('--'))

if (files.length === 0) {
	console.error('Usage: node tools/score-check/check.mjs [--json] [--range=3-6] <file.mxl|file.musicxml> ...')
	process.exit(2)
}

/**
 * Playing order as runs of consecutive measures, in printed numbers.
 *
 * @param {number[]} order measure indices
 * @param {{ number: string }[]} measures all measures
 */
function runs(order, measures) {
	const parts = []
	let start = 0
	for (let i = 1; i <= order.length; i++) {
		if (i === order.length || order[i] !== order[i - 1] + 1) {
			const a = measures[order[start]].number
			const b = measures[order[i - 1]].number
			parts.push(a === b ? a : `${a}–${b}`)
			start = i
		}
	}
	return parts.join(', ')
}

const fallbackLabel = (part, number, staff) => `${part || `Staff ${staff}`}, voice ${number}`

async function check(file) {
	const result = { file: basename(file), ok: false }
	let toolkit = null
	try {
		const text = await readScoreText(readFileSync(file))
		toolkit = await createToolkit()
		const started = performance.now()
		const model = loadScore(toolkit, text)
		result.loadMs = Math.round(performance.now() - started)
		result.ok = true

		const staves = model.parts.reduce((sum, part) => sum + part.staves.length, 0)
		result.parts = model.parts.length
		result.staves = staves
		result.partNames = model.parts.map((part) => part.name)
		result.voices = model.voices.map((voice) => ({
			label: voiceLabel(voice, fallbackLabel),
			key: voice.key,
			notes: voice.notes,
		}))
		result.twoVoiceStaves = model.parts
			.flatMap((part) => part.staves)
			.filter((staff) => model.voices.filter((voice) => voice.members.some((member) => member.staff === staff)).length >= 2)
			.length

		const numbers = model.measures.map((measure) => measure.number)
		result.measures = model.measures.length
		result.firstNumber = numbers[0]
		result.lastNumber = numbers[numbers.length - 1]
		result.pickup = model.pickup
		result.implicit = model.measures.filter((measure) => measure.implicit).map((measure) => measure.number)
		result.duplicates = duplicateNumbers(model.measures)
		result.signatures = model.signatures
		result.tempo = model.tempo
		result.quarterTempo = model.quarterTempo

		// Verovio's own expansion of repeats, from a separate toolkit
		const other = await createToolkit()
		const verovio = verovioOrder(other, text)
		other.destroy()
		result.order = {
			source: model.orderSource,
			repeats: model.fullOrder.length !== model.measures.length,
			own: runs(model.fullOrder, model.measures),
			verovio: runs(verovio, model.measures),
			straight: runs(model.straightOrder, model.measures),
			sameAsVerovio: verovio.join(',') === model.fullOrder.join(','),
			length: model.fullOrder.length,
		}

		// Range selection: resolved indices and what Verovio actually renders
		const range = resolveRange(model.measures, rangeFrom, rangeTo)
		if ('error' in range) {
			result.range = { requested: rangeArg, error: range.error }
		} else {
			const expected = model.measures.slice(range.start, range.end + 1).map((measure) => measure.number)
			selectMeasures(toolkit, model, range)
			const rendered = renderPages(toolkit).flatMap(measureIdsInSvg)
			const numberOf = new Map(model.measures.map((measure) => [measure.id, measure.number]))
			const renderedNumbers = rendered.map((id) => numberOf.get(id) ?? `?${id}`)
			const timeline = timelineFor(model, playingOrder(model, range, false))
			result.range = {
				requested: rangeArg,
				resolved: expected,
				rendered: renderedNumbers,
				ok: expected.join(',') === renderedNumbers.join(','),
				playedMeasures: timeline.measures.map((slot) => model.measures[slot.index].number),
				events: timeline.events.length,
			}
		}
		selectMeasures(toolkit, model, null)
		const allRendered = renderPages(toolkit).flatMap(measureIdsInSvg).length
		result.fullRenderOk = allRendered === model.measures.length

		// Pitches: own computation from the MEI against Verovio's MIDI values
		let checked = 0
		const mismatches = []
		for (const notes of model.notesByMeasure) {
			for (const note of notes) {
				if (note.pitch === null) {
					continue
				}
				checked++
				const midi = toolkit.getMIDIValuesForElement(note.id)
				if (midi.pitch !== note.pitch) {
					mismatches.push(`${note.id}: ${note.pitch} vs ${midi.pitch}`)
				}
			}
		}
		result.pitch = { checked, mismatches: mismatches.length, examples: mismatches.slice(0, 5) }

		const full = timelineFor(model, model.fullOrder)
		const straight = timelineFor(model, model.straightOrder)
		result.timeline = {
			events: full.events.length,
			tiedContinuations: full.events.filter((event) => !event.sound).length,
			seconds: Math.round(full.duration),
			secondsWithoutRepeats: Math.round(straight.duration),
		}
	} catch (error) {
		result.error = error instanceof Error ? error.message : String(error)
	} finally {
		toolkit?.destroy()
	}
	return result
}

const results = []
for (const file of files) {
	results.push(await check(file))
}

if (json) {
	console.log(JSON.stringify(results, null, 1))
} else {
	const shelfmark = (name) => (name.match(/^(\d+)-0*(\d+)/) ?? []).slice(1, 3).join('-') || name
	console.log('| File | Load | Parts/staves | Voices | Measures (first–last) | Pickup | Repeats: own = Verovio | Range ' + rangeArg + ' | Pitches | Events | Duration |')
	console.log('|---|---|---|---|---|---|---|---|---|---|---|')
	for (const r of results) {
		if (!r.ok) {
			console.log(`| ${shelfmark(r.file)} | ERROR: ${r.error} | | | | | | | | | |`)
			continue
		}
		const range = r.range.error ? `n/a (${r.range.error})` : (r.range.ok ? 'ok' : 'MISMATCH')
		const repeats = r.order.repeats ? (r.order.sameAsVerovio ? 'yes, same' : 'yes, DIFFERENT') : 'none'
		console.log(`| ${shelfmark(r.file)} | ok (${r.loadMs} ms) | ${r.parts}/${r.staves} | ${r.voices.length} | ${r.measures} (${r.firstNumber}–${r.lastNumber}) | ${r.pickup ? 'yes' : 'no'} | ${repeats} | ${range} | ${r.pitch.mismatches === 0 ? 'ok' : r.pitch.mismatches + ' differ'} (${r.pitch.checked}) | ${r.timeline.events} | ${r.timeline.seconds} s |`)
	}
	for (const r of results) {
		console.log(`\n## ${r.file}`)
		if (!r.ok) {
			console.log(`  ERROR: ${r.error}`)
			continue
		}
		console.log(`  parts: ${r.partNames.map((name) => JSON.stringify(name)).join(', ')}`)
		console.log(`  voices: ${r.voices.map((voice) => `${voice.label} [${voice.key}, ${voice.notes}]`).join('; ')}`)
		console.log(`  measures: ${r.measures}, printed ${r.firstNumber}–${r.lastNumber}, pickup: ${r.pickup}, implicit: ${r.implicit.join(' ') || '-'}, duplicates: ${r.duplicates.join(' ') || '-'}`)
		console.log(`  key: ${r.signatures.fifths} ${r.signatures.mode}, time: ${r.signatures.time}, tempo: ${r.tempo ? `${r.tempo.beatUnit}${'.'.repeat(r.tempo.dots)} = ${r.tempo.perMinute}${r.tempo.printed ? '' : ' (editorial)'}` : '-'}, quarter = ${r.quarterTempo}`)
		if (r.order.repeats) {
			console.log(`  playing order (own):     ${r.order.own}`)
			if (!r.order.sameAsVerovio) {
				console.log(`  playing order (Verovio): ${r.order.verovio}`)
			}
			console.log(`  without repeats:         ${r.order.straight}`)
		}
		if (r.range.error) {
			console.log(`  range ${r.range.requested}: ${r.range.error}`)
		} else {
			console.log(`  range ${r.range.requested}: resolved ${r.range.resolved.join(' ')}; rendered ${r.range.rendered.join(' ')}; played ${r.range.playedMeasures.join(' ')} (${r.range.events} events)`)
		}
		console.log(`  full render: ${r.fullRenderOk ? 'all measures' : 'MISSING MEASURES'}; pitches: ${r.pitch.checked} checked, ${r.pitch.mismatches} differ ${r.pitch.examples.join(', ')}`)
		console.log(`  events: ${r.timeline.events} (${r.timeline.tiedContinuations} tied continuations), ${r.timeline.seconds} s with repeats, ${r.timeline.secondsWithoutRepeats} s without`)
	}
}
