/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Formatting of values for display: dates, sizes and field values from
 * the field directory.
 */
import type { FieldInfo, FieldValue } from './types.ts'

import { getCanonicalLocale } from '@nextcloud/l10n'
import { t } from './l10n.ts'

const APP = 'notenarchiv'

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
 * A date for display in the user's locale. Values that are not complete
 * dates (e.g. "2025-12" from the catalog) are shown as they are.
 *
 * @param value - ISO date or free text
 */
export function formatDate(value: string): string {
	const date = parseIsoDate(value)
	if (date === null) {
		return value
	}
	return new Intl.DateTimeFormat(locale(), { dateStyle: 'medium' }).format(date)
}

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
		return value === true || value === 1 || value === '1' || value === 'true' ? t('notenarchiv', 'Yes') : t('notenarchiv', 'No')
	case 'choice':
		return optionLabel(info, String(value))
	case 'date':
		return formatDate(String(value))
	default:
		if (typeof value === 'boolean') {
			return value ? t('notenarchiv', 'Yes') : t('notenarchiv', 'No')
		}
		return String(value)
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
			return t('notenarchiv', 'Yes')
		}
		if (['0', 'false', 'no', 'nein'].includes(value.toLowerCase())) {
			return t('notenarchiv', 'No')
		}
	}
	return displayValue(info, value)
}
