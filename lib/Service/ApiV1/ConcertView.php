<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service\ApiV1;

use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\FieldValue;

/**
 * Concerts and song lists with their programmes in the API for agents (v1),
 * from EventService::list() and EventService::detail().
 *
 * @psalm-import-type CantoriaConcertRow from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaConcert from \OCA\Cantoria\ResponseDefinitions
 * @psalm-import-type CantoriaProgrammeItem from \OCA\Cantoria\ResponseDefinitions
 */
final class ConcertView {
	/**
	 * @param list<array<string, mixed>> $events rows of EventService::list()
	 * @return list<CantoriaConcertRow>
	 */
	public static function rows(array $events): array {
		return array_map(fn (array $event): array => [
			'id' => Values::int($event['id'] ?? null),
			'kind' => Values::string($event['kind'] ?? null),
			'title' => Values::stringOrNull($event['title'] ?? null),
			'date' => Values::stringOrNull($event['event_date'] ?? null),
			'start_time' => Values::stringOrNull($event['start_time'] ?? null),
			'end_time' => Values::stringOrNull($event['end_time'] ?? null),
			'time_zone' => Values::stringOrNull($event['time_zone'] ?? null),
			'location' => Values::stringOrNull($event['location'] ?? null),
			'status' => Values::string($event['status'] ?? null),
			'imported' => Values::bool($event['imported'] ?? null),
			'pieces' => Values::int($event['items'] ?? null),
		], $events);
	}

	/**
	 * Sort key: by date, events without a date last.
	 *
	 * @param CantoriaConcertRow $row
	 * @return list<int|string>
	 */
	public static function key(array $row): array {
		return [$row['date'] ?? '9999-12-31', $row['id']];
	}

	/**
	 * @param list<CantoriaConcertRow> $rows
	 * @return list<CantoriaConcertRow>
	 * @throws InvalidInputException for an unknown kind or status or an invalid date
	 */
	public static function filter(array $rows, ?string $kind, ?string $status, ?string $from, ?string $to): array {
		if ($kind !== null && $kind !== '' && !in_array($kind, Event::KINDS, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Kind']);
		}
		if ($status !== null && $status !== '' && !in_array($status, Event::STATUSES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Status']);
		}
		foreach ([$from, $to] as $date) {
			if ($date !== null && $date !== '' && !FieldValue::isDate($date)) {
				throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Date']);
			}
		}
		return array_values(array_filter($rows, fn (array $row): bool => ($kind === null || $kind === '' || $row['kind'] === $kind)
			&& ($status === null || $status === '' || $row['status'] === $status)
			&& ($from === null || $from === '' || ($row['date'] !== null && $row['date'] >= $from))
			&& ($to === null || $to === '' || ($row['date'] !== null && $row['date'] <= $to))));
	}

	/**
	 * @param array{event: Event, items: list<array<string, string|int|bool|null|list<string>>>, totalDuration: int, missingDurations: int} $detail
	 * @param callable(string): string $translate translates the warnings (English source strings)
	 * @return CantoriaConcert
	 */
	public static function concert(array $detail, callable $translate): array {
		$event = $detail['event'];
		return [
			'id' => $event->getId(),
			'kind' => $event->getKind(),
			'title' => $event->getTitle(),
			'date' => $event->getEventDate(),
			'start_time' => $event->getStartTime(),
			'end_time' => $event->getEndTime(),
			'time_zone' => $event->getTimeZone(),
			'location' => $event->getLocation(),
			'status' => $event->getStatus(),
			'imported' => $event->getImported() === true,
			'is_public' => $event->getIsPublic() === true,
			'notes' => $event->getNotes(),
			'items' => array_map(fn (array $item): array => self::item($item, $translate), $detail['items']),
			'total_duration' => $detail['totalDuration'],
			'missing_durations' => $detail['missingDurations'],
		];
	}

	/**
	 * @param array<string, string|int|bool|null|list<string>> $item
	 * @param callable(string): string $translate
	 * @return CantoriaProgrammeItem
	 */
	private static function item(array $item, callable $translate): array {
		return [
			'id' => Values::int($item['id'] ?? null),
			'position' => Values::int($item['position'] ?? null),
			'copy_id' => Values::intOrNull($item['copy_id'] ?? null),
			'version_id' => Values::intOrNull($item['version_id'] ?? null),
			'work_id' => Values::intOrNull($item['work_id'] ?? null),
			'shelfmark' => Values::stringOrNull($item['shelfmark'] ?? null),
			'title' => Values::stringOrNull($item['title'] ?? null),
			'composer' => Values::stringOrNull($item['composer'] ?? null),
			'arranger_text' => Values::stringOrNull($item['arranger_text'] ?? null),
			'duration' => Values::intOrNull($item['duration'] ?? null),
			'encore' => Values::bool($item['encore'] ?? null),
			'struck' => Values::bool($item['struck'] ?? null),
			'assignment' => Values::string($item['assignment'] ?? null),
			'note' => Values::stringOrNull($item['note'] ?? null),
			'warnings' => array_map($translate, Values::strings($item['warnings'] ?? null)),
		];
	}
}
