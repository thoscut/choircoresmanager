<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCP\IL10N;

/**
 * Texts for the protection proposal in the user's language. The proposal
 * itself carries only reason keys, so that it can be computed without a
 * request (background jobs, tests).
 *
 * @psalm-import-type Proposal from ProtectionTerm
 */
final class ProtectionText {
	/**
	 * Adds `reasonText` (and `reasonText` of every blocking person) plus the
	 * `notice` that this is a proposal and no legal advice.
	 *
	 * @param Proposal $proposal result of ProtectionTerm::propose()
	 * @return array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int,
	 *     blocking: list<array{personId: ?int, name: string, reason: string, reasonText: string}>, persons?: int, reasonText: string, notice: string}
	 */
	public static function apply(IL10N $l, array $proposal): array {
		$blocking = [];
		foreach ($proposal['blocking'] as $person) {
			$blocking[] = $person + ['reasonText' => self::reason($l, $person['reason'], [$person['name']])];
		}
		$proposal['blocking'] = $blocking;
		$proposal['reasonText'] = self::reason($l, $proposal['reason'], $proposal['parameters']);
		$proposal['notice'] = $l->t('Proposal only, based on German law (protection ends 70 years after the end of the year in which the last person involved died). It is no legal advice and does not change the rights status.');
		return $proposal;
	}

	/**
	 * @param list<string|int> $p
	 */
	private static function reason(IL10N $l, string $key, array $p): string {
		$p = array_map(fn ($v): string => str_replace('%', '%%', (string)$v), $p);
		return match ($key) {
			'no_persons' => $l->t('No persons are recorded for this version. Unknown means protected.'),
			'living' => $l->t('%s is alive. The version is protected.', [$p[0] ?? '']),
			'death_unknown' => $l->t('The year of death of %s is not known. Unknown means protected.', [$p[0] ?? '']),
			'death_circa' => $l->t('The year of death of %s is only approximate. Without a secured year of death there is no "public domain" proposal.', [$p[0] ?? '']),
			'identification_uncertain' => $l->t('It is uncertain who %s is. Without a secured identification there is no "public domain" proposal.', [$p[0] ?? '']),
			'ended' => $l->t('Protection ended at the end of %3$s: the last of the persons involved, %1$s, died in %2$s.', [$p[0] ?? '', $p[1] ?? '', $p[2] ?? '']),
			'running' => $l->t('Protected until the end of %3$s: the last of the persons involved, %1$s, died in %2$s.', [$p[0] ?? '', $p[1] ?? '', $p[2] ?? '']),
			default => $key,
		};
	}
}
