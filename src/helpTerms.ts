/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The terms of the archive that have a short explanation at their place
 * (the "?" button, HelpTerm.vue): name, one or two sentences and the
 * section of the guide. All texts in one place, so that they stay the same
 * wherever a term is explained.
 */
import type { GuideSection } from './docs.ts'

import { BOTH_GUIDES } from './docs.ts'
import { t } from './l10n.ts'

export type HelpTermKey =
	| 'version'
	| 'copy'
	| 'shelfmark'
	| 'rightsStatus'
	| 'release'
	| 'validVersion'
	| 'preservationCopy'

export interface HelpTermText {
	/** the term as it appears in the interface */
	name: string
	/** what it means, in plain words */
	text: string
	/** where the guide says more */
	section: GuideSection
}

/**
 * Name, explanation and guide section of a term.
 *
 * @param term - the term
 */
export function helpTerm(term: HelpTermKey): HelpTermText {
	switch (term) {
	case 'version':
		return {
			name: t('notenarchiv', 'Version'),
			text: t('notenarchiv', 'A version is one edition or arrangement of a work. Rights, releases and the valid version belong to the version, not to the work.'),
			section: BOTH_GUIDES.termVersion,
		}
	case 'copy':
		return {
			name: t('notenarchiv', 'Copy'),
			text: t('notenarchiv', 'A copy is one copy of a version on the shelf or in the archive folder, with its own shelf mark and status. A version can have several copies.'),
			section: BOTH_GUIDES.termVersion,
		}
	case 'shelfmark':
		return {
			name: t('notenarchiv', 'Shelf mark'),
			text: t('notenarchiv', 'The shelf mark says where a copy is kept: archive binder and number, e.g. 5-12. File names start with it, so that the file sync links the files to the copy.'),
			section: BOTH_GUIDES.termShelfmark,
		}
	case 'rightsStatus':
		return {
			name: t('notenarchiv', 'Rights status'),
			text: t('notenarchiv', 'Public domain, protected or open. The app proposes a status from the data; it counts for releases only after the music librarians have confirmed it. It is not legal advice.'),
			section: BOTH_GUIDES.termRightsStatus,
		}
	case 'release':
		return {
			name: t('notenarchiv', 'Release'),
			text: t('notenarchiv', 'A release says whether a version may be given out in a certain way, e.g. in the singers\' portal or at the print station. It follows from the confirmed rights or a valid license.'),
			section: BOTH_GUIDES.termRelease,
		}
	case 'validVersion':
		return {
			name: t('notenarchiv', 'Valid version'),
			text: t('notenarchiv', 'The version the conductor has chosen for the choir, at most one per work. It says nothing about the rights.'),
			section: BOTH_GUIDES.termValidVersion,
		}
	case 'preservationCopy':
		return {
			name: t('notenarchiv', 'Internal preservation copy'),
			text: t('notenarchiv', 'A copy kept only for the archive, e.g. the scan of a protected print. It is never shared, played or exported.'),
			section: BOTH_GUIDES.termPreservationCopy,
		}
	}
	return { name: '', text: '', section: BOTH_GUIDES.termVersion }
}

/** Sections of the sidebar whose heading is explained (FieldSection.vue). */
export const SECTION_HELP: Readonly<Partial<Record<string, HelpTermKey>>> = {
	version: 'version',
	copy: 'copy',
}

/** Fields whose label is explained (FieldSection.vue). */
export const FIELD_HELP: Readonly<Partial<Record<string, HelpTermKey>>> = {
	shelfmark: 'shelfmark',
	rights_status: 'rightsStatus',
	preservation_copy: 'preservationCopy',
}
