<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ContributionMapper;
use OCA\Notenarchiv\Db\Person;
use OCA\Notenarchiv\Db\PersonMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;

/**
 * The public repertoire page (module public_repertoire, milestone 0.7): the
 * works the catalog librarian has chosen for it (`public_repertoire`), as
 * long as a version of the work is confirmed public domain; checked again
 * every time the page is shown, so a later change of the rights takes a
 * work off the page at once.
 *
 * Each entry has the title, the composer and lyricist with their life dates
 * (from the register of persons while its module is on, otherwise the
 * composer as written in the catalog), the scoring of the public domain
 * versions and the links to CPDL and IMSLP. Nothing else: no shelf marks,
 * no copies, no account names, no notes. Nothing is loaded from CPDL or
 * IMSLP; the page only links to them (guideline 9).
 *
 * @psalm-type RepertoirePerson = array{role: string, name: string, lifeDates: ?string}
 * @psalm-type RepertoireEntry = array{title: string, composer: string, persons: list<RepertoirePerson>, scoring: list<string>, cpdl: ?string, imslp: ?string}
 */
class PublicRepertoireService {
	/** Roles of persons shown on the page, in this order */
	private const ROLES = ['composer', 'lyricist'];
	/** Hosts of the links, as in the field registry */
	private const HOSTS = ['cpdl_url' => ['cpdl.org', 'www.cpdl.org'], 'imslp_url' => ['imslp.org', 'www.imslp.org']];

	public function __construct(
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private ContributionMapper $contributions,
		private PersonMapper $persons,
		private Modules $modules,
	) {
	}

	/**
	 * Whether a version makes its work eligible: confirmed public domain and
	 * no internal preservation copy.
	 */
	public static function eligible(WorkVersion $version): bool {
		return $version->getRightsStatus() === 'public_domain' && $version->isConfirmed() && $version->getPreservationCopy() !== true;
	}

	/**
	 * The entries of the page, sorted by title.
	 *
	 * @return list<RepertoireEntry>
	 */
	public function entries(): array {
		$versionsByWork = [];
		foreach ($this->versions->findAllById() as $version) {
			if (self::eligible($version)) {
				$versionsByWork[$version->getWorkId()][] = $version;
			}
		}
		$withPersons = $this->modules->isEnabled('persons');
		$contributions = $withPersons ? $this->contributions->findAllByVersion() : [];
		$persons = $withPersons ? $this->persons->findAllById() : [];

		$entries = [];
		foreach ($this->works->findAllById() as $work) {
			$versions = $versionsByWork[$work->getId()] ?? [];
			if ($work->getPublicRepertoire() !== true || $work->getMergedInto() !== null || $versions === []) {
				continue;
			}
			$entries[] = [
				'title' => trim((string)$work->getTitle()),
				'composer' => trim((string)$work->getComposer()),
				'persons' => self::personsOf($versions, $contributions, $persons),
				'scoring' => array_values(array_unique(array_filter(array_map(
					fn (WorkVersion $v): string => trim((string)$v->getScoring()), $versions), fn (string $s): bool => $s !== ''))),
				'cpdl' => self::link($work, 'cpdl_url'),
				'imslp' => self::link($work, 'imslp_url'),
			];
		}
		usort($entries,
			/**
			 * @param RepertoireEntry $a
			 * @param RepertoireEntry $b
			 */
			fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
		return $entries;
	}

	/**
	 * Life dates as a short text: the years (with "ca." where approximate),
	 * or the text as found.
	 */
	public static function lifeDates(Person $person): ?string {
		$year = function (?string $date, ?string $precision): ?string {
			if ($date === null || $date === '' || $precision === 'unknown' || $precision === 'living') {
				return null;
			}
			$value = preg_match('/^-?\d{1,4}/', $date, $m) === 1 ? $m[0] : null;
			return $value === null ? null : ($precision === 'circa' ? 'ca. ' . $value : $value);
		};
		$born = $year($person->getBorn(), $person->getBornPrecision());
		$died = $year($person->getDied(), $person->getDiedPrecision());
		if ($born !== null || $died !== null) {
			return ($born ?? '?') . '–' . ($died ?? '?');
		}
		$text = trim((string)$person->getLifeDatesText());
		return $text === '' ? null : $text;
	}

	/**
	 * Composer and lyricist of the versions, each person once.
	 *
	 * @param non-empty-list<WorkVersion> $versions
	 * @param array<int, list<\OCA\Notenarchiv\Db\Contribution>> $contributions by version id
	 * @param array<int, Person> $persons by id
	 * @return list<RepertoirePerson>
	 */
	private static function personsOf(array $versions, array $contributions, array $persons): array {
		$result = [];
		foreach (self::ROLES as $role) {
			foreach ($versions as $version) {
				foreach ($contributions[$version->getId()] ?? [] as $contribution) {
					$person = $persons[$contribution->getPersonId()] ?? null;
					$key = $role . ':' . $contribution->getPersonId();
					if ($contribution->getRole() !== $role || $person === null || isset($result[$key])) {
						continue;
					}
					$result[$key] = ['role' => $role, 'name' => $person->getName(), 'lifeDates' => self::lifeDates($person)];
				}
			}
		}
		return array_values($result);
	}

	/**
	 * A link of the work, only if it is an https link to its site.
	 */
	private static function link(Work $work, string $field): ?string {
		$value = $work->getValue($field);
		return is_string($value) && FieldValue::isLinkTo($value, self::HOSTS[$field]) ? $value : null;
	}
}
