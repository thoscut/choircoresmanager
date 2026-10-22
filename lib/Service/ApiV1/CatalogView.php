<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service\ApiV1;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\Task;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\Fields;

/**
 * The catalog in the API for agents (v1): the search over the rows of
 * CatalogService::entries() and one copy from CatalogService::entry(), in
 * the fixed shape of docs/api-v1.md. Only reshapes and filters; reading and
 * every check stay with the services.
 *
 * Account names are left out (fields of PERSONAL_FIELDS): an agent needs
 * the decision, not who took it.
 *
 * @psalm-import-type Entry from \OCA\Notenarchiv\Service\CatalogService
 * @psalm-import-type NotenarchivCatalogRow from \OCA\Notenarchiv\ResponseDefinitions
 * @psalm-import-type NotenarchivCopy from \OCA\Notenarchiv\ResponseDefinitions
 * @psalm-import-type NotenarchivFieldValues from \OCA\Notenarchiv\ResponseDefinitions
 * @psalm-type CatalogFilters = array{q?: ?string, shelfmark?: ?string, binder?: ?int, status?: ?string, rights_status?: ?string,
 *     confirmed?: ?bool, repertoire_class?: ?string, has_musicxml?: ?bool}
 */
final class CatalogView {
	/** Fields that hold account names, per level; never part of API v1 */
	public const PERSONAL_FIELDS = [
		Fields::VERSION => ['confirmed_by', 'confirmed_by_name', 'valid_set_by', 'rule1_checked'],
	];

	/** Rights states of the filter; `none` = no rights status */
	public const RIGHTS_STATES = ['public_domain', 'protected', 'open', 'none'];
	/** Repertoire classes of the filter; `none` = no class */
	public const REPERTOIRE_CLASSES = ['current', 'dormant', 'earlier', 'none'];

	/**
	 * Rows of the search. Internal preservation copies are left out for
	 * accounts that may not view them (RightsPolicy).
	 *
	 * @param list<array<string, mixed>> $entries rows of CatalogService::entries()
	 * @return list<NotenarchivCatalogRow>
	 */
	public static function rows(array $entries, bool $withPreservationCopies): array {
		$rows = [];
		foreach ($entries as $entry) {
			$preservationCopy = Values::bool($entry['preservation_copy'] ?? null);
			if ($preservationCopy && !$withPreservationCopies) {
				continue;
			}
			$rows[] = [
				'id' => Values::int($entry['id'] ?? null),
				'shelfmark' => Values::string($entry['shelfmark'] ?? null),
				'binder' => Values::int($entry['binder'] ?? null),
				'number' => Values::int($entry['number'] ?? null),
				'status' => Values::string($entry['status'] ?? null),
				'work_id' => Values::intOrNull($entry['work_id'] ?? null),
				'version_id' => Values::intOrNull($entry['version_id'] ?? null),
				'title' => Values::stringOrNull($entry['title'] ?? null),
				'composer' => Values::stringOrNull($entry['composer'] ?? null),
				'work_no' => Values::stringOrNull($entry['work_no'] ?? null),
				'arranger_text' => Values::stringOrNull($entry['arranger_text'] ?? null),
				'rights_status' => Values::stringOrNull($entry['rights_status'] ?? null),
				'rights_confirmed' => ($entry['confirmed_at'] ?? null) !== null,
				'preservation_copy' => $preservationCopy,
				'valid_for_choir' => ($entry['valid_since'] ?? null) !== null,
				'repertoire_class' => Values::stringOrNull($entry['repertoire_class'] ?? null),
				'performances' => Values::intOrNull($entry['performances'] ?? null),
				'last_performed_on' => Values::stringOrNull($entry['last_performed_on'] ?? null),
				'has_musicxml' => Values::bool($entry['has_musicxml'] ?? null),
				'files' => Values::int($entry['files'] ?? null),
			];
		}
		return $rows;
	}

	/**
	 * Sort key of a row: the order of the shelf.
	 *
	 * @param NotenarchivCatalogRow $row
	 * @return list<int>
	 */
	public static function key(array $row): array {
		return [$row['binder'], $row['number'], $row['id']];
	}

	/**
	 * The rows that match every given filter. `q` searches shelf mark,
	 * title, composer, arranger and work number (case-insensitive, parts of
	 * words); `shelfmark` is exact ("5-1" also finds "5-01").
	 *
	 * @param list<NotenarchivCatalogRow> $rows
	 * @param CatalogFilters $filters
	 * @return list<NotenarchivCatalogRow>
	 * @throws InvalidInputException for a value a filter does not know
	 */
	public static function filter(array $rows, array $filters): array {
		$status = self::choice($filters['status'] ?? null, array_keys(Fields::COPY_STATUS), 'Status');
		$rightsStatus = self::choice($filters['rights_status'] ?? null, self::RIGHTS_STATES, 'Rights status');
		$repertoire = self::choice($filters['repertoire_class'] ?? null, self::REPERTOIRE_CLASSES, 'Repertoire');
		$query = self::normalize($filters['q'] ?? null);
		$shelfmark = $filters['shelfmark'] ?? null;
		$shelfmark = $shelfmark === null || trim($shelfmark) === '' ? null : trim($shelfmark);
		$binder = $filters['binder'] ?? null;
		$confirmed = $filters['confirmed'] ?? null;
		$hasMusicxml = $filters['has_musicxml'] ?? null;

		return array_values(array_filter($rows, fn (array $row): bool => ($status === null || $row['status'] === $status)
			&& ($rightsStatus === null || ($row['rights_status'] ?? 'none') === $rightsStatus)
			&& ($repertoire === null || ($row['repertoire_class'] ?? 'none') === $repertoire)
			&& ($binder === null || $row['binder'] === $binder)
			&& ($confirmed === null || $row['rights_confirmed'] === $confirmed)
			&& ($hasMusicxml === null || $row['has_musicxml'] === $hasMusicxml)
			&& ($shelfmark === null || self::sameShelfmark($row, $shelfmark))
			&& ($query === '' || str_contains(self::normalize(implode(' ', [$row['shelfmark'], $row['title'] ?? '', $row['composer'] ?? '',
				$row['arranger_text'] ?? '', $row['work_no'] ?? ''])), $query))));
	}

