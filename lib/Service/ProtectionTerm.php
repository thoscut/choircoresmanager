<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Person;

/**
 * Proposal for the end of protection of a version (German law: §§ 64, 65
 * UrhG): protection ends at the end of the 70th calendar year after the
 * death of the last of all persons involved.
 *
 * The proposal is for display only. It never sets the rights status; the
 * catalog librarian decides. Rules from the expert report of the rights
 * holders:
 * - Unknown means protected: without persons, or when any person lacks a
 *   secured year of death (living, unknown, approximate) or is not securely
 *   identified, there is no "public domain" proposal.
 * - Unnamed is not anonymous: missing names never lead to the term for
 *   anonymous works (§ 66 UrhG).
 * - All roles count, including arrangers, translators and editors. The flag
 *   "text written for this composition" (§ 65 (3) UrhG) does not shorten
 *   anything, because both parts are needed to sing the version anyway.
 *
 * Result:
 * - `status`: `free` (protection has ended), `protected` (ends at a known
 *   date) or `unknown` (to be treated as protected)
 * - `until`: last day of protection (YYYY-12-31) when known
 * - `freeSince`: first free day (YYYY-01-01) when known
 * - `reason`: key of the explanation, `parameters`: values for it
 * - `blocking`: persons that prevent a date, each with its reason key
 *
 * Pure function without Nextcloud dependencies, so that it can be unit
 * tested.
 *
 * @psalm-type Blocking = array{personId: ?int, name: string, reason: string}
 * @psalm-type Proposal = array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int, blocking: list<Blocking>, persons?: int}
 */
final class ProtectionTerm {
	public const YEARS_AFTER_DEATH = 70;

	public const FREE = 'free';
	public const PROTECTED = 'protected';
	public const UNKNOWN = 'unknown';

	/** Reasons why no date can be given, most serious first */
	private const BLOCKING_ORDER = ['living', 'death_unknown', 'death_circa', 'identification_uncertain'];

	/**
	 * @param list<Person> $persons all persons involved in the version
	 * @param int $currentYear the current calendar year
	 * @return Proposal
	 */
	public static function propose(array $persons, int $currentYear): array {
		if ($persons === []) {
			return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => 'no_persons',
				'parameters' => [], 'decisivePersonId' => null, 'blocking' => []];
		}
		$blocking = [];
		$decisive = null;
		$lastYear = null;
		foreach ($persons as $person) {
			$reason = self::blockingReason($person);
			if ($reason !== null) {
				$blocking[] = ['personId' => $person->getId(), 'name' => $person->getName(), 'reason' => $reason];
				continue;
			}
			$year = (int)$person->securedDeathYear();
			if ($lastYear === null || $year > $lastYear) {
				$lastYear = $year;
				$decisive = $person;
			}
		}
		if ($blocking !== [] || $decisive === null || $lastYear === null) {
			$blocking = Sort::byKeys($blocking, array_map(
				fn (array $b): array => [(int)array_search($b['reason'], self::BLOCKING_ORDER, true)], $blocking));
			return ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => $blocking[0]['reason'] ?? 'no_persons',
				'parameters' => [$blocking[0]['name'] ?? ''], 'decisivePersonId' => null, 'blocking' => $blocking];
		}
		$endYear = $lastYear + self::YEARS_AFTER_DEATH;
		$free = $currentYear > $endYear;
		return [
			'status' => $free ? self::FREE : self::PROTECTED,
			'until' => self::yearString($endYear) . '-12-31',
			'freeSince' => self::yearString($endYear + 1) . '-01-01',
			'reason' => $free ? 'ended' : 'running',
			'parameters' => [$decisive->getName(), $lastYear, $endYear],
			'decisivePersonId' => $decisive->getId(),
			'blocking' => [],
		];
	}

	/**
	 * Why a person prevents a secured date, or null when the year of death
	 * is secured.
	 */
	public static function blockingReason(Person $person): ?string {
		return match (true) {
			$person->getDiedPrecision() === 'living' => 'living',
			$person->getDiedPrecision() === 'circa' => 'death_circa',
			$person->securedDeathYear() === null => 'death_unknown',
			($person->getIdentification() ?? 'uncertain') === 'uncertain' => 'identification_uncertain',
			default => null,
		};
	}

	private static function yearString(int $year): string {
		return $year < 0 ? (string)$year : sprintf('%04d', $year);
	}
}
