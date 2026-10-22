/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Links into the guides (the same place as `<documentation>` in
 * appinfo/info.xml). Messages that say what to do link the matching
 * section. A unit test checks that every anchor exists in the guide.
 * Opening a link is the person's own choice; the app itself never requests
 * anything from outside.
 */
import { getLanguage } from '@nextcloud/l10n'

const DOCS = 'https://github.com/thoscut/choircoresmanager/blob/main/docs/'

/** Sections of docs/admin-guide.md (GitHub's anchors of the headings). */
export const ADMIN_GUIDE = {
	setup: 'setting-up-in-five-steps',
	roles: '2-roles-and-groups',
	delegation: 'giving-the-settings-to-a-group',
	archive: '3-archive-folder',
	sharing: 'sharing-the-archive-folder',
	access: 'checking-who-can-open-the-archive-folder',
	import: '4-first-import',
	backup: '5-backups-and-restore',
	modules: '12-modules-and-presets',
	folderBook: '15-folder-book-retention-period',
	printMirror: '16-mirror-folder-for-the-print-station',
	problems: 'common-problems',
} as const

/** Sections of docs/user-guide.md. */
export const USER_GUIDE = {
	filesNotShared: 'files-you-cannot-open',
	revisions: 'revisions-of-a-version',
	validVersion: 'choosing-the-valid-version',
} as const

/**
 * A section in both guides: the English user guide and the German quick
 * start (docs/de/schnellstart.md). German readers get the quick start.
 */
export interface GuideSection {
	/** anchor in docs/user-guide.md */
	en: string
	/** anchor in docs/de/schnellstart.md */
	de: string
}

/** Sections that exist in both guides. */
export const BOTH_GUIDES = {
	noAccess: { en: 'no-access-to-the-music-archive', de: 'kein-zugang-zum-notenarchiv' },
	termVersion: { en: 'work-version-and-copy', de: 'werk-fassung-und-exemplar' },
	termShelfmark: { en: 'shelf-mark', de: 'signatur' },
	termRightsStatus: { en: 'rights-status-and-confirmation', de: 'rechtestatus-und-bestätigung' },
	termRelease: { en: 'release', de: 'freigabe' },
	termValidVersion: { en: 'valid-version', de: 'geltende-fassung' },
	termPreservationCopy: { en: 'internal-preservation-copy', de: 'interne-erhaltungskopie' },
} as const satisfies Record<string, GuideSection>

/**
 * Link to a section of the administration guide.
 *
 * @param section - anchor, a value of ADMIN_GUIDE
 */
export function adminGuideUrl(section: typeof ADMIN_GUIDE[keyof typeof ADMIN_GUIDE]): string {
	return `${DOCS}admin-guide.md#${section}`
}

/**
 * Link to a section of the user guide.
 *
 * @param section - anchor, a value of USER_GUIDE
 */
export function userGuideUrl(section: typeof USER_GUIDE[keyof typeof USER_GUIDE]): string {
	return `${DOCS}user-guide.md#${section}`
}

/**
 * Link to a section that exists in both guides, in the language of the
 * user: the German quick start or the English user guide.
 *
 * @param section - anchors of the section, a value of BOTH_GUIDES
 */
export function guideUrl(section: GuideSection): string {
	return isGerman()
		? `${DOCS}de/schnellstart.md#${encodeURIComponent(section.de)}`
		: `${DOCS}user-guide.md#${section.en}`
}

/** Whether the user reads German (the quick start is German). */
function isGerman(): boolean {
	return getLanguage().toLowerCase().startsWith('de')
}

/**
 * The anchor GitHub gives a heading: lower case, without punctuation, spaces
 * as hyphens.
 *
 * @param heading - text of the heading without the leading #
 */
export function headingAnchor(heading: string): string {
	return heading.trim().toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/g, '-')
}

/**
 * The guide behind "Help": in German the quick start, otherwise the user
 * guide (the full guides are in English).
 */
export function helpUrl(): string {
	return isGerman() ? DOCS + 'de/schnellstart.md' : DOCS + 'user-guide.md'
}
