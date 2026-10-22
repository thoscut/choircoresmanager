<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ContributionMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\PersonMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;

/**
 * Finding copies by shelf mark, title and person, for the search of
 * Nextcloud (module "unified_search", milestone 0.8).
 *
 * The rules follow the search field of the app (src/catalog.ts): a shelf
 * mark ("5-1", "5 / 01") finds exactly that copy, whatever its status. Any
 * other input finds copies whose title, composer, arranger text or persons
 * of the register contain every word, ignoring case and accents. Active
 * copies come first, then in shelf order.
 *
 * Internal preservation copies are left out for accounts that may not view
 * them (RightsPolicy), as in the API for agents. Who may search at all is
 * checked by the caller.
 *
 * @psalm-type Hit = array{copyId: int, shelfmark: string, status: string, title: ?string, composer: ?string, arranger: ?string, preservationCopy: bool}
 */
class CatalogSearch {
	/** Words of a search that are looked at; more are ignored */
	public const MAX_WORDS = 10;

	public function __construct(
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private PersonMapper $persons,
		private ContributionMapper $contributions,
		private Modules $modules,
	) {
	}

	/**
	 * @return list<Hit>
	 */
	public function find(string $term, bool $withPreservationCopies): array {
		$shelfmark = self::shelfmark($term);
		$words = $shelfmark === null ? array_slice(self::words($term), 0, self::MAX_WORDS) : [];
		if ($shelfmark === null && $words === []) {
			return [];
		}
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$names = $shelfmark === null && $this->modules->isEnabled('persons') ? $this->personNames() : [];

		$hits = [];
		$keys = [];
		foreach ($this->copies->findAll() as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			$preservation = $version?->getPreservationCopy() === true;
			if ($preservation && !$withPreservationCopies) {
				continue;
			}
			$work = CatalogService::listedWork($version === null ? null : ($works[$version->getWorkId()] ?? null), $works);
			if ($shelfmark !== null) {
				if (self::shelfmark($copy->getShelfmark()) !== $shelfmark) {
					continue;
				}
			} else {
				$text = self::normalize(implode(' ', array_filter([$work?->getTitle(), $work?->getComposer(), $version?->getArrangerText(),
					...($names[$copy->getVersionId()] ?? [])], fn (?string $s): bool => $s !== null && $s !== '')));
				if (!self::containsAll($text, $words)) {
					continue;
				}
			}
			$hits[] = [
				'copyId' => $copy->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'status' => $copy->getStatus(),
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'arranger' => $version?->getArrangerText(),
				'preservationCopy' => $preservation,
			];
			$keys[] = [$copy->getStatus() === 'active' ? 0 : 1, $copy->getBinder(), $copy->getNumber()];
		}
		return Sort::byKeys($hits, $keys);
	}

	/**
	 * A shelf mark typed by a user ("5-1", "5 - 01", "5/1") in canonical
	 * form ("5-1"), or null when the input is no shelf mark.
	 */
	public static function shelfmark(string $input): ?string {
		if (preg_match('/^(\d{1,3})\s*[-\/.]\s*(\d{1,5})$/', trim($input), $m) !== 1) {
			return null;
		}
		return (int)$m[1] . '-' . (int)$m[2];
	}

	/**
	 * Lower case without accents, for comparing ("Ännchen" => "annchen").
	 */
	public static function normalize(string $text): string {
		if (class_exists(\Normalizer::class)) {
			$decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
			$text = preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $text) ?? $text;
		}
		return mb_strtolower(trim($text));
	}

	/**
	 * @return list<string> the words of a search, normalized
	 */
	public static function words(string $term): array {
		return array_values(array_filter(preg_split('/\s+/u', self::normalize($term)) ?: [], fn (string $w): bool => $w !== ''));
	}

	/**
	 * @param list<string> $words
	 */
	public static function containsAll(string $text, array $words): bool {
		foreach ($words as $word) {
			if (!str_contains($text, $word)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Names of the persons of the register involved in each version.
	 *
	 * @return array<int, list<string>> version id => names
	 */
	private function personNames(): array {
		$persons = $this->persons->findAllById();
		$names = [];
		foreach ($this->contributions->findAllByVersion() as $versionId => $list) {
			foreach ($list as $contribution) {
				$person = $persons[$contribution->getPersonId()] ?? null;
				if ($person !== null) {
					$names[$versionId][] = $person->getName();
				}
			}
		}
		return $names;
	}
}
