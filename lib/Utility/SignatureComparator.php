<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Utility;

/**
 * Compares signatures in natural order, so that "1-9" sorts before "1-10" and "10-1".
 *
 * Mirrors compareSignatures() in src/utils/repertoireColumnUtils.ts.
 */
final class SignatureComparator {
	/**
	 * Compare two signatures; empty signatures sort last.
	 *
	 * @param string|null $a
	 * @param string|null $b
	 * @return int Negative if $a sorts first, positive if $b sorts first, 0 if equal
	 */
	public static function compare(?string $a, ?string $b): int {
		$a = $a === '' ? null : $a;
		$b = $b === '' ? null : $b;
		if ($a === null || $b === null) {
			return ($a === null ? 1 : 0) - ($b === null ? 1 : 0);
		}

		$partsA = self::parts($a);
		$partsB = self::parts($b);
		$count = min(count($partsA), count($partsB));
		for ($i = 0; $i < $count; $i++) {
			$partA = $partsA[$i];
			$partB = $partsB[$i];
			if ($partA === $partB) {
				continue;
			}
			if (ctype_digit($partA) && ctype_digit($partB)) {
				$result = self::compareNumbers($partA, $partB);
				if ($result === 0) {
					// e.g. "05" and "5"
					continue;
				}
				return $result;
			}
			return strcmp($partA, $partB);
		}
		return count($partsA) - count($partsB);
	}

	/**
	 * Split a signature at separators into runs of digits and non-digits.
	 *
	 * @return list<string> Parts, non-digit parts in lower case
	 */
	private static function parts(string $signature): array {
		$parts = [];
		$segments = preg_split('/[-.\/\s]+/u', $signature, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($segments === false ? [] : $segments as $segment) {
			preg_match_all('/\d+|\D+/u', $segment, $matches);
			foreach ($matches[0] as $part) {
				$parts[] = ctype_digit($part) ? $part : mb_strtolower($part);
			}
		}
		return $parts;
	}

	/**
	 * Compare two digit strings numerically without integer overflow.
	 */
	private static function compareNumbers(string $a, string $b): int {
		$a = ltrim($a, '0');
		$b = ltrim($b, '0');
		if (strlen($a) !== strlen($b)) {
			return strlen($a) - strlen($b);
		}
		return strcmp($a, $b);
	}
}
