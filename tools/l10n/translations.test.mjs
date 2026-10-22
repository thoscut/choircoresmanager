/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Checks every translation module: placeholders as in the English source,
// complete plural forms, and plural rules of the server equal to those of the
// browser (@nextcloud/l10n).
import { getPlural } from '@nextcloud/l10n'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { GERMAN, LANGUAGES, placeholders, pluralCount } from './languages.mjs'
import * as german from './translations.mjs'

const modules = Object.fromEntries(await Promise.all(Object.keys(LANGUAGES).map(async (lang) => [lang, await import(`./translations.${lang}.mjs`)])))

/**
 * Evaluates a gettext plural expression the way the server does.
 *
 * @param {string} pluralForm - e.g. 'nplurals=2; plural=(n != 1);'
 * @return {(n: number) => number}
 */
function serverPlural(pluralForm) {
	const expression = pluralForm.match(/plural=(.*);/)[1]
	// eslint-disable-next-line no-new-func
	return new Function('n', `return Number(${expression})`)
}

describe('German translations', () => {
	it('keep the placeholders of the source', () => {
		for (const [source, value] of Object.entries(german.singular)) {
			for (const text of Array.isArray(value) ? value : [value]) {
				expect(placeholders(text), source).toBe(placeholders(source))
			}
		}
	})

	it('have both plural forms with the placeholders of the source', () => {
		for (const [key, value] of Object.entries(german.plural)) {
			const many = key.split('|')[1]
			for (const forms of Array.isArray(value) ? [value] : [value.du, value.sie]) {
				expect(forms, key).toHaveLength(pluralCount(GERMAN.pluralForm))
				forms.forEach((text) => expect(placeholders(text), key).toBe(placeholders(many)))
			}
		}
	})
})

describe.each(Object.entries(LANGUAGES))('%s translations', (lang, { pluralForm }) => {
	const { singular, plural } = modules[lang]

	it('keep the placeholders of the source', () => {
		for (const [source, text] of Object.entries(singular)) {
			expect(typeof text, source).toBe('string')
			expect(text.trim(), source).not.toBe('')
			expect(placeholders(text), source).toBe(placeholders(source))
		}
	})

	it('have every plural form with the placeholders of the source', () => {
		for (const [key, forms] of Object.entries(plural)) {
			const many = key.split('|')[1]
			expect(forms, key).toHaveLength(pluralCount(pluralForm))
			forms.forEach((text) => expect(placeholders(text), key).toBe(placeholders(many)))
		}
	})

	it('choose the same plural form on the server as in the browser', () => {
		const server = serverPlural(pluralForm)
		for (let n = 0; n <= 1000; n++) {
			expect(server(n), `n = ${n}`).toBe(getPlural(n, lang))
		}
	})

	it('are generated into l10n/ with the plural rule', () => {
		const file = JSON.parse(readFileSync(new URL(`../../l10n/${lang}.json`, import.meta.url), 'utf8'))
		expect(file.pluralForm).toBe(pluralForm)
		expect(Object.keys(file.translations).length).toBeGreaterThan(0)
	})
})
