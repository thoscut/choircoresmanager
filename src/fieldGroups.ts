/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Which fields of `meta.fields` appear in which section of the sidebar.
 *
 * The forms are generated from the field directory; this file only sorts
 * fields into the tabs "Overview" and "Rights". Fields not matched by a
 * rule stay in the overview of their level, so new server fields always
 * show up somewhere.
 */
import type { FieldMap, Level } from './types.ts'

export type SectionId = 'work' | 'version' | 'copy' | 'rights' | 'musicxml'

export interface SectionDef {
	id: SectionId
	level: Level
}

/** Version fields shown in the "Rights" section, in this order if present. */
const RIGHTS_ORDER = [
	'rights_status',
	'rights_confirmed',
	'rights_assessment',
	'rights_status_import',
	'preservation_copy',
	'publication',
	'publication_reason',
	'publisher',
	'license',
	'proof_of_purchase',
	'publication_status',
	'rule1_status',
	'rule1_count',
	'rule1_checked',
]

/**
 * Fields shown by the rights panel (confirmation, portal, valid version);
 * they are set by actions, not edited in a form.
 */
export const PANEL_FIELDS = new Set([
	'confirmed_by',
	'confirmed_by_name',
	'confirmed_at',
	'confirmed_reason',
	'confirmed_evidence',
	'portal_since',
	'portal_removed_at',
	'portal_removed_reason',
	'valid_since',
	'valid_set_by',
	'valid_reason',
])

/** Fallback rule for rights fields with other names. */
const RIGHTS_PATTERN = /^(rights|publication|publisher|licen[cs]e|proof_of_purchase|preservation|portal|rule1)/

const MUSICXML_PATTERN = /^(musicxml|review_status)/

function belongsTo(section: SectionId, key: string): boolean {
	switch (section) {
	case 'rights':
		return RIGHTS_ORDER.includes(key) || RIGHTS_PATTERN.test(key)
	case 'musicxml':
		return MUSICXML_PATTERN.test(key)
	case 'version':
		return !belongsTo('rights', key) && !belongsTo('musicxml', key)
	default:
		return true
	}
}

/**
 * Keys of the fields of one section, in display order.
 *
 * @param section - the section
 * @param fields - field directory of the section's level
 */
export function sectionFields(section: SectionId, fields: FieldMap): string[] {
	const keys = Object.keys(fields).filter((key) => !PANEL_FIELDS.has(key) && belongsTo(section, key))
	if (section === 'rights') {
		const rank = (key: string) => {
			const index = RIGHTS_ORDER.indexOf(key)
			return index === -1 ? RIGHTS_ORDER.length : index
		}
		return keys.sort((a, b) => rank(a) - rank(b))
	}
	return keys
}

export const OVERVIEW_SECTIONS: SectionDef[] = [
	{ id: 'work', level: 'work' },
	{ id: 'version', level: 'version' },
	{ id: 'copy', level: 'copy' },
]

export const RIGHTS_SECTIONS: SectionDef[] = [
	{ id: 'rights', level: 'version' },
	{ id: 'musicxml', level: 'version' },
]
