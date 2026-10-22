#!/usr/bin/env node
// Builds the public demo data of the Notenarchiv app from tools/demo/catalog.json
// and tools/demo/scores/*.json (see tools/demo/README.md):
//
//   build/demo/Notenarchiv-Demo/            archive folder to upload to Nextcloud
//     Mappe_1 … Mappe_4/                    placeholder PDFs and MusicXML files
//     LIESMICH.txt                          note for people who open the folder
//     _Import/                              CSV files for the imports (skipped by the file sync)
//
// Usage:
//   node tools/demo/build.mjs [--out=build/demo] [--check]
//
// --check only parses the scores and the catalog and reports the checks.
// The output is reproducible: the same sources give byte-identical files.
import { mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { writeCsv } from './lib/csv.mjs'
import { toMusicXml } from './lib/musicxml.mjs'
import { checkVoiceLeading, parseScore } from './lib/notation.mjs'
import { placeholderPdf } from './lib/pdf.mjs'
import { mxl } from './lib/zip.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../..')
const args = process.argv.slice(2)
const out = resolve(root, (args.find((arg) => arg.startsWith('--out=')) ?? '--out=build/demo').slice('--out='.length))
const checkOnly = args.includes('--check')

const catalog = JSON.parse(readFileSync(join(here, 'catalog.json'), 'utf8'))
const ENCODING = { software: 'Notenarchiv demo data (tools/demo/build.mjs)', date: catalog.date }

const problems = []
const fail = (message) => problems.push(message)

// ---------------------------------------------------------------------------
// Column layout and allowed values, read from the app itself

const catalogCsvPhp = readFileSync(join(root, 'lib/Service/CatalogCsv.php'), 'utf8')
const fieldsPhp = readFileSync(join(root, 'lib/Service/Fields.php'), 'utf8')

/** The block of a PHP constant (array literal) */
function phpConstant(source, name) {
	const match = new RegExp(`const ${name} = \\[([\\s\\S]*?)\\n\\t\\];`).exec(source)
	if (!match) {
		throw new Error(`Constant ${name} not found in the PHP source`)
	}
	return match[1]
}

/** Legacy catalog columns in their order: [header, level, field] */
const LEGACY_COLUMNS = [...phpConstant(catalogCsvPhp, 'LEGACY_COLUMNS')
	.matchAll(/'([^']+)' => \[Fields::(WORK|VERSION|COPY), '([a-z_]+)'\]/g)]
	.map(([, header, level, field]) => [header, level.toLowerCase(), field])
const LEGACY_RIGHTS_STATUS = [...phpConstant(catalogCsvPhp, 'LEGACY_RIGHTS_STATUS').matchAll(/'([^']+)' => '([a-z_]+)'/g)]
	.map(([, legacy]) => legacy)
