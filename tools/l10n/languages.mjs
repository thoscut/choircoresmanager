/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Languages of the app besides the English source and their plural rules.
//
// The browser picks a plural form with the rules built into @nextcloud/l10n
// (getPlural), the server with the "pluralForm" of l10n/<lang>.json. Each
// pluralForm here gives the same form as getPlural, so both sides agree
// (checked by translations.test.mjs).

/** German: required, kept in translations.mjs (informal "de", formal "de_DE"). */
export const GERMAN = {
	pluralForm: 'nplurals=2; plural=(n != 1);',
}

/**
 * Further languages, each in translations.<lang>.mjs. A missing translation
 * falls back to the English source; generate.mjs lists it.
 */
export const LANGUAGES = {
	fr: { name: 'French', pluralForm: 'nplurals=2; plural=(n > 1);' },
	es: { name: 'Spanish', pluralForm: 'nplurals=2; plural=(n != 1);' },
	it: { name: 'Italian', pluralForm: 'nplurals=2; plural=(n != 1);' },
	nl: { name: 'Dutch', pluralForm: 'nplurals=2; plural=(n != 1);' },
	pl: { name: 'Polish', pluralForm: 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);' },
}

/**
 * Number of plural forms of a pluralForm expression.
 *
 * @param {string} pluralForm - e.g. 'nplurals=2; plural=(n != 1);'
 * @return {number}
 */
export function pluralCount(pluralForm) {
	return Number(pluralForm.match(/nplurals=(\d+)/)[1])
}

/**
 * Placeholders of a text, sorted: {name}, %n, %s, %d, %1$s …
 *
 * @param {string} text - source or translation
 * @return {string}
 */
export function placeholders(text) {
	return [...text.matchAll(/\{\w+\}|%(?:\d+\$)?[sd]|%n/g)].map((m) => m[0]).sort().join(',')
}
