<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

/**
 * Validation of single field values against the field registry, shared by
 * the import and by edits in the UI.
 *
 * `check()` returns null when the value is valid, otherwise an error message
 * (English source string with `%s` for the field label).
 *
 * @psalm-import-type FieldInfo from Fields
 */
final class FieldValue {
	/**
	 * @param FieldInfo $info
	 */
	public static function check(array $info, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		switch ($info['type']) {
			case Fields::TYPE_NUMBER:
				return is_int($value) ? null : '"%s" must be a whole number.';
			case Fields::TYPE_BOOLEAN:
				return is_bool($value) ? null : '"%s" must be yes or no.';
			case Fields::TYPE_CHOICE:
				return is_string($value) && isset($info['options'][$value]) ? null : '"%s": value not allowed.';
			case Fields::TYPE_DATE:
				if (!is_string($value) || !self::isDate($value)) {
					return '"%s" needs a valid date (YYYY-MM-DD).';
				}
				return null;
			default:
				if (!is_string($value)) {
					return '"%s" must be text.';
				}
				if (isset($info['max']) && mb_strlen($value) > $info['max']) {
					return '"%s" is too long.';
				}
				return null;
		}
	}

	/**
	 * Strict calendar date: rejects e.g. 2026-02-31.
	 */
	public static function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}
}
