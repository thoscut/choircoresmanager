/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What confirming a rights status makes possible, said in the dialog before
 * saving (guideline 13: consequences before saving). It follows the rules of
 * the server (lib/Service/RightsPolicy.php) and names only switched-on
 * functions; the server decides again on every use.
 */
import { t } from './l10n.ts'

/**
 * Sentences about the consequences of a confirmation.
 *
 * @param status - the status to be confirmed ('public_domain' or 'protected')
 * @param preservationCopy - whether the version is an internal preservation copy
 * @param isActive - whether a module is switched on
 */
export function confirmConsequences(status: string, preservationCopy: boolean, isActive: (module: string) => boolean): string[] {
	if (preservationCopy) {
		return [t('notenarchiv', 'This version is an internal preservation copy: it stays internal and is never shared, played or exported, whatever the rights status.')]
	}
	const uses = [
		isActive('portal') ? t('notenarchiv', 'singers\' portal') : '',
		isActive('print_station') ? t('notenarchiv', 'print station') : '',
		isActive('practice_audio') ? t('notenarchiv', 'practice files') : '',
		isActive('member_links') ? t('notenarchiv', 'sharing with choir members') : '',
		isActive('public_links') ? t('notenarchiv', 'public links (after the release for publication)') : '',
	].filter((use) => use !== '')
	if (uses.length === 0) {
		return []
	}
	if (status === 'public_domain') {
		return [t('notenarchiv', 'After the confirmation this becomes possible: {uses}.', { uses: uses.join(', ') })]
	}
	return [t('notenarchiv', 'A protected version needs a valid license for each of these uses: {uses}.', { uses: uses.join(', ') })]
}
