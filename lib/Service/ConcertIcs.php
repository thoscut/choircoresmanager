<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Db\Event;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Concerts as calendar entries (iCalendar, RFC 5545).
 *
 * Each concert is one entry with a stable UID, so that a calendar that
 * receives it again updates it instead of adding it twice. A concert with a
 * start time refers to the time zone recorded with it (or, for older
 * entries, the Nextcloud time zone of the account); a concert without a time
 * is an all-day entry (VALUE=DATE).
 *
 * The preparation deadlines (10, 8 and 6 weeks before) can be added as
 * separate all-day entries with their own UIDs and not as alarms: they are
 * work for the conductor and the music librarian that stays visible in the
 * calendar weeks ahead, works in every calendar program (alarms of imported
 * entries are often ignored or fire only once) and does not duplicate the
 * app's own notifications. They are marked "free" so that they block no time.
 *
 * Entries contain no personal data: title, date, time, location, status, a
 * link back to the app; the notes of a concert are left out.
 *
 * @psalm-type Component = array{uid: string, zone: ?\DateTimeZone, year: int, lines: list<string>}
 */
class ConcertIcs {
	/** Start of the count of SEQUENCE (minutes since then of the last change). */
	private const SEQUENCE_EPOCH = 1767225600; // 2026-01-01 00:00:00 UTC

	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
		private IConfig $config,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * A calendar file with the concerts and, if wanted, their deadlines.
	 *
	 * @param list<Event> $events concerts with a full date
	 * @param string|null $name name of the calendar for programs that show one
	 */
	public function document(array $events, bool $deadlines, string $uid, ?string $name): string {
		$components = [];
		foreach ($events as $event) {
			foreach ($this->components($event, $deadlines, $uid) as $component) {
				$components[] = $component;
			}
		}
		return $this->calendar($components, $name);
	}

	/**
	 * The entries of a concert, one calendar object each (CalDAV keeps one
	 * UID per object).
	 *
	 * @return list<array{uid: string, ics: string}>
	 */
	public function objects(Event $event, bool $deadlines, string $uid): array {
		$objects = [];
		foreach ($this->components($event, $deadlines, $uid) as $component) {
			$objects[] = ['uid' => $component['uid'], 'ics' => $this->calendar([$component], null)];
		}
		return $objects;
	}

	/**
	 * Stable UID of a concert (or of one of its deadlines) in this
	 * Nextcloud instance.
	 */
	public function uid(Event $event, ?int $weeks = null): string {
		$instance = substr(hash('sha256', $this->config->getSystemValueString('instanceid', '')), 0, 12);
		return 'notenarchiv-concert-' . $event->getId() . ($weeks === null ? '' : '-deadline-' . $weeks) . '-' . $instance;
	}

	/**
	 * @param list<Component> $components
	 */
	private function calendar(array $components, ?string $name): string {
		$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Notenarchiv//Nextcloud app ' . Application::APP_ID . '//EN', 'CALSCALE:GREGORIAN'];
		if ($name !== null) {
			$lines[] = 'X-WR-CALNAME:' . IcsWriter::text($name);
		}
		// One time zone definition per zone, for all years it is used in.
		$zones = [];
		foreach ($components as $component) {
			$zone = $component['zone'];
			if ($zone === null) {
				continue;
			}
			$known = $zones[$zone->getName()] ?? null;
			$zones[$zone->getName()] = [
				'zone' => $zone,
				'from' => min($known['from'] ?? $component['year'], $component['year']),
				'to' => max($known['to'] ?? $component['year'], $component['year']),
			];
		}
		foreach ($zones as ['zone' => $zone, 'from' => $from, 'to' => $to]) {
			array_push($lines, ...IcsWriter::timezone($zone, $from, $to));
		}
		foreach ($components as $component) {
			array_push($lines, ...$component['lines']);
		}
		$lines[] = 'END:VCALENDAR';
		return IcsWriter::document($lines);
	}

