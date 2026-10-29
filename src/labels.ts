/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Translated labels of keys used across the views: roles, task kinds and
 * states, event states, release states, repertoire classes, license uses,
 * and the words of text references.
 */
import type { ReferenceWords } from './references.ts'
import type { EventStatus, ReleaseStatus, Role, TaskCategory, TaskKind, TaskStatus } from './types.ts'

import { t } from './l10n.ts'
import { translateLabel } from './format.ts'
import { meta } from './store.ts'

export const ROLES: Role[] = ['librarian', 'operations', 'conductor', 'contributor', 'reader']

/** Roles a task can be addressed to (see docs/api.md). */
export const TASK_ADDRESSEES: Role[] = ['conductor', 'librarian', 'operations', 'reader']

export const TASK_KINDS: TaskKind[] = ['question', 'check', 'report']

export const TASK_CATEGORIES: TaskCategory[] = ['reading', 'structure', 'origin', 'shelf', 'rights', 'other']

/**
 * Label of a role, as delivered by the server and translated.
 *
 * @param role - role key
 */
export function roleLabel(role: string): string {
	const label = meta.value?.roleLabels[role]
	return label ? translateLabel(label) : role
}

/**
 * @param kind - task kind
 */
export function taskKindLabel(kind: TaskKind | string): string {
	switch (kind) {
	case 'question':
		return t('cantoria', 'Question')
	case 'check':
		return t('cantoria', 'Check')
	case 'report':
		return t('cantoria', 'Report')
	default:
		return kind
	}
}

/**
 * @param status - task status
 */
export function taskStatusLabel(status: TaskStatus | string): string {
	switch (status) {
	case 'open':
		return t('cantoria', 'Open')
	case 'answered':
		return t('cantoria', 'Answered')
	case 'applied':
		return t('cantoria', 'Applied')
	case 'discarded':
		return t('cantoria', 'Discarded')
	default:
		return status
	}
}

/**
 * @param category - task category
 */
export function taskCategoryLabel(category: TaskCategory | string | null): string {
	switch (category) {
	case 'reading':
		return t('cantoria', 'Reading of the notes')
	case 'structure':
		return t('cantoria', 'Form and repeats')
	case 'origin':
		return t('cantoria', 'Origin and source')
	case 'shelf':
		return t('cantoria', 'Shelf and copies')
	case 'rights':
		return t('cantoria', 'Rights')
	case 'other':
		return t('cantoria', 'Other')
	default:
		return category ?? ''
	}
}

/**
 * @param status - event status
 */
export function eventStatusLabel(status: EventStatus | string): string {
	switch (status) {
	case 'draft':
		return t('cantoria', 'Draft')
	case 'fixed':
		return t('cantoria', 'Fixed')
	case 'sung':
		return t('cantoria', 'Sung')
	case 'cancelled':
		return t('cantoria', 'Cancelled')
	default:
		return status
	}
}

/**
 * @param status - status of a release list row
 */
export function releaseStatusLabel(status: ReleaseStatus | string): string {
	switch (status) {
	case 'remove':
		return t('cantoria', 'Remove')
	case 'add':
		return t('cantoria', 'Add')
	case 'not_decided':
		return t('cantoria', 'No valid version chosen')
	case 'ok':
		return t('cantoria', 'In order')
	case 'other_version_valid':
		return t('cantoria', 'Another version is valid')
	case 'blocked':
		return t('cantoria', 'Not released')
	default:
		return status
	}
}

/**
 * @param value - repertoire class key
 */
export function repertoireClassLabel(value: string | null | undefined): string {
	switch (value) {
	case 'current':
		return t('cantoria', 'Current repertoire')
	case 'dormant':
		return t('cantoria', 'Dormant')
	case 'earlier':
		return t('cantoria', 'Earlier')
	case 'none':
	case null:
	case undefined:
		return t('cantoria', 'Not documented')
	default:
		return value
	}
}

