/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
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
		return t('cantoria', 'Due')
	case 'catalog':
		return t('cantoria', 'Catalog')
	case 'tasks':
		return t('cantoria', 'Tasks')
	case 'proposals':
		return t('cantoria', 'Inbox')
	case 'portal':
		return t('cantoria', 'Singers\' portal')
	case 'printStation':
		return t('cantoria', 'Print station')
	case 'licenses':
		return t('cantoria', 'Ending licenses')
	case 'links':
		// internal links and, with milestone 0.7, links for choir members and the public
		return t('cantoria', 'Links')
	case 'concerts':
		return t('cantoria', 'Concerts')
	case 'event':
		return t('cantoria', 'Programmes')
	case 'songLists':
		return t('cantoria', 'Song lists')
	case 'ambiguous':
		return t('cantoria', 'Unclear programme lines')
	case 'persons':
		return t('cantoria', 'Persons')
	case 'protection':
		return t('cantoria', 'Protection terms')
	case 'statistics':
		return t('cantoria', 'Statistics')
	case 'accession':
		return librarian ? t('cantoria', 'New accession') : t('cantoria', 'Shelf labels')
	case 'merges':
		return t('cantoria', 'Merge')
	case 'inventory':
		return t('cantoria', 'Inventory')
	case 'checksums':
		return t('cantoria', 'Checksums')
	case 'folderBook':
		return t('cantoria', 'Folder book')
	case 'gaps':
		return t('cantoria', 'Gaps in shelf marks')
	case 'snapshots':
		return t('cantoria', 'Annual snapshots')
	case 'validVersions':
		return t('cantoria', 'Choose valid versions')
	}
	return t('cantoria', 'Cantoria')
}