	/**
	 * The concert and, if wanted, its deadlines (not for imported history).
	 *
	 * @return list<Component>
	 */
	private function components(Event $event, bool $deadlines, string $uid): array {
		$date = (string)$event->getEventDate();
		$title = trim((string)$event->getTitle()) !== '' ? (string)$event->getTitle() : $this->l->t('Concert');
		$link = $this->url->linkToRouteAbsolute(Application::APP_ID . '.page.index') . '?event=' . $event->getId();
		$cancelled = $event->getStatus() === Event::CANCELLED;
		$year = (int)substr($date, 0, 4);

		$description = [];
		$description[] = $cancelled
			? $this->l->t('This concert has been cancelled.')
			: $this->l->t('Concert in the music archive, status: %s', [$this->statusLabel($event->getStatus())]);
		$description[] = $this->l->t('Open in the music archive: %s', [$link]);

		$lines = $this->head($event, $this->uid($event));
		$zone = null;
		$start = $event->getStartTime();
		if ($start === null) {
			array_push($lines, ...self::allDay($date));
			$lines[] = 'TRANSP:TRANSPARENT';
		} else {
			$zone = $this->zone($event, $uid);
			$tzid = ';TZID=' . IcsWriter::parameter($zone->getName());
			$lines[] = 'DTSTART' . $tzid . ':' . IcsWriter::localTime($date, $start);
			$end = $event->getEndTime();
			if ($end !== null) {
				$lines[] = 'DTEND' . $tzid . ':' . IcsWriter::localTime($date, $end);
			}
			$lines[] = 'TRANSP:OPAQUE';
		}
		$lines[] = 'SUMMARY:' . IcsWriter::text($title);
		$location = trim((string)$event->getLocation());
		if ($location !== '') {
			$lines[] = 'LOCATION:' . IcsWriter::text($location);
		}
		$lines[] = 'DESCRIPTION:' . IcsWriter::text(implode("\n", $description));
		$lines[] = 'URL:' . $link;
		$lines[] = 'STATUS:' . match ($event->getStatus()) {
			Event::CANCELLED => 'CANCELLED',
			'draft' => 'TENTATIVE',
			default => 'CONFIRMED',
		};
		$lines[] = 'END:VEVENT';
		$components = [['uid' => $this->uid($event), 'zone' => $zone, 'year' => $year, 'lines' => $lines]];

		if (!$deadlines || $event->getImported() === true) {
			return $components;
		}
		$day = $this->formatDate($date);
		foreach (ConcertDeadlines::dates($date) as $weeks => $deadline) {
			$lines = $this->head($event, $this->uid($event, $weeks));
			array_push($lines, ...self::allDay($deadline));
			$lines[] = 'TRANSP:TRANSPARENT';
			$lines[] = 'SUMMARY:' . IcsWriter::text($title . ': ' . $this->l->t(ConcertDeadlines::label($weeks)));
			$lines[] = 'DESCRIPTION:' . IcsWriter::text(implode("\n", [
				$this->l->n('Deadline %n week before the concert on %s.', 'Deadline %n weeks before the concert on %s.', $weeks, [$day]),
				...($cancelled ? [$this->l->t('This concert has been cancelled.')] : []),
				$this->l->t('Open in the music archive: %s', [$link]),
			]));
			$lines[] = 'URL:' . $link;
			if ($cancelled) {
				$lines[] = 'STATUS:CANCELLED';
			}
			$lines[] = 'END:VEVENT';
			$components[] = ['uid' => $this->uid($event, $weeks), 'zone' => null, 'year' => (int)substr($deadline, 0, 4), 'lines' => $lines];
		}
		return $components;
	}

	/**
	 * Start of an entry: UID, time stamps and the sequence number, which
	 * grows with every change of the concert so that programs importing the
	 * file again take the newer version.
	 *
	 * @return list<string>
	 */
	private function head(Event $event, string $entryUid): array {
		$lines = ['BEGIN:VEVENT', 'UID:' . $entryUid, 'DTSTAMP:' . IcsWriter::utc($this->time->getTime())];
		if ($event->getCreated() > 0) {
			$lines[] = 'CREATED:' . IcsWriter::utc($event->getCreated());
		}
		if ($event->getModified() > 0) {
			$lines[] = 'LAST-MODIFIED:' . IcsWriter::utc($event->getModified());
		}
		$lines[] = 'SEQUENCE:' . max(0, intdiv($event->getModified() - self::SEQUENCE_EPOCH, 60));
		return $lines;
	}

	/**
	 * DTSTART and DTEND of an all-day entry (the end is the next day).
	 *
	 * @param string $date YYYY-MM-DD
	 * @return list<string>
	 */
	private static function allDay(string $date): array {
		$next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
		return ['DTSTART;VALUE=DATE:' . IcsWriter::date($date), 'DTEND;VALUE=DATE:' . IcsWriter::date($next)];
	}

	/**
	 * The time zone of a concert's time: the one recorded with it, or the
	 * Nextcloud time zone of the account.
	 */
	private function zone(Event $event, string $uid): \DateTimeZone {
		$name = $event->getTimeZone();
		if ($name !== null && $name !== '') {
			try {
				return new \DateTimeZone($name);
			} catch (\Exception) {
				// unknown to this PHP version: fall back to the account's zone
			}
		}
		return $this->timeZone->getTimeZone(false, $uid);
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			'draft' => $this->l->t('Draft'),
			'fixed' => $this->l->t('Fixed'),
			'sung' => $this->l->t('Sung'),
			Event::CANCELLED => $this->l->t('Cancelled'),
			default => $status,
		};
	}

	/**
	 * A date in the user's language, e.g. "12 December 2026".
	 *
	 * @param string $date YYYY-MM-DD
	 */
	private function formatDate(string $date): string {
		$formatted = $this->l->l('date', new \DateTime($date . ' 12:00:00', new \DateTimeZone('UTC')), ['width' => 'long']);
		return is_string($formatted) && $formatted !== '' ? $formatted : $date;
	}
}
