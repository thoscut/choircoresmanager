<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

/**
 * Writes iCalendar data (RFC 5545): content lines ending with CRLF, folded
 * after 75 octets without splitting a UTF-8 character, TEXT values escaped,
 * dates, UTC times and local times with their time zone definition
 * (VTIMEZONE built from PHP's time zone database).
 *
 * Pure functions; the concert specific content is built by ConcertIcs.
 */
final class IcsWriter {
	public const CRLF = "\r\n";

	/** Maximum length of a content line in octets, without the line break. */
	private const LINE_OCTETS = 75;

	/**
	 * A complete iCalendar document: every line folded, CRLF after every
	 * line (also the last one).
	 *
	 * @param list<string> $lines unfolded content lines
	 */
	public static function document(array $lines): string {
		return implode(self::CRLF, array_map(self::fold(...), $lines)) . self::CRLF;
	}

	/**
	 * Folds a content line: after at most 75 octets a CRLF and one space
	 * follow (RFC 5545 section 3.1). A multi-octet UTF-8 character is never
	 * split.
	 */
	public static function fold(string $line): string {
		$parts = [];
		$limit = self::LINE_OCTETS;
		while (strlen($line) > $limit) {
			$cut = $limit;
			// Do not cut before a continuation octet (10xxxxxx) of a character.
			while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}
			if ($cut === 0) {
				$cut = $limit;
			}
			$parts[] = substr($line, 0, $cut);
			$line = substr($line, $cut);
			// A continuation line starts with the space, which counts too.
			$limit = self::LINE_OCTETS - 1;
		}
		$parts[] = $line;
		return implode(self::CRLF . ' ', $parts);
	}

	/**
	 * A TEXT value (RFC 5545 section 3.3.11): backslash, semicolon and
	 * comma escaped, line breaks as "\n". Other control characters are not
	 * allowed in TEXT and become spaces; invalid UTF-8 is replaced.
	 */
	public static function text(string $value): string {
		$value = mb_scrub($value, 'UTF-8');
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		$value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $value) ?? '';
		return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
	}

	/**
	 * A parameter value (e.g. of TZID): quoted when it contains a colon,
	 * semicolon or comma; double quotes are not allowed in it.
	 */
	public static function parameter(string $value): string {
		$value = str_replace('"', '', $value);
		return preg_match('/[:;,]/', $value) === 1 ? '"' . $value . '"' : $value;
	}

	/**
	 * A DATE value.
	 *
	 * @param string $date YYYY-MM-DD
	 */
	public static function date(string $date): string {
		return str_replace('-', '', $date);
	}

	/**
	 * A DATE-TIME value in UTC ("…Z").
	 */
	public static function utc(int $timestamp): string {
		return gmdate('Ymd\THis\Z', $timestamp);
	}

	/**
	 * A local DATE-TIME value, used with TZID.
	 *
	 * @param string $date YYYY-MM-DD
	 * @param string $time HH:MM
	 */
	public static function localTime(string $date, string $time): string {
		return self::date($date) . 'T' . str_replace(':', '', $time) . '00';
	}

	/**
	 * The definition of a time zone (VTIMEZONE) for the years given: one
	 * observance for the state at the start of the first year and one for
	 * every change (daylight saving time) up to the end of the last year,
	 * taken from PHP's time zone database. Every TZID used in a document
	 * needs such a definition (RFC 5545 section 3.6.5).
	 *
	 * @return list<string> content lines
	 */
	public static function timezone(\DateTimeZone $zone, int $fromYear, int $toYear): array {
		$begin = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $fromYear), $zone))->getTimestamp();
		$end = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $toYear + 1), $zone))->getTimestamp();
		$transitions = $zone->getTransitions($begin, $end);
		if ($transitions === false || $transitions === []) {
			$offset = $zone->getOffset(new \DateTimeImmutable('@' . $begin));
			$transitions = [['ts' => $begin, 'offset' => $offset, 'isdst' => false, 'abbr' => '']];
		}
		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $zone->getName()];
		$previous = null;
		foreach ($transitions as $transition) {
			$offset = $transition['offset'];
			$from = $previous ?? $offset;
			$kind = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';
			$lines[] = 'BEGIN:' . $kind;
			// The local time of the change, still in the offset before it.
			$lines[] = 'DTSTART:' . gmdate('Ymd\THis', $transition['ts'] + $from);
			$lines[] = 'TZOFFSETFROM:' . self::offset($from);
			$lines[] = 'TZOFFSETTO:' . self::offset($offset);
			if ($transition['abbr'] !== '') {
				$lines[] = 'TZNAME:' . self::text($transition['abbr']);
			}
			$lines[] = 'END:' . $kind;
			$previous = $offset;
		}
		$lines[] = 'END:VTIMEZONE';
		return $lines;
	}

	/**
	 * A UTC offset ("+0100", "-0330", "+0000"); seconds only when needed.
	 */
	private static function offset(int $seconds): string {
		$sign = $seconds < 0 ? '-' : '+';
		$seconds = abs($seconds);
		$text = sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
		return $seconds % 60 === 0 ? $text : $text . sprintf('%02d', $seconds % 60);
	}
}
