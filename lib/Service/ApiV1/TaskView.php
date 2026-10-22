<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service\ApiV1;

use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Exception\InvalidInputException;

/**
 * Tasks and proposals in the API for agents (v1). Account names are left
 * out; `own` tells whether the calling account created the entry.
 *
 * @psalm-import-type NotenarchivTask from \OCA\Notenarchiv\ResponseDefinitions
 * @psalm-import-type NotenarchivProposal from \OCA\Notenarchiv\ResponseDefinitions
 */
final class TaskView {
	/** Which tasks a list shows: for the own roles, open and answered, or all */
	public const SCOPES = ['mine', 'open', 'all'];

	/**
	 * @throws InvalidInputException for an unknown scope
	 */
	public static function scope(string $scope): string {
		if (!in_array($scope, self::SCOPES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Scope']);
		}
		return $scope;
	}

	/**
	 * @param array<string, mixed> $row a task as TaskService lists it (columns plus shelf mark and title)
	 * @return NotenarchivTask
	 */
	public static function task(array $row, string $uid): array {
		return [
			'id' => Values::int($row['id'] ?? null),
			'kind' => Values::string($row['kind'] ?? null),
			'status' => Values::string($row['status'] ?? null),
			'copy_id' => Values::int($row['copy_id'] ?? null),
			'shelfmark' => Values::stringOrNull($row['shelfmark'] ?? null),
			'title' => Values::stringOrNull($row['title'] ?? null),
			'addressee' => Values::string($row['addressee'] ?? null),
			'category' => Values::stringOrNull($row['category'] ?? null),
			'page' => Values::intOrNull($row['page'] ?? null),
			'measure_from' => Values::stringOrNull($row['measure_from'] ?? null),
			'measure_to' => Values::stringOrNull($row['measure_to'] ?? null),
			'voice' => Values::stringOrNull($row['voice'] ?? null),
			'verse' => Values::intOrNull($row['verse'] ?? null),
			'origin' => Values::stringOrNull($row['origin'] ?? null),
			'question' => Values::stringOrNull($row['question'] ?? null),
			'answer' => Values::stringOrNull($row['answer'] ?? null),
			'due' => Values::stringOrNull($row['due'] ?? null),
			'closing_note' => Values::stringOrNull($row['closing_note'] ?? null),
			'created_at' => Values::time($row['created'] ?? null) ?? '',
			'answered_at' => Values::time($row['answered'] ?? null),
			'closed_at' => Values::time($row['closed'] ?? null),
			'own' => ($row['created_by'] ?? null) === $uid,
		];
	}

	/**
	 * Sort key of a task row: the order of the shelf, as TaskService sorts.
	 *
	 * @param array<string, mixed> $row
	 * @return list<int>
	 */
	public static function key(array $row): array {
		return [Values::int($row['binder'] ?? null), Values::int($row['number'] ?? null), Values::int($row['id'] ?? null)];
	}

	/**
	 * @return NotenarchivProposal
	 */
	public static function proposal(Proposal $proposal, string $uid): array {
		try {
			$value = $proposal->value();
		} catch (\JsonException|\UnexpectedValueException) {
			// Stored by an older version or by hand: the raw text.
			$value = $proposal->getNewValue();
		}
		return [
			'id' => $proposal->getId(),
			'level' => $proposal->getObjectType(),
			'object_id' => $proposal->getObjectId(),
			'field' => $proposal->getField(),
			'value' => $value,
			'reason' => $proposal->getReason(),
			'source' => $proposal->getSource(),
			'status' => $proposal->getStatus(),
			'decision_reason' => $proposal->getDecisionReason(),
			'created_at' => Values::time($proposal->getCreated()) ?? '',
			'decided_at' => Values::time($proposal->getDecided()),
			'own' => $proposal->getCreatedBy() === $uid,
		];
	}
}
