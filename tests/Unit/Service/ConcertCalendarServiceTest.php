<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\ConcertCalendarService;
use OCA\Notenarchiv\Service\ConcertIcs;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Tests\Unit\Support\FakeCalendar;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Calendar files and entries in the account's own calendars: who may do
 * what, which calendars are offered, and that adding again updates instead
 * of duplicating.
 */
#[CoversClass(ConcertCalendarService::class)]
final class ConcertCalendarServiceTest extends TestCase {
	private const TODAY = '2026-10-22';

	/** @var array<string, list<string>> uid => roles */
	private array $roles = ['anna' => ['reader'], 'admin' => []];
	/** @var list<ICalendar> */
	private array $calendars = [];
	/** @var array<int, Event> */
	private array $events = [];
	/** @var list<string> */
	private array $principals = [];

	private function service(): ConcertCalendarService {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireAccess')->willReturnCallback(function (string $uid): void {
			if (!isset($this->roles[$uid])) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$roles->method('requireRole')->willReturnCallback(function (string $uid, array $allowed): void {
			if (array_intersect($this->roles[$uid] ?? [], $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s', ['…']);
			}
		});
		$mapper = $this->createStub(EventMapper::class);
		$mapper->method('find')->willReturnCallback(fn (int $id): Event => $this->events[$id] ?? throw new DoesNotExistException('none'));
		$mapper->method('findByKind')->willReturnCallback(fn (?string $kind = null): array
			=> array_values(array_filter($this->events, fn (Event $e): bool => $kind === null || $e->getKind() === $kind)));
		$manager = $this->createStub(IManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturnCallback(function (string $principal): array {
			$this->principals[] = $principal;
			return $this->calendars;
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('n')->willReturnCallback(fn (string $singular, string $plural, int $count, array $parameters = []): string
			=> vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/apps/notenarchiv/');
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('ocabc123');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1792670400);
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$ics = new ConcertIcs($l, $url, $config, $time, $timeZone);
		return new ConcertCalendarService($mapper, $ics, $manager, $roles, $rights, $l, $this->createStub(LoggerInterface::class));
	}

	private function addEvent(int $id, string $date, string $status = 'fixed', string $kind = Event::CONCERT, bool $imported = false): Event {
		$event = new Event();
		$event->setId($id);
		$event->setKind($kind);
		$event->setTitle('Konzert ' . $id);
		$event->setEventDate($date);
		$event->setStatus($status);
		$event->setImported($imported);
		$event->setModified(1790000000);
		$this->events[$id] = $event;
		return $event;
	}

	public function testOffersOnlyWritableEnabledCalendarsOfTheAccount(): void {
		$readOnly = $this->createStub(ICalendar::class);
		$readOnly->method('getUri')->willReturn('subscription');
		$this->calendars = [
			new FakeCalendar('personal', 'Persönlich'),
			new FakeCalendar('shared_by_bob', 'Chor (nur lesen)', writable: false),
			new FakeCalendar('hidden', 'Ausgeblendet', enabled: false),
			new FakeCalendar('trash', 'Gelöscht', deleted: true),
			new FakeCalendar('noname'),
			$readOnly,
		];
		$calendars = $this->service()->writableCalendars('anna');
		self::assertSame([
			['uri' => 'personal', 'name' => 'Persönlich', 'color' => '#0082c9'],
			['uri' => 'noname', 'name' => 'noname', 'color' => '#0082c9'],
		], $calendars);
		self::assertSame(['principals/users/anna'], $this->principals);
	}

	public function testAddingNeedsARole(): void {
		$this->calendars = [new FakeCalendar('personal')];
		$this->addEvent(1, '2026-12-12');
		$service = $this->service();
		$this->expectException(AccessDeniedException::class);
		$service->add(1, 'personal', false, 'admin');
	}

	public function testListingCalendarsNeedsARole(): void {
		$this->expectException(AccessDeniedException::class);
		$this->service()->writableCalendars('admin');
	}

	public function testAnUnknownOrReadOnlyCalendarIsRefused(): void {
		$this->calendars = [new FakeCalendar('shared', writable: false)];
		$this->addEvent(1, '2026-12-12');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The selected calendar does not exist or you cannot add entries to it.');
		$this->service()->add(1, 'shared', false, 'anna');
	}

	public function testAddsAConcertWithItsDeadlinesAndUpdatesInsteadOfDuplicating(): void {
		$personal = new FakeCalendar('personal', 'Persönlich');
		$this->calendars = [$personal];
		$this->addEvent(7, '2026-12-12');

		$first = $this->service()->add(7, 'personal', true, 'anna');
		self::assertSame(['calendar' => 'Persönlich', 'concerts' => 1, 'created' => 4, 'updates' => [], 'skipped' => 0, 'failed' => 0], $first);
		self::assertCount(4, $personal->objects);
		foreach (array_keys($personal->objects) as $name) {
			self::assertMatchesRegularExpression('/^notenarchiv-concert-7(-deadline-(10|8|6))?-[0-9a-f]{12}\.ics$/', $name, 'object name = UID');
		}

		// Changed and added again: nothing new, four updates with the new content
		$this->events[7]->setLocation('Dom');
		$again = $this->service()->add(7, 'personal', true, 'anna');
		self::assertSame(0, $again['created']);
		self::assertCount(4, $again['updates']);
		self::assertCount(4, $personal->objects, 'no duplicates');
		$update = $again['updates'][0];
		self::assertSame('Persönlich', $update['calendar']);
		self::assertSame('calendars/anna/personal/' . array_key_first($personal->objects), $update['path']);
		self::assertStringContainsString("\r\nLOCATION:Dom\r\n", $update['ics']);
	}

	public function testAnEntryInAnotherCalendarIsUpdatedThere(): void {
		$personal = new FakeCalendar('personal', 'Persönlich');
		$choir = new FakeCalendar('chor', 'Chor & Co');
		$this->calendars = [$personal, $choir];
		$this->addEvent(7, '2026-12-12');
		$this->service()->add(7, 'chor', false, 'anna');
		self::assertCount(1, $choir->objects);

		$result = $this->service()->add(7, 'personal', false, 'anna');
		self::assertSame(0, $result['created']);
		self::assertSame([], $personal->objects);
		self::assertSame('Chor & Co', $result['updates'][0]['calendar']);
		self::assertStringStartsWith('calendars/anna/chor/', $result['updates'][0]['path']);
	}

	public function testThePathIsEncoded(): void {
		$this->roles['anna maria@example.org'] = ['conductor'];
		$calendar = new FakeCalendar('my calendar');
		$this->calendars = [$calendar];
		$this->addEvent(7, '2026-12-12');
		// Imported by hand earlier, under a name chosen by the calendar program
		$uid = 'notenarchiv-concert-7-' . substr(hash('sha256', 'ocabc123'), 0, 12);
		$calendar->objects['strange name?.ics'] = "BEGIN:VCALENDAR\r\nUID:" . $uid . "\r\nEND:VCALENDAR\r\n";
		$result = $this->service()->add(7, 'my calendar', false, 'anna maria@example.org');
		self::assertSame(0, $result['created']);
		self::assertSame('calendars/anna%20maria%40example.org/my%20calendar/strange%20name%3F.ics', $result['updates'][0]['path']);
	}

	public function testACancelledConcertIsUpdatedButNotAdded(): void {
		$personal = new FakeCalendar('personal');
		$this->calendars = [$personal];
		$this->addEvent(7, '2026-12-12', Event::CANCELLED);
		$result = $this->service()->add(7, 'personal', true, 'anna');
		self::assertSame(['created' => 0, 'updates' => 0, 'skipped' => 4], ['created' => $result['created'], 'updates' => count($result['updates']), 'skipped' => $result['skipped']]);

		// Added while planned, then cancelled: the entries are updated
		$this->addEvent(8, '2026-12-13');
		$this->service()->add(8, 'personal', false, 'anna');
		$this->events[8]->setStatus(Event::CANCELLED);
		$result = $this->service()->add(8, 'personal', false, 'anna');
		self::assertCount(1, $result['updates']);
		self::assertStringContainsString("\r\nSTATUS:CANCELLED\r\n", $result['updates'][0]['ics']);
	}

	public function testAFailingCalendarIsCountedNotFatal(): void {
		$personal = new FakeCalendar('personal');
		$personal->failOnCreate = true;
		$this->calendars = [$personal];
		$this->addEvent(7, '2026-12-12');
		$result = $this->service()->add(7, 'personal', true, 'anna');
		self::assertSame(['created' => 0, 'failed' => 4], ['created' => $result['created'], 'failed' => $result['failed']]);
	}

	public function testAddsAllUpcomingConcertsPlannedInTheApp(): void {
		$personal = new FakeCalendar('personal');
		$this->calendars = [$personal];
		$this->addEvent(1, '2026-10-21');
		$this->addEvent(2, self::TODAY);
		$this->addEvent(3, '2027-01-06');
		$this->addEvent(4, '2026-12-24', 'sung', Event::CONCERT, true);
		$this->addEvent(5, '2026-12-31', 'draft', Event::SONG_LIST);
		$this->addEvent(6, '2027');
		$result = $this->service()->add(null, 'personal', false, 'anna');
		self::assertSame(2, $result['concerts']);
		self::assertSame(2, $result['created']);
		self::assertSame(['notenarchiv-concert-2', 'notenarchiv-concert-3'],
			array_map(fn (string $name): string => substr($name, 0, 21), array_keys($personal->objects)));
	}

	public function testCalendarFiles(): void {
		$this->addEvent(3, '2027-01-06');
		$this->addEvent(2, '2026-11-01');
		$this->addEvent(9, '2026-12-31', 'draft', Event::SONG_LIST);
		$this->addEvent(10, '2027');
		$service = $this->service();

		$file = $service->concertFile(3, true, 'anna');
		self::assertSame('concert-3.ics', $file['name']);
		self::assertSame(4, substr_count($file['content'], "\r\nBEGIN:VEVENT\r\n"));

		$upcoming = $service->upcomingFile(false, 'anna');
		self::assertSame('concerts.ics', $upcoming['name']);
		self::assertStringContainsString("X-WR-CALNAME:Concerts of the music archive\r\n", $upcoming['content']);
		self::assertLessThan(strpos($upcoming['content'], 'notenarchiv-concert-3-'), strpos($upcoming['content'], 'notenarchiv-concert-2-'), 'the next concert first');

		foreach ([9 => 'Only concerts can be added to a calendar.', 10 => 'This concert has no date yet.', 99 => 'Not found.'] as $id => $message) {
			try {
				$service->concertFile($id, false, 'anna');
				self::fail('no file for event ' . $id);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}

	public function testFilesNeedAccess(): void {
		$this->addEvent(3, '2027-01-06');
		$this->expectException(AccessDeniedException::class);
		$this->service()->concertFile(3, false, 'stranger');
	}

	public function testThereIsNoFileWithoutUpcomingConcerts(): void {
		$this->addEvent(1, '2026-01-01');
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('There are no upcoming concerts.');
		$this->service()->upcomingFile(false, 'anna');
	}
}