/**
 * @param source - where the repertoire class comes from
 */
export function repertoireSourceLabel(source: string | null | undefined): string {
	switch (source) {
	case 'performances':
		return t('cantoria', 'from documented performances')
	case 'import':
		return t('cantoria', 'from the imported catalog')
	default:
		return ''
	}
}

/**
 * Label of a license use, from the server's list and translated.
 *
 * @param use - use key
 */
export function licenseUseLabel(use: string): string {
	const label = meta.value?.licenseUses[use]
	return label ? translateLabel(label) : use
}

/**
 * Explanation of a license use, or '' for uses that explain themselves.
 *
 * @param use - use key
 */
export function licenseUseHint(use: string): string {
	return use === 'successor'
		? t('cantoria', 'The license may pass to a legal successor together with the archive, e.g. when choirs merge or another body takes over the archive.')
		: ''
}

/** Words of text references in the UI language. */
export function referenceWords(): ReferenceWords {
	return {
		page: (page) => t('cantoria', 'p. {page}', { page }),
		measure: (measure) => t('cantoria', 'm. {measure}', { measure }),
		measures: (from, to) => t('cantoria', 'm. {from}–{to}', { from, to }),
		verse: (verse) => t('cantoria', 'verse {verse}', { verse }),
	}
}

// ---- milestone 0.5 --------------------------------------------------------------

/**
 * Label of a contribution role (composer, lyricist, …), translated.
 *
 * @param role - role key
 */
export function contributionRoleLabel(role: string): string {
	const label = meta.value?.contributionRoles[role]
	return label ? translateLabel(label) : role
}

/**
 * Label of an option of a person field (precision, identification).
 *
 * @param field - person field key
 * @param value - option key
 */
export function personOptionLabel(field: string, value: string | null | undefined): string {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const label = meta.value?.personFields[field]?.options?.[value]
	return label ? translateLabel(label) : value
}

/**
 * Why a person prevents a protection date.
 *
 * @param reason - reason key from the server
 */
export function blockingReasonLabel(reason: string | null | undefined): string {
	switch (reason) {
	case 'living':
		return t('cantoria', 'living')
	case 'death_unknown':
		return t('cantoria', 'year of death unknown')
	case 'death_circa':
		return t('cantoria', 'year of death only approximate')
	case 'identification_uncertain':
		return t('cantoria', 'identification uncertain')
	default:
		return reason ?? ''
	}
}

/**
 * State of a protection proposal.
 *
 * @param status - free, protected or unknown
 */
export function protectionStatusLabel(status: string): string {
	switch (status) {
	case 'free':
		return t('cantoria', 'Public domain by the life dates')
	case 'protected':
		return t('cantoria', 'Protected by the life dates')
	default:
		return t('cantoria', 'Protection end unknown – counts as protected')
	}
}

/**
 * Life dates of a person as short text, e.g. "1788–1857".
 *
 * @param person - person with dates and precision
 * @param person.born - date or year of birth
 * @param person.born_precision - precision of the birth date
 * @param person.died - date or year of death
 * @param person.died_precision - precision of the death date
 * @param person.life_dates_text - life dates as found
 */
export function lifeDates(person: { born: string | null, born_precision: string | null, died: string | null, died_precision: string | null, life_dates_text: string | null }): string {
	const year = (value: string | null, precision: string | null) => {
		if (precision === 'living') {
			return ''
		}
		if (precision === 'unknown' || !value) {
			return '?'
		}
		const match = value.match(/^(-?\d{1,4})/)
		const text = match ? match[1] : value
		return precision === 'circa' ? t('cantoria', 'c. {year}', { year: text }) : text
	}
	if (!person.born && !person.died && person.life_dates_text) {
		return person.life_dates_text
	}
	const born = year(person.born, person.born_precision)
	if (person.died_precision === 'living') {
		return t('cantoria', 'born {year}', { year: born })
	}
	return `${born}–${year(person.died, person.died_precision)}`
}