	/**
	 * One copy with its version and work, as far as the account may see it.
	 *
	 * @param Entry $entry CatalogService::entry()
	 * @param callable(string): string $translate translates an English source string
	 * @return NotenarchivCopy
	 */
	public static function copy(array $entry, string $uid, callable $translate): array {
		$work = $entry['work'];
		$version = $entry['version'];
		$copy = $entry['copy'];
		$score = $entry['score'];
		$releases = $entry['releases'];
		$context = ['shelfmark' => $copy->getShelfmark(), 'title' => $work->getTitle()];
		return [
			'id' => $copy->getId(),
			'shelfmark' => $copy->getShelfmark(),
			'binder' => $copy->getBinder(),
			'number' => $copy->getNumber(),
			'status' => $copy->getStatus(),
			'work_id' => $work->getId(),
			'version_id' => $version->getId(),
			'title' => $work->getTitle(),
			'composer' => $work->getComposer(),
			'fields' => [
				'work' => self::fieldValues(Fields::WORK, $work),
				'version' => self::fieldValues(Fields::VERSION, $version),
				'copy' => self::fieldValues(Fields::COPY, $copy),
			],
			'rights' => [
				'status' => $version->getRightsStatus(),
				'confirmed' => $version->isConfirmed(),
				'confirmed_on' => $version->getConfirmedAt(),
				'preservation_copy' => $version->getPreservationCopy() === true,
				'valid_for_choir' => $version->getValidSince() !== null,
				'valid_since' => $version->getValidSince(),
				'publication_status' => $version->getPublicationStatus(),
				'portal' => ['allowed' => $releases['portal'] === null, 'reason' => self::translated($releases['portal'], $translate),
					'on_portal' => $releases['onPortal']],
				'print_station' => ['allowed' => $releases['printStation'] === null,
					'reason' => self::translated($releases['printStation'], $translate)],
			],
			'score' => $score === null ? null : [
				'file_id' => $score['fileId'],
				'file_name' => $score['fileName'],
				'can_view' => $score['canView'],
				'can_play' => $score['canPlay'],
				'reason' => self::translated($score['reason'], $translate),
			],
			'files' => array_map(fn (ArchiveFile $file): array => [
				'id' => $file->getId(),
				'name' => $file->getName(),
				'path' => $file->getPath(),
				'type' => $file->getType(),
				'role' => $file->getRole(),
				'found' => $file->getFound() === true,
			], $entry['files']),
			'other_copies' => $entry['siblings'],
			'tasks' => array_map(fn (Task $task): array => TaskView::task($task->jsonSerialize() + $context, $uid), $entry['tasks']),
			'open_proposals' => array_map(fn (Proposal $proposal): array => TaskView::proposal($proposal, $uid), $entry['proposals']),
		];
	}

	/**
	 * The values of every field of the registry, except account names.
	 *
	 * @return NotenarchivFieldValues
	 */
	public static function fieldValues(string $level, Work|WorkVersion|Copy $entity): array {
		$values = [];
		foreach (array_keys(Fields::all()[$level] ?? []) as $field) {
			if (!in_array($field, self::PERSONAL_FIELDS[$level] ?? [], true)) {
				$values[$field] = $entity->getValue($field);
			}
		}
		return $values;
	}

	/**
	 * @param callable(string): string $translate
	 */
	private static function translated(?string $text, callable $translate): ?string {
		return $text === null ? null : $translate($text);
	}

	/**
	 * @param list<string> $allowed
	 * @throws InvalidInputException
	 */
	private static function choice(?string $value, array $allowed, string $label): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!in_array($value, $allowed, true)) {
			throw new InvalidInputException('"%s": value not allowed.', [$label]);
		}
		return $value;
	}

	/**
	 * @param NotenarchivCatalogRow $row
	 */
	private static function sameShelfmark(array $row, string $shelfmark): bool {
		if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $shelfmark, $parts) === 1) {
			return $row['binder'] === (int)$parts[1] && $row['number'] === (int)$parts[2];
		}
		return mb_strtolower($row['shelfmark']) === mb_strtolower($shelfmark);
	}

	private static function normalize(?string $text): string {
		return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($text ?? '')));
	}
}
