<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsEnabled;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Concerts in the calendar: calendar files to download and entries in the
 * account's own Nextcloud calendars.
 *
 * Reading (the files) is open to every account with access to the archive;
 * adding entries to one's own calendar needs a role. There is deliberately
 * no public subscription link: a feed reachable with a token would be a new
 * way into the archive data and is a decision for the choir.
 *
 * Nextcloud's calendar interface for apps can only create entries. An entry
 * whose UID is already in one of the account's writable calendars is
 * therefore not created again; the result names it with its CalDAV path and
 * the new content, and the browser of the logged-in account updates it there
 * (a plain CalDAV PUT, see docs/api.md).
 *
 * @psalm-type Update = array{calendar: string, path: string, ics: string}
 * @psalm-type AddResult = array{calendar: string, concerts: int, created: int, updates: list<Update>, skipped: int, failed: int}
 */
class ConcertCalendarService {
	public function __construct(
		private EventMapper $events,
		private ConcertIcs $ics,
		private IManager $calendars,
		private Roles $roles,
		private RightsService $rights,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Calendar file of one concert.
	 *
	 * @return array{name: string, content: string}
	 */
	public function concertFile(int $eventId, bool $deadlines, string $uid): array {
		$this->roles->requireAccess($uid);
		$event = $this->concert($eventId);
		return ['name' => 'concert-' . $eventId . '.ics', 'content' => $this->ics->document([$event], $deadlines, $uid, null)];
	}

	/**
	 * Calendar file of all upcoming concerts (from today on, planned in the
	 * app). Cancelled ones are included, marked as cancelled, so that a
	 * calendar program that imports the file again updates them.
	 *
	 * @return array{name: string, content: string}
	 */
	public function upcomingFile(bool $deadlines, string $uid): array {
		$this->roles->requireAccess($uid);
		$events = $this->upcoming($uid);
		if ($events === []) {
			throw new InvalidInputException('There are no upcoming concerts.');
		}
		return ['name' => 'concerts.ics', 'content' => $this->ics->document($events, $deadlines, $uid, $this->l->t('Concerts of the music archive'))];
	}

	/**
	 * The account's calendars that can take new entries.
	 *
	 * @return list<array{uri: string, name: string, color: ?string}>
	 */
	public function writableCalendars(string $uid): array {
		$this->roles->requireRole($uid, Roles::ALL);
		return array_map(fn (ICreateFromString $calendar): array => [
			'uri' => $calendar->getUri(),
			'name' => self::name($calendar),
			'color' => $calendar->getDisplayColor(),
		], $this->writable($uid));
	}

	/**
	 * Adds a concert (or, with $eventId null, all upcoming concerts) to one
	 * of the account's calendars. An entry that is already in one of the
	 * account's writable calendars is listed under `updates` instead of being
	 * created again; a cancelled concert is only updated, never added.
	 *
	 * @return AddResult
	 */
	public function add(?int $eventId, string $calendarUri, bool $deadlines, string $uid): array {
		$this->roles->requireRole($uid, Roles::ALL);
		$calendars = $this->writable($uid);
		$target = null;
		foreach ($calendars as $calendar) {
			if ($calendar->getUri() === $calendarUri) {
				$target = $calendar;
			}
		}
		if ($target === null) {
			throw new InvalidInputException('The selected calendar does not exist or you cannot add entries to it.');
		}
		$events = $eventId === null ? $this->upcoming($uid) : [$this->concert($eventId)];
		// The chosen calendar first, then the account's other calendars.
		$search = array_merge([$target], array_values(array_filter($calendars, fn (ICreateFromString $c): bool => $c !== $target)));
		$result = ['calendar' => self::name($target), 'concerts' => count($events), 'created' => 0, 'updates' => [], 'skipped' => 0, 'failed' => 0];
		foreach ($events as $event) {
			foreach ($this->ics->objects($event, $deadlines, $uid) as ['uid' => $entryUid, 'ics' => $ics]) {
				$found = $this->find($search, $entryUid);
				if ($found !== null) {
					$result['updates'][] = [
						'calendar' => self::name($found['calendar']),
						'path' => 'calendars/' . rawurlencode($uid) . '/' . rawurlencode($found['calendar']->getUri()) . '/' . rawurlencode($found['uri']),
						'ics' => $ics,
					];
					continue;
				}
				if ($event->getStatus() === Event::CANCELLED) {
					$result['skipped']++;
					continue;
				}
				try {
					$target->createFromString($entryUid . '.ics', $ics);
					$result['created']++;
				} catch (\Exception $e) {
					// CalendarException, or an error of the CalDAV server (e.g. an
					// entry with this UID that the search cannot see)
					$this->logger->warning('Notenarchiv: a calendar entry could not be created: ' . $e->getMessage(), ['exception' => $e]);
					$result['failed']++;
				}
			}
		}
		return $result;
	}

	/**
	 * Writable, enabled calendars of the account that accept new entries.
	 *
	 * @return list<ICreateFromString>
	 */
	private function writable(string $uid): array {
		$result = [];
		foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $uid) as $calendar) {
			if (!$calendar instanceof ICreateFromString || $calendar->isDeleted()) {
				continue;
			}
			$writable = $calendar instanceof ICalendarIsWritable
				? $calendar->isWritable()
				: ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0;
			if (!$writable || ($calendar instanceof ICalendarIsEnabled && !$calendar->isEnabled())) {
				continue;
			}
			$result[] = $calendar;
		}
		return $result;
	}

	/**
	 * The calendar and object name of an entry with this UID.
	 *
	 * @param list<ICreateFromString> $calendars
	 * @return array{calendar: ICreateFromString, uri: string}|null
	 */
	private function find(array $calendars, string $entryUid): ?array {
		foreach ($calendars as $calendar) {
			$hits = $calendar->search('', [], ['uid' => $entryUid], 1);
			if (isset($hits[0]) && is_array($hits[0]) && isset($hits[0]['uri']) && is_string($hits[0]['uri']) && $hits[0]['uri'] !== '') {
				return ['calendar' => $calendar, 'uri' => $hits[0]['uri']];
			}
		}
		return null;
	}

	private static function name(ICalendar $calendar): string {
		$name = $calendar->getDisplayName();
		return $name === null || $name === '' ? $calendar->getUri() : $name;
	}

	private function concert(int $eventId): Event {
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		if ($event->getKind() !== Event::CONCERT) {
			throw new InvalidInputException('Only concerts can be added to a calendar.');
		}
		if (!FieldValue::isDate((string)$event->getEventDate())) {
			throw new InvalidInputException('This concert has no date yet.');
		}
		return $event;
	}

	/**
	 * Concerts from today on that are planned in the app, the next first.
	 *
	 * @return list<Event>
	 */
	private function upcoming(string $uid): array {
		$today = $this->rights->today($uid);
		$events = array_values(array_filter($this->events->findByKind(Event::CONCERT),
			fn (Event $e): bool => $e->getImported() !== true && FieldValue::isDate((string)$e->getEventDate()) && (string)$e->getEventDate() >= $today));
		usort($events, fn (Event $a, Event $b): int => [(string)$a->getEventDate(), (string)$a->getStartTime(), $a->getId()]
			<=> [(string)$b->getEventDate(), (string)$b->getStartTime(), $b->getId()]);
		return $events;
	}
}
