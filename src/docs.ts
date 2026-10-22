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
	problems: 'common-problems',
} as const

/** Sections of docs/user-guide.md. */
export const USER_GUIDE = {
	filesNotShared: 'files-you-cannot-open',
} as const

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
	return getLanguage().toLowerCase().startsWith('de') ? DOCS + 'de/schnellstart.md' : DOCS + 'user-guide.md'
}
