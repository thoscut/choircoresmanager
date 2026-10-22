/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Names of the views, shared by the navigation and the title of the browser
 * tab (WCAG 2.4.2: every view has its own page title).
 */
import type { ViewName } from './startParams.ts'

import { t } from './l10n.ts'

/**
 * Name of a view.
 *
 * @param view - the view
 * @param librarian - whether the user has the librarian role: the accession
 *   view is "New accession" for librarians and "Shelf labels" for others
 */
export function viewTitle(view: ViewName, librarian: boolean): string {
	switch (view) {
	case 'due':
		return t('notenarchiv', 'Due')
	case 'catalog':
		return t('notenarchiv', 'Catalog')
	case 'tasks':
		return t('notenarchiv', 'Tasks')
	case 'proposals':
		return t('notenarchiv', 'Inbox')
	case 'portal':
		return t('notenarchiv', 'Singers\' portal')
	case 'printStation':
		return t('notenarchiv', 'Print station')
	case 'licenses':
		return t('notenarchiv', 'Ending licenses')
	case 'links':
		// internal links and, with milestone 0.7, links for choir members and the public
		return t('notenarchiv', 'Links')
	case 'concerts':
		return t('notenarchiv', 'Concerts')
	case 'event':
		return t('notenarchiv', 'Programmes')
	case 'songLists':
		return t('notenarchiv', 'Song lists')
	case 'ambiguous':
		return t('notenarchiv', 'Unclear programme lines')
	case 'persons':
		return t('notenarchiv', 'Persons')
	case 'protection':
		return t('notenarchiv', 'Protection terms')
	case 'statistics':
		return t('notenarchiv', 'Statistics')
	case 'accession':
		return librarian ? t('notenarchiv', 'New accession') : t('notenarchiv', 'Shelf labels')
	case 'merges':
		return t('notenarchiv', 'Merge')
	case 'inventory':
		return t('notenarchiv', 'Inventory')
	case 'checksums':
		return t('notenarchiv', 'Checksums')
	case 'folderBook':
		return t('notenarchiv', 'Folder book')
	case 'gaps':
		return t('notenarchiv', 'Gaps in shelf marks')
	case 'snapshots':
		return t('notenarchiv', 'Annual snapshots')
	}
	return t('notenarchiv', 'Music archive')
}
