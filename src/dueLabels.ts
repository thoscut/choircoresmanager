/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Labels of the start page cards in singular or plural. The card shows the
 * number above its label ("1" / "Proposal in the inbox"), so the texts have
 * no "%n"; together they are the visible text and the accessible name of the
 * card (WCAG 2.5.3). The server also sends a label (plural only); it is
 * used for card keys this version of the app does not know yet.
 */
import { n } from './l10n.ts'

/**
 * Label of a card for its number.
 *
 * @param key - card key from GET /api/due
 * @param count - number shown on the card
 * @param fallback - label translated by the server
 */
export function dueCardLabel(key: string, count: number, fallback: string): string {
	switch (key) {
	case 'tasks_open':
		return n('notenarchiv', 'Open task for your role', 'Open tasks for your role', count)
	case 'tasks_answered':
		return n('notenarchiv', 'Answered task to apply', 'Answered tasks to apply', count)
	case 'ambiguous_items':
		return n('notenarchiv', 'Programme line with an unclear shelf mark', 'Programme lines with an unclear shelf mark', count)
	case 'proposals':
		return n('notenarchiv', 'Proposal in the inbox', 'Proposals in the inbox', count)
	case 'portal_remove':
		return n('notenarchiv', 'Version to remove from the singers\' portal', 'Versions to remove from the singers\' portal', count)
	case 'portal_add':
		return n('notenarchiv', 'Released version not yet in the singers\' portal', 'Released versions not yet in the singers\' portal', count)
	case 'confirm_current':
		return n('notenarchiv', 'Current repertoire: public domain version to confirm', 'Current repertoire: public domain versions to confirm', count)
	case 'rule1_current':
		return n('notenarchiv', 'Current repertoire: preservation copy with originals to check', 'Current repertoire: preservation copies with originals to check', count)
	case 'licenses_expiring':
		return n('notenarchiv', 'License ending or ended', 'Licenses ending or ended', count)
	case 'valid_missing':
		return n('notenarchiv', 'Current repertoire: work without a chosen valid version', 'Current repertoire: works without a chosen valid version', count)
	default:
		return fallback
	}
}
