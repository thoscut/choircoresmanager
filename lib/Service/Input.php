<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;

/**
 * Narrows request data (decoded JSON) to the value types the services work
 * with, at the boundary of each service. Nested arrays or objects are refused
 * where a single value is expected.
 */
final class Input {
	/**
	 * @param array<array-key, mixed> $data field => value
	 * @return array<string, string|int|float|bool|null>
	 * @throws InvalidInputException
	 */
	public static function values(array $data): array {
		$values = [];
		foreach (array_keys($data) as $field) {
			$values[(string)$field] = self::value($data[$field], (string)$field);
		}
		return $values;
	}

	/**
	 * @throws InvalidInputException
	 */
	public static function value(mixed $value, string $field): string|int|float|bool|null {
		if ($value === null || is_scalar($value)) {
			return $value;
		}
		throw new InvalidInputException('"%s": value not allowed.', [$field]);
	}

	/**
	 * Optional text: null for null and blank text.
	 *
	 * @throws InvalidInputException
	 */
	public static function text(mixed $value, string $field): ?string {
		$value = self::value($value, $field);
		if (is_bool($value)) {
			throw new InvalidInputException('"%s" must be text.', [$field]);
		}
		if ($value === null) {
			return null;
		}
		$text = (string)$value;
		return trim($text) === '' ? null : $text;
	}

	/**
	 * Yes or no. Accepts booleans and their usual text forms ("1", "0",
	 * "true", "false", "yes", "no"); anything else is refused.
	 *
	 * @throws InvalidInputException
	 */
	public static function bool(mixed $value, string $field): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_string($value)) {
			$bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
			if (is_bool($bool)) {
				return $bool;
			}
		}
		throw new InvalidInputException('"%s" must be yes or no.', [$field]);
	}

	/**
	 * A JSON list of strings (e.g. group ids); anything else is refused.
	 *
	 * @return list<string>
	 * @throws InvalidInputException
	 */
	public static function stringList(mixed $value, string $field): array {
		if (!is_array($value)) {
			throw new InvalidInputException('"%s": value not allowed.', [$field]);
		}
		$strings = [];
		foreach (array_keys($value) as $key) {
			$entry = self::value($value[$key], $field);
			if (!is_string($entry)) {
				throw new InvalidInputException('"%s": value not allowed.', [$field]);
			}
			$strings[] = $entry;
		}
		return $strings;
	}

	/**
	 * A list of strings: a JSON list, or one string with comma-separated
	 * entries. Empty entries are dropped; "0" is kept.
	 *
	 * @return list<string>
	 * @throws InvalidInputException
	 */
	public static function strings(mixed $value, string $field): array {
		if ($value === null || $value === '') {
			return [];
		}
		if (is_string($value)) {
			$value = explode(',', $value);
		}
		if (!is_array($value)) {
			throw new InvalidInputException('"%s": value not allowed.', [$field]);
		}
		$strings = [];
		foreach (array_keys($value) as $key) {
			$entry = self::value($value[$key], $field);
			if (!is_string($entry) && !is_int($entry)) {
				throw new InvalidInputException('"%s": value not allowed.', [$field]);
			}
			$entry = trim((string)$entry);
			if ($entry !== '') {
				$strings[] = $entry;
			}
		}
		return $strings;
	}
}
