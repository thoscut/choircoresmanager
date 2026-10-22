<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Event;

/**
 * The preparation deadlines of a planned concert: 10 weeks before it the
 * programme and the valid versions are fixed, 8 weeks before it the rights
 * of all pieces are clarified, 6 weeks before it the concert binders are
 * prepared; after it, what was sung is confirmed.
 *
 * One place for the daily notifications, the start page "due", the
 * dashboard widget and the calendar entries.
 */
final class ConcertDeadlines {
	/** Weeks before the concert, the earliest first. */
	public const WEEKS = [10, 8, 6];

	/** What is due at each stage (English source strings). */
	public const LABELS = [
		'10' => 'Fix the programme and the valid versions',
		'8' => 'Clarify the rights of all pieces',
		'6' => 'Prepare the concert binders',
		'confirm' => 'Confirm what was sung',
	];

	/**
	 * What is due at a stage ('10', '8', '6' or 'confirm'), as English source
	 * string; '' for an unknown stage.
	 */
	public static function label(string|int $stage): string {
		return self::LABELS[(string)$stage] ?? '';
	}

	/**
	 * Whether the deadlines apply: concerts planned in the app (not imported
	 * history, not cancelled) with a full date.
	 */
	public static function apply(Event $event): bool {
		return $event->getKind() === Event::CONCERT
			&& $event->getImported() !== true
			&& $event->getStatus() !== Event::CANCELLED
			&& FieldValue::isDate((string)$event->getEventDate());
	}

	/**
	 * What is due for a concert on a given day: '10', '8' or '6' within the
	 * weeks before it, 'confirm' after it until it is confirmed as sung, ''
	 * otherwise.
	 *
	 * @param string $today YYYY-MM-DD
	 */
	public static function stage(Event $event, string $today): string {
		if (!self::apply($event) || $event->getStatus() === 'sung') {
			return '';
		}
		$days = (int)(new \DateTimeImmutable($today))->diff(new \DateTimeImmutable((string)$event->getEventDate()))->format('%r%a');
		return match (true) {
			$days < 0 => 'confirm',
			$days <= 42 => '6',
			$days <= 56 => '8',
			$days <= 70 => '10',
			default => '',
		};
	}

	/**
	 * The days of the deadlines of a concert.
	 *
	 * @param string $concertDate YYYY-MM-DD
	 * @return array<int, string> weeks before => YYYY-MM-DD
	 */
	public static function dates(string $concertDate): array {
		$day = new \DateTimeImmutable($concertDate);
		$dates = [];
		foreach (self::WEEKS as $weeks) {
			$dates[$weeks] = $day->modify('-' . (7 * $weeks) . ' days')->format('Y-m-d');
		}
		return $dates;
	}
}
