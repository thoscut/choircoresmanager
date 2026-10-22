<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Db;

/**
 * Typed access to values read with the query builder. Depending on the
 * database driver, numbers may arrive as strings.
 */
final class Row {
	public static function int(mixed $value): int {
		if (is_int($value)) {
			return $value;
		}
		if ((is_string($value) && is_numeric($value)) || is_float($value)) {
			return (int)$value;
		}
		throw new \UnexpectedValueException('Expected a number from the database, got ' . get_debug_type($value));
	}

	public static function string(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		throw new \UnexpectedValueException('Expected text from the database, got ' . get_debug_type($value));
	}

	public static function stringOrNull(mixed $value): ?string {
		return $value === null ? null : self::string($value);
	}
}
