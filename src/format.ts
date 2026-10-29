/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Formatting of values for display: dates, sizes and field values from
 * the field directory.
 */
import type { FieldInfo, FieldValue } from './types.ts'

import { getCanonicalLocale } from '@nextcloud/l10n'
import { t } from './l10n.ts'

const APP = 'cantoria'

/**
 * Translate an English label delivered by the server (field labels, option
 * labels, role labels). The German texts are in l10n/.
 *
 * @param label - English source string
 */
export function translateLabel(label: string): string {
	return label === '' ? '' : t(APP, label)
}

function locale(): string {
	try {
		return getCanonicalLocale()
	} catch {
		return 'de-DE'
	}
}

/**
 * Parse "YYYY-MM-DD" as a local calendar day.
 *
 * @param value - ISO date
 */
export function parseIsoDate(value: string): Date | null {
	const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/)
	if (!match) {
		return null
	}
	const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * Format a local calendar day as "YYYY-MM-DD".
 *
 * @param date - the day
 */
export function toIsoDate(date: Date): string {
	const yyyy = date.getFullYear().toString().padStart(4, '0')
	const mm = (date.getMonth() + 1).toString().padStart(2, '0')
	const dd = date.getDate().toString().padStart(2, '0')
	return `${yyyy}-${mm}-${dd}`
}

/**
 * A date for display in the user's locale, the same everywhere (catalog,
 * sidebar, statistics). A month without a day ("2025-12", e.g. "last
 * performed" from an imported catalog) is shown as month and year; other
 * values (free text) are shown as they are.
 *
 * @param value - ISO date, year and month, or free text
 */
export function formatDate(value: string): string {
	const date = parseIsoDate(value)
	if (date !== null) {
		return new Intl.DateTimeFormat(locale(), { dateStyle: 'medium' }).format(date)
	}
	const month = value.match(/^(\d{4})-(\d{2})$/)
	if (month && Number(month[2]) >= 1 && Number(month[2]) <= 12) {
		return new Intl.DateTimeFormat(locale(), { year: 'numeric', month: 'short' }).format(new Date(Number(month[1]), Number(month[2]) - 1, 1))
	}
	return value
}

/** A date or a month in ISO form, as text fields of the catalog may hold it. */
const ISO_DATE_TEXT = /^\d{4}-\d{2}(-\d{2})?$/

/**
 * Date and time of a Unix timestamp in the user's locale.
 *
 * @param seconds - Unix timestamp in seconds
 */
export function formatDateTime(seconds: number): string {
	return new Intl.DateTimeFormat(locale(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(seconds * 1000))
}

/**
 * File size in B, KB, MB or GB.
 *
 * @param bytes - size in bytes
 */
export function formatSize(bytes: number | null | undefined): string {
	if (bytes === null || bytes === undefined || bytes < 0) {
		return ''
	}
	const units = ['B', 'KB', 'MB', 'GB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	const digits = unit === 0 || value >= 10 ? 0 : 1
	return new Intl.NumberFormat(locale(), { maximumFractionDigits: digits }).format(value) + ' ' + units[unit]
}

/**
 * Whether a value counts as empty for display.
 *
 * @param value - field value
 */
export function isEmpty(value: FieldValue | undefined): boolean {
	return value === null || value === undefined || (typeof value === 'string' && value.trim() === '')
}

/**
 * Label of a choice option, translated; unknown keys are shown as they are.
 *
 * @param info - field directory entry
 * @param key - option key
 */
export function optionLabel(info: FieldInfo | undefined, key: string): string {
	const label = info?.options?.[key]
	return label ? translateLabel(label) : key
}

/**
 * A field value as text for display. Empty values give ''.
 *
 * @param info - field directory entry (may be missing for unknown fields)
 * @param value - raw value from the server
 */
export function displayValue(info: FieldInfo | undefined, value: FieldValue | undefined): string {
	if (isEmpty(value)) {
		return ''
	}
	switch (info?.type) {
	case 'boolean':
		return value === true || value === 1 || value === '1' || value === 'true' ? t('cantoria', 'Yes') : t('cantoria', 'No')
	case 'choice':
		return optionLabel(info, String(value))
	case 'date':
		return formatDate(String(value))
	default:
		if (typeof value === 'boolean') {
			return value ? t('cantoria', 'Yes') : t('cantoria', 'No')
		}
		// A date in a text field (e.g. "last performed" from the import) looks like every other date
		return typeof value === 'string' && ISO_DATE_TEXT.test(value) ? formatDate(value) : String(value)
	}
}

/**
 * A value from the audit log (always stored as text) for display.
 *
 * @param info - field directory entry
 * @param value - stored text
 */
export function displayAuditValue(info: FieldInfo | undefined, value: string | null): string {
	if (value === null || value === '') {
		return ''
	}
	if (info?.type === 'boolean') {
		if (['1', 'true', 'yes', 'ja'].includes(value.toLowerCase())) {
			return t('cantoria', 'Yes')
		}
		if (['0', 'false', 'no', 'nein'].includes(value.toLowerCase())) {
			return t('cantoria', 'No')
		}
	}
	return displayValue(info, value)
}
