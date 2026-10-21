<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Utility;

/**
 * Helper class for Date and DateTime operations.
 *
 * This helper provides clear separation between date-only operations (day precision)
 * and datetime operations (time precision). Since PHP does not have a separate
 * DateImmutable class, both use DateTimeImmutable internally, but the methods
 * clearly indicate whether they work with dates or datetimes.
 *
 * Date methods:
 * - Always normalize time to midnight UTC
 * - Used for date-only fields (e.g., validFrom, validTo)
 * - Format as 'Y-m-d' when serializing
 *
 * DateTime methods:
 * - Preserve time information
 * - Used for timestamp fields (e.g., createdAt, updatedAt)
 * - Format as 'Y-m-d H:i:s' or ISO 8601 when serializing
 */
class DateTimeHelper {
	/**
	 * Parse a date string in Y-m-d format to DateTimeImmutable.
	 * This method is for DATE values (not datetime).
	 * Time component is always set to midnight UTC.
	 *
	 * @param string $dateStr Date string in Y-m-d format (e.g., '2024-12-07')
	 * @return \DateTimeImmutable Date normalized to midnight UTC
	 * @throws \InvalidArgumentException If the date string is invalid
	 */
	public static function parseDate(string $dateStr): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr, new \DateTimeZone('UTC'));
		if ($date === false) {
			throw new \InvalidArgumentException('Invalid date format. Use Y-m-d.');
		}
		// Reset time to midnight to ensure we're working with date-only values
		return $date->setTime(0, 0, 0);
	}

	/**
	 * Parse a date string that must be exactly in YYYY-MM-DD format and a valid calendar date.
	 * Unlike parseDate(), values such as '2024-2-3' or '2024-02-30' are rejected.
	 *
	 * @param string $dateStr Date string in YYYY-MM-DD format (e.g., '2024-12-07')
	 * @return \DateTimeImmutable Date normalized to midnight UTC
	 * @throws \InvalidArgumentException If the date string is not a valid YYYY-MM-DD date
	 */
	public static function parseStrictDate(string $dateStr): \DateTimeImmutable {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $dateStr, $matches) !== 1
			|| !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
			throw new \InvalidArgumentException('Invalid date. Use YYYY-MM-DD.');
		}
		return self::parseDate($dateStr);
	}

	/**
	 * Create a date for today (midnight UTC).
	 * This method is for DATE values (not datetime).
	 *
	 * @return \DateTimeImmutable Today's date at midnight UTC
	 */
	public static function today(): \DateTimeImmutable {
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		return $now->setTime(0, 0, 0);
	}

	/**
	 * Normalize a DateTimeImmutable to a date (midnight UTC).
	 * This strips any time component, leaving only the date.
	 *
	 * @param \DateTimeImmutable $dateTime DateTime to normalize
	 * @return \DateTimeImmutable Date normalized to midnight UTC
	 * @psalm-suppress PossiblyUnusedMethod - Public API for future use
	 */
	public static function toDate(\DateTimeImmutable $dateTime): \DateTimeImmutable {
		return $dateTime->setTime(0, 0, 0);
	}

	/**
	 * Validate that a date range is valid (from <= to).
	 * Both parameters should be date values (normalized to midnight).
	 *
	 * @param \DateTimeImmutable $validFrom Start date of the range
	 * @param \DateTimeImmutable|null $validTo End date of the range (null = open-ended)
	 * @throws \InvalidArgumentException If validTo is before validFrom
	 */
	public static function validateDateRange(\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo): void {
		if ($validTo !== null && $validTo < $validFrom) {
			throw new \InvalidArgumentException('valid_to must be on or after valid_from');
		}
	}
}
