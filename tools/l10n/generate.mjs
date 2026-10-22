// Writes l10n/de.json, de_DE.json, de.js, de_DE.js from translations.mjs
// and checks them against the strings extracted from src/ and lib/.
// Usage: node generate.mjs <projectRoot> <strings.json>
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { plural, singular } from './translations.mjs'

const [root, stringsFile] = process.argv.slice(2)
const strings = JSON.parse(readFileSync(stringsFile, 'utf8'))
const PLURAL_FORM = 'nplurals=2; plural=(n != 1);'

const missing = []
const out = { de: {}, de_DE: {} }
const placeholders = (s) => [...s.matchAll(/\{\w+\}|%[sd]|%n/g)].map((m) => m[0]).sort().join(',')

for (const { s } of strings.singular) {
	const value = singular[s]
	if (value === undefined) {
		missing.push(s)
		continue
	}
	const [du, sie] = Array.isArray(value) ? value : [value, value]
	for (const text of [du, sie]) {
		if (placeholders(text) !== placeholders(s)) {
			throw new Error(`Placeholder mismatch: "${s}" -> "${text}"`)
		}
	}
	out.de[s] = du
	out.de_DE[s] = sie
}
for (const [one, many] of strings.plural) {
	const value = plural[`${one}|${many}`]
	if (value === undefined) {
		missing.push(`${one} | ${many}`)
		continue
	}
	const du = Array.isArray(value) ? value : value.du
	const sie = Array.isArray(value) ? value : value.sie
	out.de[`_${one}_::_${many}_`] = du
	out.de_DE[`_${one}_::_${many}_`] = sie
}

const used = new Set([...strings.singular.map(({ s }) => s), ...strings.plural.map(([a, b]) => `${a}|${b}`)])
const unused = [...Object.keys(singular), ...Object.keys(plural)].filter((k) => !used.has(k))

if (missing.length > 0) {
	console.error('MISSING translations:\n  ' + missing.join('\n  '))
	process.exitCode = 1
}
if (unused.length > 0) {
	console.log('unused translations (kept out of the files):\n  ' + unused.join('\n  '))
}

mkdirSync(join(root, 'l10n'), { recursive: true })
for (const lang of ['de', 'de_DE']) {
	const translations = out[lang]
	writeFileSync(join(root, 'l10n', `${lang}.json`), JSON.stringify({ translations, pluralForm: PLURAL_FORM }, null, 4) + '\n')
	const body = JSON.stringify(translations, null, 4).replace(/\n/g, '\n')
	writeFileSync(join(root, 'l10n', `${lang}.js`), `OC.L10N.register(\n    "notenarchiv",\n    ${body.replace(/\n/g, '\n    ')},\n    "${PLURAL_FORM}");\n`)
	console.log(`${lang}: ${Object.keys(translations).length} entries`)
}
