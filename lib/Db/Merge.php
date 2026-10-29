<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A merge that can be undone.
 *
 * - kind `work`: the versions of the source work were moved to the target
 *   work; the source work got `merged_into`. `moved_ids` holds the moved
 *   version ids and the programme lines that followed them.
 * - kind `copy`: the copy `source_id` was moved to the version `target_id`
 *   (two copies of the same print). `moved_ids` holds the previous version
 *   and the programme lines that followed the copy.
 *
 * Merges never change a rights status: rights hang on the version.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method int getTargetId()
 * @method void setTargetId(int $targetId)
 * @method string|null getMovedIds()
 * @method void setMovedIds(?string $movedIds)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int|null getUndone()
 * @method void setUndone(?int $undone)
 * @method string|null getUndoneBy()
 * @method void setUndoneBy(?string $undoneBy)
 */
class Merge extends Entity implements JsonSerializable {
	use FieldAccess;

	public const WORK = 'work';
	public const COPY = 'copy';

	/** No default value: the setter only marks a field for the insert when the value changes. */
	protected string $kind = '';
	protected int $sourceId = 0;
	protected int $targetId = 0;
	protected ?string $movedIds = null;
	protected ?string $reason = null;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected ?int $undone = null;
	protected ?string $undoneBy = null;

	public function __construct() {
		foreach (['sourceId', 'targetId', 'created', 'undone'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['kind', 'movedIds', 'reason', 'createdBy', 'undoneBy'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * What was moved, for display: movedWork() or movedCopy() by kind.
	 *
	 * @return array{versions: list<int>, eventItems: list<int>, workNo: ?string}|array{fromVersion: int, eventItems: list<array{id: int, version: ?int, work: ?int}>}
	 */
	public function moved(): array {
		return $this->kind === self::WORK ? $this->movedWork() : $this->movedCopy();
	}

	/**
	 * Merge of works: the moved versions and programme lines, and the work
	 * number the merge gave to the target work.
	 *
	 * @return array{versions: list<int>, eventItems: list<int>, workNo: ?string}
	 */
	public function movedWork(): array {
		$data = $this->decoded();
		return [
			'versions' => self::intList($data['versions'] ?? null),
			'eventItems' => self::intList($data['eventItems'] ?? null),
			'workNo' => self::stringOrNull($data['workNo'] ?? null),
		];
	}

	/**
	 * Move of a copy: the former version and, per programme line that
	 * followed the copy, its former version and work.
	 *
	 * @return array{fromVersion: int, eventItems: list<array{id: int, version: ?int, work: ?int}>}
	 */
	public function movedCopy(): array {
		$data = $this->decoded();
		return ['fromVersion' => self::intOrNull($data['fromVersion'] ?? null) ?? 0, 'eventItems' => self::itemEntries($data['eventItems'] ?? null)];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function decoded(): array {
		if ($this->movedIds === null || $this->movedIds === '') {
			return [];
		}
		return self::arrayOrEmpty(json_decode($this->movedIds, true, 512, JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private static function arrayOrEmpty(mixed $value): array {
		return is_array($value) ? $value : [];
	}

	/**
	 * @return list<int>
	 */
	private static function intList(mixed $value): array {
		$result = [];
		if (is_array($value)) {
			foreach (array_keys($value) as $key) {
				$id = self::intOrNull($value[$key]);
				if ($id !== null) {
					$result[] = $id;
				}
			}
		}
		return $result;
	}

	/**
	 * @return list<array{id: int, version: ?int, work: ?int}>
	 */
	private static function itemEntries(mixed $entries): array {
		$items = [];
		if (is_array($entries)) {
			foreach (array_keys($entries) as $key) {
				$item = self::itemEntry($entries[$key]);
				if ($item !== null) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	private static function stringOrNull(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/**
	 * @return array{id: int, version: ?int, work: ?int}|null
	 */
	private static function itemEntry(mixed $entry): ?array {
		if (!is_array($entry)) {
			return null;
		}
		$id = self::intOrNull($entry['id'] ?? null);
		return $id === null ? null : ['id' => $id, 'version' => self::intOrNull($entry['version'] ?? null), 'work' => self::intOrNull($entry['work'] ?? null)];
	}

	private static function intOrNull(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int)$value : null;
	}
}
