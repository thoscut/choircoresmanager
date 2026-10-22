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
 * Special cases (milestone 0.8), switched on per version by the catalog
 * librarian; both protect for 25 years after the end of the year in which
 * the edition or the work first appeared (§ 69 UrhG):
 * - scientific edition (§ 70 UrhG): an edition of works or texts that are
 *   not protected, made by scholarly work and clearly different from
 *   earlier editions (e.g. an urtext or a critical edition);
 * - posthumous work (§ 71 UrhG): a work whose protection has ended and that
 *   appeared (or was performed in public) for the first time afterwards.
 * With a special case, persons involved only as editors count with these
 * 25 years instead of 70 years after their death; every other person still
 * counts as usual, and the later of both ends decides. Without the year the
 * special term is unknown, and unknown means protected.
 *
 * Result:
 * - `status`: `free` (protection has ended), `protected` (ends at a known
 *   date) or `unknown` (to be treated as protected)
 * - `until`: last day of protection (YYYY-12-31) when known
 * - `freeSince`: first free day (YYYY-01-01) when known
 * - `reason`: key of the explanation, `parameters`: values for it
 * - `blocking`: persons (or the missing year, without a person) that
 *   prevent a date, each with its reason key
 * - `special`: only with special cases: each case with the year and the
 *   end of its term
 *
 * Pure function without Nextcloud dependencies, so that it can be unit
 * tested.
 *
 * @psalm-type Blocking = array{personId: ?int, name: string, reason: string}
 * @psalm-type SpecialTerm = array{case: string, year: ?int, until: ?string}
 * @psalm-type Proposal = array{status: string, until: ?string, freeSince: ?string, reason: string, parameters: list<string|int>, decisivePersonId: ?int, blocking: list<Blocking>, persons?: int, special?: list<SpecialTerm>}
 */
final class ProtectionTerm {
	public const YEARS_AFTER_DEATH = 70;
	/** Term of the special cases after the year of the first appearance (§§ 70, 71 UrhG) */
	public const YEARS_AFTER_PUBLICATION = 25;

	public const FREE = 'free';
	public const PROTECTED = 'protected';
	public const UNKNOWN = 'unknown';

	/** Scientific edition, § 70 UrhG (version field of the same name) */
	public const SCIENTIFIC_EDITION = 'scientific_edition';
	/** Posthumous work, § 71 UrhG (version field of the same name) */
	public const POSTHUMOUS_WORK = 'posthumous_work';
	/** The special cases in the order of the act */
	public const SPECIAL_CASES = [self::SCIENTIFIC_EDITION, self::POSTHUMOUS_WORK];

	/** Role of a contribution that a special case counts with its own term */
	public const EDITOR_ROLE = 'editor';

	/** Reasons why no date can be given, most serious first */
	private const BLOCKING_ORDER = ['living', 'death_unknown', 'death_circa', 'identification_uncertain', 'year_missing'];

	/**
	 * @param list<Person> $persons all persons involved in the version
	 * @param int $currentYear the current calendar year
	 * @param list<string> $cases special cases switched on for the version (SPECIAL_CASES)
	 * @param ?int $publishedYear year in which the edition or the work first appeared
	 * @param list<int> $editorIds persons involved in the version only as editors
	 * @return Proposal
	 */
	public static function propose(array $persons, int $currentYear, array $cases = [], ?int $publishedYear = null, array $editorIds = []): array {
		$cases = array_values(array_intersect(self::SPECIAL_CASES, $cases));
		if ($cases === []) {
			return self::byLifeDates($persons, $currentYear);
		}
		$counted = array_values(array_filter($persons, fn (Person $person): bool => !in_array($person->getId(), $editorIds, true)));
		$proposal = $counted === [] && $persons !== []
			? ['status' => self::UNKNOWN, 'until' => null, 'freeSince' => null, 'reason' => 'only_editors',
				'parameters' => [], 'decisivePersonId' => null, 'blocking' => []]
			: self::byLifeDates($counted, $currentYear);

		$endYear = $publishedYear === null ? null : $publishedYear + self::YEARS_AFTER_PUBLICATION;
		$special = array_map(fn (string $case): array => ['case' => $case, 'year' => $publishedYear,
			'until' => $endYear === null ? null : self::yearString($endYear) . '-12-31'], $cases);

		if ($endYear === null) {
			// Without the year the special term is unknown: unknown means protected.
			$blocking = self::sorted([...$proposal['blocking'], ['personId' => null, 'name' => '', 'reason' => 'year_missing']]);
			// No persons (or only editors) stays the main reason; the missing year is listed.
			$keepReason = $proposal['status'] === self::UNKNOWN && $proposal['blocking'] === [];
			return [
				'status' => self::UNKNOWN,
				'until' => null,
				'freeSince' => null,
				'reason' => $keepReason ? $proposal['reason'] : $blocking[0]['reason'],
				'parameters' => $keepReason ? $proposal['parameters'] : [$blocking[0]['name']],
				'decisivePersonId' => null,
				'blocking' => $blocking,
				'special' => $special,
			];
		}
		if ($proposal['status'] === self::UNKNOWN || $endYear <= self::untilYear((string)$proposal['until'])) {
			// The persons decide: their protection is unknown or ends later.
			return $proposal + ['special' => $special];
		}
		$free = $currentYear > $endYear;
		return [
			'status' => $free ? self::FREE : self::PROTECTED,
			'until' => self::yearString($endYear) . '-12-31',
			'freeSince' => self::yearString($endYear + 1) . '-01-01',
			'reason' => $cases[0] . ($free ? '_ended' : '_running'),
			'parameters' => [(int)$publishedYear, $endYear],
			'decisivePersonId' => null,
			'blocking' => [],
			'special' => $special,
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

	/**
	 * The proposal by the life dates of the persons alone (§§ 64, 65 UrhG).
	 *
	 * @param list<Person> $persons
	 * @return Proposal
	 */
	private static function byLifeDates(array $persons, int $currentYear): array {
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
			$blocking = self::sorted($blocking);
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
	 * @param list<array{personId: ?int, name: string, reason: string}> $blocking
	 * @return list<array{personId: ?int, name: string, reason: string}> most serious reason first
	 */
	private static function sorted(array $blocking): array {
		return Sort::byKeys($blocking, array_map(
			fn (array $b): array => [(int)array_search($b['reason'], self::BLOCKING_ORDER, true)], $blocking));
	}

	/**
	 * The year of a last day of protection ("1861-12-31", "-0008-12-31").
	 */
	private static function untilYear(string $until): int {
		return (int)substr($until, 0, -6);
	}

	private static function yearString(int $year): string {
		return $year < 0 ? (string)$year : sprintf('%04d', $year);
	}
}
