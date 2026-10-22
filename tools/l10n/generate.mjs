/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Writes l10n/<lang>.json and l10n/<lang>.js for German (translations.mjs:
// "de" and "de_DE") and each further language (translations.<lang>.mjs, see
// languages.mjs), and checks them against the strings extracted from src/
// and lib/. A missing German translation fails the run; a missing translation
// of a further language falls back to English and is listed.
// Usage: node generate.mjs <projectRoot> <strings.json>
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { GERMAN, LANGUAGES, placeholders, pluralCount } from './languages.mjs'
import { plural, singular } from './translations.mjs'

const [root, stringsFile] = process.argv.slice(2)
const strings = JSON.parse(readFileSync(stringsFile, 'utf8'))
const pluralKey = (one, many) => `_${one}_::_${many}_`
const used = new Set([...strings.singular.map(({ s }) => s), ...strings.plural.map(([a, b]) => `${a}|${b}`)])
const errors = []

/**
 * Checks that a translation keeps the placeholders of its source.
 *
 * @param {string} lang - language code
 * @param {string} source - English source
 * @param {string} text - translation
 */
function checkPlaceholders(lang, source, text) {
	if (placeholders(text) !== placeholders(source)) {
		errors.push(`${lang}: placeholder mismatch: "${source}" -> "${text}"`)
	}
}

/**
 * Writes l10n/<lang>.json and l10n/<lang>.js.
 *
 * @param {string} lang - language code
 * @param {Record<string, string|string[]>} translations - source -> translation
 * @param {string} pluralForm - plural rule for the server
 */
function write(lang, translations, pluralForm) {
	writeFileSync(join(root, 'l10n', `${lang}.json`), JSON.stringify({ translations, pluralForm }, null, 4) + '\n')
	const body = JSON.stringify(translations, null, 4)
	writeFileSync(join(root, 'l10n', `${lang}.js`), `OC.L10N.register(\n    "notenarchiv",\n    ${body.replace(/\n/g, '\n    ')},\n    "${pluralForm}");\n`)
	console.log(`${lang}: ${Object.keys(translations).length} entries`)
}

/**
 * Lists translations whose source is no longer in the app.
 *
 * @param {string} lang - language code
 * @param {object} module - { singular, plural }
 */
function reportUnused(lang, module) {
	const unused = [...Object.keys(module.singular), ...Object.keys(module.plural)].filter((k) => !used.has(k))
	if (unused.length > 0) {
		console.log(`${lang}: unused translations (kept out of the files):\n  ` + unused.join('\n  '))
	}
}

mkdirSync(join(root, 'l10n'), { recursive: true })

// German: every string needs a translation
const missing = []
const de = {}
const deDE = {}
for (const { s } of strings.singular) {
	const value = singular[s]
	if (value === undefined) {
		missing.push(s)
		continue
	}
	const [du, sie] = Array.isArray(value) ? value : [value, value]
	checkPlaceholders('de', s, du)
	checkPlaceholders('de_DE', s, sie)
	de[s] = du
	deDE[s] = sie
}
for (const [one, many] of strings.plural) {
	const value = plural[`${one}|${many}`]
	if (value === undefined) {
		missing.push(`${one} | ${many}`)
		continue
	}
	de[pluralKey(one, many)] = Array.isArray(value) ? value : value.du
	deDE[pluralKey(one, many)] = Array.isArray(value) ? value : value.sie
}
if (missing.length > 0) {
	errors.push('MISSING German translations:\n  ' + missing.join('\n  '))
}
reportUnused('de', { singular, plural })
write('de', de, GERMAN.pluralForm)
write('de_DE', deDE, GERMAN.pluralForm)

// Further languages: a missing translation falls back to the English source
for (const [lang, { pluralForm }] of Object.entries(LANGUAGES)) {
	const module = await import(`./translations.${lang}.mjs`)
	const forms = pluralCount(pluralForm)
	const out = {}
	const fallback = []
	for (const { s } of strings.singular) {
		const text = module.singular[s]
		if (text === undefined) {
			fallback.push(s)
			continue
		}
		checkPlaceholders(lang, s, text)
		out[s] = text
	}
	for (const [one, many] of strings.plural) {
		const value = module.plural[`${one}|${many}`]
		if (value === undefined) {
			fallback.push(`${one} | ${many}`)
			continue
		}
		if (value.length !== forms) {
			errors.push(`${lang}: "${one}|${many}" needs ${forms} plural forms, has ${value.length}`)
		}
		value.forEach((text) => checkPlaceholders(lang, many, text))
		out[pluralKey(one, many)] = value
	}
	if (fallback.length > 0) {
		console.log(`${lang}: ${fallback.length} strings fall back to English:\n  ` + fallback.join('\n  '))
	}
	reportUnused(lang, module)
	write(lang, out, pluralForm)
}

if (errors.length > 0) {
	console.error(errors.join('\n'))
	process.exitCode = 1
}
