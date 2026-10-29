<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service\ApiV1;

use OCA\Cantoria\Exception\InvalidInputException;

/**
 * Pages of a list in the API for agents (v1): `limit` entries after the
 * position a `cursor` names.
 *
 * The cursor is opaque for clients: the sort key of the last entry of the
 * page before (e.g. binder, number and id of a copy), encoded as base64url
 * JSON. A page therefore stays correct when entries are added or removed
 * between two calls; it never repeats or skips an entry that stays. A cursor
 * that was not made here is refused (400), never trusted.
 */
final class Page {
	public const DEFAULT_LIMIT = 25;
	public const MAX_LIMIT = 100;
	private const MAX_CURSOR_LENGTH = 512;

	/**
	 * Sorts the rows by their key and returns the page after the cursor.
	 *
	 * @template T
	 * @param list<T> $rows
	 * @param callable(T): list<int|string> $key sort key of a row, unique within the list
	 * @param string|null $cursor `next_cursor` of the page before, or null for the first page
	 * @return array{items: list<T>, total: int, next_cursor: ?string}
	 * @throws InvalidInputException for a limit out of range or an invalid cursor
	 */
	public static function of(array $rows, callable $key, ?string $cursor, int $limit): array {
		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new InvalidInputException('The limit must be between 1 and %s.', [self::MAX_LIMIT]);
		}
		$after = $cursor === null || $cursor === '' ? null : self::decode($cursor);
		$keyed = [];
		foreach ($rows as $row) {
			$rowKey = $key($row);
			if ($after === null || ($rowKey <=> $after) > 0) {
				$keyed[] = ['key' => $rowKey, 'row' => $row];
			}
		}
		usort($keyed, fn (array $a, array $b): int => $a['key'] <=> $b['key']);
		$items = [];
		$lastKey = null;
		foreach (array_slice($keyed, 0, $limit) as $entry) {
			$items[] = $entry['row'];
			$lastKey = $entry['key'];
		}
		return [
			'items' => $items,
			'total' => count($rows),
			'next_cursor' => count($keyed) > $limit && $lastKey !== null ? self::encode($lastKey) : null,
		];
	}

	/**
	 * @param list<int|string> $key
	 */
	public static function encode(array $key): string {
		return rtrim(strtr(base64_encode(json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
	}

	/**
	 * @return list<int|string>
	 * @throws InvalidInputException
	 */
	public static function decode(string $cursor): array {
		$invalid = new InvalidInputException('This cursor is not valid. Request the first page again without a cursor.');
		if (strlen($cursor) > self::MAX_CURSOR_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
			throw $invalid;
		}
		$json = base64_decode(strtr($cursor, '-_', '+/'), true);
		try {
			$key = $json === false ? null : json_decode($json, false, 2, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw $invalid;
		}
		if (!is_array($key) || $key === [] || !array_is_list($key)) {
			throw $invalid;
		}
		$values = [];
		foreach ($key as $value) {
			if (!is_int($value) && !is_string($value)) {
				throw $invalid;
			}
			$values[] = $value;
		}
		return $values;
	}
}