const choiceKeys = (name) => [...phpConstant(fieldsPhp, name).matchAll(/'([a-z_0-9]+)' => '/g)].map(([, key]) => key)
const CHOICES = {
	'copy.status': choiceKeys('COPY_STATUS'),
	'copy.kind': choiceKeys('COPY_KIND'),
	'version.kind': choiceKeys('VERSION_KIND'),
	'version.rule1_status': choiceKeys('RULE1_STATUS'),
}
if (LEGACY_COLUMNS.length !== 31) {
	fail(`expected 31 legacy columns in CatalogCsv.php, found ${LEGACY_COLUMNS.length}`)
}

// ---------------------------------------------------------------------------
// Scores

function loadScores() {
	const scores = new Map()
	for (const name of readdirSync(join(here, 'scores')).filter((file) => file.endsWith('.json')).sort()) {
		const spec = JSON.parse(readFileSync(join(here, 'scores', name), 'utf8'))
		const score = parseScore(spec)
		const order = spec.staves.flatMap((staff) => staff.voices)
		const { errors, warnings } = checkVoiceLeading(score, order)
		console.log(`score ${spec.id}: ${score.measures.length} measures, voices ${order.join(' ')}; ${errors.length} errors, ${warnings.length} notes`)
		for (const error of errors) {
			fail(`${spec.id}: ${error}`)
		}
		for (const warning of warnings) {
			console.log(`  note  ${warning}`)
		}
		scores.set(spec.id, score)
	}
	return scores
}

// ---------------------------------------------------------------------------
// Catalog

const pad = (number) => String(number).padStart(2, '0')

/** Binder, number and file names of a copy */
function describeCopy(copy) {
	const match = /^(\d+)-(\d+)$/.exec(copy.shelfmark)
	if (!match) {
		fail(`invalid shelf mark ${copy.shelfmark}`)
		return null
	}
	const binder = Number(match[1])
	const number = Number(match[2])
	const base = `${binder}-${pad(number)}_${copy.fileStem}`
	return {
		binder,
		number,
		folder: `Mappe_${binder}`,
		pdf: copy.noFile ? null : `${base}.pdf`,
		score: copy.score ? `${base}.${copy.scoreFormat}` : null,
	}
}

/** The 31 legacy cells of a copy */
function legacyRow(copy, files) {
	const values = {
		...copy,
		binder: String(files.binder),
		number: String(files.number),
		pdf_files: files.pdf ?? '',
		scan_files: files.pdf ?? '',
		musicxml_file: files.score ?? '',
		musicxml_valid: files.score ?? '',
		musicxml_date: files.score ? catalog.date : '',
		musicxml_origin: !files.score
			? ''
			: copy.shelfmark === '3-1'
				? 'Fiktive Bearbeitung des Demo-Bestands; die Datei ist ein eigener Satz unter CC0, erzeugt mit tools/demo/build.mjs'
				: `Eigener Satz für den Demo-Bestand (CC0), erzeugt mit tools/demo/build.mjs aus scores/${copy.score}.json`,
		musicxml_review: files.score
			? 'Satzregeln automatisch geprüft (Parallelen, Stimmumfang, Stimmabstand); tools/score-check: Laden, Takte, Stimmen und Ablauf in Ordnung'
			: '',
	}
	return LEGACY_COLUMNS.map(([header, , field]) => {
		const value = values[field]
		if (value === undefined) {
			fail(`${copy.shelfmark}: no value for column "${header}" (${field})`)
			return ''
		}
		if (/^\s|\s$/.test(value)) {
			fail(`${copy.shelfmark}: leading or trailing blank in "${header}"`)
		}
		return value
	})
}

/** Year until which a person's works are protected: end of the 70th year after death */
function protectedUntil(lifeDates) {
	if (/lebend|living/i.test(lifeDates)) {
		return ''
	}
	const match = /[-–]\s*(?:\d{1,2}\.\d{1,2}\.)?(\d{4})\s*$/.exec(lifeDates)
	return match ? `31.12.${Number(match[1]) + 70}` : ''
}

function checkCatalog() {
	const shelfmarks = new Set()
	for (const copy of catalog.copies) {
		if (shelfmarks.has(copy.shelfmark)) {
			fail(`shelf mark ${copy.shelfmark} occurs twice`)
		}
		shelfmarks.add(copy.shelfmark)
		if (!LEGACY_RIGHTS_STATUS.includes(copy.rights_status)) {
			fail(`${copy.shelfmark}: rights status "${copy.rights_status}" is not one of ${LEGACY_RIGHTS_STATUS.join(', ')}`)
		}
		for (const [column, value] of Object.entries(copy.extended ?? {})) {
			if (CHOICES[column] && !CHOICES[column].includes(value)) {
				fail(`${copy.shelfmark}: ${column} "${value}" is not one of ${CHOICES[column].join(', ')}`)
			}
		}
		if ((copy.extended?.['copy.status'] ?? 'active') !== 'active' && !copy.extended['copy.status_reason']) {
			fail(`${copy.shelfmark}: a status other than active needs a reason`)
		}
	}
	const known = (mark) => shelfmarks.has(mark)
	for (const person of catalog.persons) {
		for (const mark of person.shelfmarks.split(/\s+/)) {
			if (!known(mark)) {
				fail(`person ${person.name}: unknown shelf mark ${mark}`)
			}
		}
	}
	// Programme lines: unknown shelf marks only where the demo wants them
	const intendedUnknown = new Set(['2-17'])
	for (const concert of catalog.concerts) {
		for (const [title, , , marks] of concert.lines) {
			for (const mark of marks.split('|').map((s) => s.trim()).filter(Boolean)) {
				if (!known(mark) && !intendedUnknown.has(mark)) {
					fail(`concert ${concert.date}, ${title}: unknown shelf mark ${mark}`)
				}
			}
		}
	}
	for (const list of catalog.songLists) {
		for (const [title, mark, , candidates] of list.lines) {
			for (const m of [mark, ...candidates.split('|')].map((s) => s.trim()).filter(Boolean)) {
				if (!known(m)) {
					fail(`song list ${list.list}, ${title}: unknown shelf mark ${m}`)
				}
			}
		}
	}
	return shelfmarks
}

// ---------------------------------------------------------------------------

const scores = loadScores()
checkCatalog()
for (const copy of catalog.copies) {
	if (copy.score && !scores.has(copy.score)) {
		fail(`${copy.shelfmark}: score ${copy.score} not found in scores/`)
	}
}
if (problems.length > 0) {
	for (const problem of problems) {
		console.error(`ERROR ${problem}`)
	}
	process.exit(1)
}
if (checkOnly) {
	console.log('checks passed')
	process.exit(0)
}

const archive = join(out, catalog.archiveFolder)
const importDir = join(archive, catalog.importFolder)
rmSync(out, { recursive: true, force: true })
mkdirSync(importDir, { recursive: true })
const written = []
const write = (relative, content) => {
	const path = join(archive, relative)
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, content)
	written.push(relative)
}

// Archive files and catalog rows
const legacyRows = []
const extendedColumns = [...new Set(catalog.copies.flatMap((copy) => Object.keys(copy.extended ?? {})))]
const order = ['copy.status', 'copy.status_reason', 'copy.kind', 'copy.originals_count', 'copy.copies_count',
	'version.kind', 'version.relation', 'version.scoring', 'version.key_signature', 'version.language', 'version.rule1_status', 'version.rule1_count']
extendedColumns.sort((a, b) => order.indexOf(a) - order.indexOf(b))
const supplementRows = []
for (const copy of catalog.copies) {
	const files = describeCopy(copy)
	legacyRows.push(legacyRow(copy, files))
	supplementRows.push([copy.shelfmark, ...extendedColumns.map((column) => copy.extended?.[column] ?? '')])
	if (files.pdf) {
		write(`${files.folder}/${files.pdf}`, placeholderPdf({
			shelfmark: copy.shelfmark,
			title: copy.title,
			subtitle: [copy.composer, copy.arranger_text].filter(Boolean).join(' · '),
			pages: Number.parseInt(copy.pages, 10) || 1,
			lines: [
				`${catalog.choir} – Notenarchiv-Demo-Bestand (frei erfunden)`,
				`Signatur ${copy.shelfmark}, Archivmappe ${files.binder}`,
				'Platzhalter für einen Notenscan. Die Werke sind gemeinfrei,',
				'die Demo-Daten stehen unter CC0 1.0.',
			],
		}))
	}
	if (files.score) {
		const text = toMusicXml(scores.get(copy.score), ENCODING)
		write(`${files.folder}/${files.score}`, copy.scoreFormat === 'mxl'
			? mxl(files.score.replace(/\.mxl$/, '.musicxml'), text, catalog.date)
			: text)
	}
}
for (const extra of catalog.extraFiles) {
	write(extra.path, extra.text ?? placeholderPdf({ shelfmark: extra.shelfmark, title: extra.title, pages: 1, lines: extra.lines }))
}

// Import files
const importFile = (name, content) => write(`${catalog.importFolder}/${name}`, content)
importFile('catalog.csv', writeCsv(LEGACY_COLUMNS.map(([header]) => header), legacyRows))
importFile('catalog-supplement.csv', writeCsv(['Signatur', ...extendedColumns], supplementRows))

const defaults = catalog.personDefaults
importFile('persons.csv', writeCsv(
	['Name', 'Werke', 'Signaturen', 'Lebensdaten laut Verzeichnis', 'Lebensdaten recherchiert', 'Schutz bis', 'Status', 'Quelle', 'Geprueft am'],
	catalog.persons.map((person) => [
		person.name, person.works, person.shelfmarks, person.listed, person.researched,
		protectedUntil(person.researched || person.listed), person.status ?? defaults.status, person.source ?? defaults.source, defaults.checked,
	]),
))

importFile('performances.csv', writeCsv(
	['Datum', 'Anlass', 'Ort', 'Titel laut Programm', 'Komponist/Satz laut Programm', 'Mitwirkung', 'Signatur', 'Zuordnung', 'Stand', 'Quelle'],
	catalog.concerts.flatMap((concert) => concert.lines.map(([title, composer, participation, shelfmark, assignment, state]) => [
		concert.date, concert.occasion, concert.location, title, composer, participation || catalog.choir, shelfmark, assignment, state,
		`Programmheft ${concert.date} (Demo)`,
	])),
))

importFile('song-lists.csv', writeCsv(
	['Liste', 'Stand', 'Quelle', 'Nr', 'Titel wie gedruckt', 'Signatur', 'Zuordnung', 'Kandidaten', 'Bemerkung'],
	catalog.songLists.flatMap((list) => list.lines.map(([title, shelfmark, assignment, candidates, note], i) => [
		list.list, list.date, `Notenliste ${list.list} (Demo)`, String(i + 1), title, shelfmark, assignment, candidates, note,
	])),
))

const count = (pattern) => written.filter((path) => pattern.test(path)).length
console.log(`written to ${archive}: ${written.length} files `
	+ `(${count(/\.pdf$/)} PDF, ${count(/\.mxl$/)} MXL, ${count(/\.musicxml$/)} MusicXML, ${count(/\.csv$/)} CSV)`)
