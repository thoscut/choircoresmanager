<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service\ApiV1;

/**
 * Narrows values of the services' list rows (arrays with values of unknown
 * type) to the fixed types of the API for agents (v1).
 */
final class Values {
	public static function int(mixed $value): int {
		return self::intOrNull($value) ?? 0;
	}

	public static function intOrNull(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : null;
	}

	public static function string(mixed $value): string {
		return self::stringOrNull($value) ?? '';
	}

	public static function stringOrNull(mixed $value): ?string {
		if (is_string($value)) {
			return $value;
		}
		return is_int($value) || is_float($value) ? (string)$value : null;
	}

	public static function bool(mixed $value): bool {
		return $value === true || $value === 1 || $value === '1';
	}

	/**
	 * A point in time (Unix timestamp) as ISO 8601 in UTC, e.g.
	 * 2026-10-22T12:00:00Z; null for no value.
	 */
	public static function time(mixed $timestamp): ?string {
		$seconds = self::intOrNull($timestamp);
		return $seconds === null || $seconds <= 0 ? null : gmdate('Y-m-d\TH:i:s\Z', $seconds);
	}

	/**
	 * Strings of a list; other values are dropped.
	 *
	 * @return list<string>
	 */
	public static function strings(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		return array_values(array_filter($value, 'is_string'));
	}
}
