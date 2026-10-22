<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Service\ConcertIcs;
use OCA\Notenarchiv\Service\IcsWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concerts as iCalendar entries: stable UIDs, all-day entries, times with
 * their time zone definition, deadlines as separate all-day entries,
 * cancelled concerts, and the RFC 5545 form of the whole file.
 */
#[CoversClass(ConcertIcs::class)]
#[CoversClass(IcsWriter::class)]
final class ConcertIcsTest extends TestCase {
	private const NOW = 1792670400; // 2026-10-22 12:00:00 UTC
	private const APP_URL = 'https://cloud.example.org/apps/notenarchiv/';

	private ConcertIcs $ics;
	private string $instanceId = 'ocabc123';

	#[\Override]
	protected function setUp(): void {
		$this->ics = $this->ics();
	}

	private function ics(): ConcertIcs {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('n')->willReturnCallback(fn (string $singular, string $plural, int $count, array $parameters = []): string
			=> vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));
		$l->method('l')->willReturn(false);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn(self::APP_URL);
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => $key === 'instanceid' ? $this->instanceId : $default);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$timeZone = $this->createStub(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('America/New_York'));
		return new ConcertIcs($l, $url, $config, $time, $timeZone);
	}

	private static function concert(array $values = []): Event {
		$event = new Event();
		$event->setId(42);
		$event->setKind(Event::CONCERT);
		$event->setTitle('Adventskonzert');
		$event->setEventDate('2026-12-12');
		$event->setLocation('St. Marien, Lübeck');
		$event->setStatus('fixed');
		$event->setImported(false);
		$event->setNotes('Rücksprache mit Frau Beispiel');
		$event->setCreated(1790000000);
		$event->setModified(1790000000);
		foreach ($values as $column => $value) {
			$event->setValue($column, $value);
		}
		return $event;
	}

	/**
	 * The properties of every component of a type, after unfolding.
	 *
	 * @return list<array<string, string>> "NAME;PARAMS" => value
	 */
	private static function components(string $ics, string $type = 'VEVENT'): array {
		$lines = explode("\r\n", rtrim((string)preg_replace('/\r\n[ \t]/', '', $ics), "\r\n"));
		$components = [];
		$current = null;
		foreach ($lines as $line) {
			if ($line === 'BEGIN:' . $type) {
				$current = [];
			} elseif ($line === 'END:' . $type && $current !== null) {
				$components[] = $current;
				$current = null;
			} elseif ($current !== null) {
				[$name, $value] = explode(':', $line, 2) + [1 => ''];
				$current[$name] = $value;
			}
		}
		return $components;
	}

	private static function assertRfc5545Form(string $ics): void {
		self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:", $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		self::assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'only CRLF line breaks');
		foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
			self::assertLessThanOrEqual(75, strlen($line), $line);
			self::assertTrue(mb_check_encoding($line, 'UTF-8'), $line);
		}
		self::assertStringNotContainsString('METHOD:', $ics, 'calendar objects must not have a METHOD');
	}

	public function testAConcertWithoutTimeIsAnAllDayEntry(): void {
		$ics = $this->ics->document([self::concert()], false, 'anna', null);
		self::assertRfc5545Form($ics);
		$events = self::components($ics);
		self::assertCount(1, $events);
		$event = $events[0];
		self::assertSame('20261212', $event['DTSTART;VALUE=DATE']);
		self::assertSame('20261213', $event['DTEND;VALUE=DATE'], 'the end of an all-day entry is the next day');
		self::assertSame('TRANSPARENT', $event['TRANSP']);
		self::assertSame('20261022T120000Z', $event['DTSTAMP']);
		self::assertSame('Adventskonzert', $event['SUMMARY']);
		self::assertSame('St. Marien\\, Lübeck', $event['LOCATION']);
		self::assertSame('CONFIRMED', $event['STATUS']);
		self::assertSame(self::APP_URL . '?event=42', $event['URL']);
		self::assertStringContainsString('Open in the music archive: ' . self::APP_URL . '?event=42', $event['DESCRIPTION']);
		self::assertStringContainsString('status: Fixed', $event['DESCRIPTION']);
		self::assertStringNotContainsString('Beispiel', $ics, 'the notes (possibly personal data) are left out');
		self::assertSame([], self::components($ics, 'VTIMEZONE'), 'an all-day entry needs no time zone');
	}

	public function testTheUidIsStableAndBelongsToTheInstance(): void {
		$first = self::components($this->ics->document([self::concert()], false, 'anna', null))[0]['UID'];
		$again = self::components($this->ics()->document([self::concert(['title' => 'Neuer Titel', 'event_date' => '2026-12-13'])], false, 'ben', null))[0]['UID'];
		self::assertSame($first, $again, 'same concert, same UID, whoever asks and whatever changed');
		self::assertMatchesRegularExpression('/^notenarchiv-concert-42-[0-9a-f]{12}$/', $first);
		self::assertStringNotContainsString($this->instanceId, $first, 'the instance id itself is not published');

		$this->instanceId = 'ocother';
		$other = self::components($this->ics()->document([self::concert()], false, 'anna', null))[0]['UID'];
		self::assertNotSame($first, $other, 'another Nextcloud instance gives other UIDs');
	}

	public function testATimedConcertUsesItsRecordedTimeZone(): void {
		$ics = $this->ics->document([self::concert(['start_time' => '19:30', 'end_time' => '21:45', 'time_zone' => 'Europe/Berlin'])], false, 'anna', null);
		self::assertRfc5545Form($ics);
		$event = self::components($ics)[0];
		self::assertSame('20261212T193000', $event['DTSTART;TZID=Europe/Berlin']);
		self::assertSame('20261212T214500', $event['DTEND;TZID=Europe/Berlin']);
		self::assertSame('OPAQUE', $event['TRANSP']);
		$zones = self::components($ics, 'VTIMEZONE');
		self::assertCount(1, $zones);
		self::assertSame('Europe/Berlin', $zones[0]['TZID']);
		self::assertLessThan(strpos($ics, 'BEGIN:VEVENT'), strpos($ics, 'BEGIN:VTIMEZONE'));
		self::assertStringContainsString("BEGIN:STANDARD\r\nDTSTART:20261025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100", $ics);
	}

	public function testWithoutARecordedZoneTheAccountsTimeZoneIsUsed(): void {
		$ics = $this->ics->document([self::concert(['start_time' => '18:00'])], false, 'anna', null);
		$event = self::components($ics)[0];
		self::assertSame('20261212T180000', $event['DTSTART;TZID=America/New_York']);
		self::assertArrayNotHasKey('DTEND;TZID=America/New_York', $event, 'no end time, no end');
		self::assertSame('America/New_York', self::components($ics, 'VTIMEZONE')[0]['TZID']);
	}

	public function testOneTimeZoneDefinitionForSeveralConcerts(): void {
		$first = self::concert(['start_time' => '19:00', 'time_zone' => 'Europe/Berlin']);
		$second = self::concert(['start_time' => '17:00', 'time_zone' => 'Europe/Berlin', 'event_date' => '2027-05-01']);
		$second->setId(43);
		$ics = $this->ics->document([$first, $second, self::concert(['event_date' => '2027-06-01'])], false, 'anna', 'Konzerte');
		self::assertRfc5545Form($ics);
		self::assertCount(3, self::components($ics));
		$zones = self::components($ics, 'VTIMEZONE');
		self::assertCount(1, $zones);
		self::assertStringContainsString('DTSTART:20270328T020000', $ics, 'the definition covers 2027 too');
		self::assertStringContainsString("X-WR-CALNAME:Konzerte\r\n", $ics);
	}

	public function testDeadlinesAreSeparateAllDayEntries(): void {
		$ics = $this->ics->document([self::concert()], true, 'anna', null);
		self::assertRfc5545Form($ics);
		$events = self::components($ics);
		self::assertCount(4, $events);
		$uids = array_column($events, 'UID');
		self::assertCount(4, array_unique($uids), 'every entry has its own UID');
		$deadlines = array_slice($events, 1);
		self::assertSame(['20261003', '20261017', '20261031'], array_column($deadlines, 'DTSTART;VALUE=DATE'));
		self::assertSame(['20261004', '20261018', '20261101'], array_column($deadlines, 'DTEND;VALUE=DATE'));
		self::assertSame(['TRANSPARENT', 'TRANSPARENT', 'TRANSPARENT'], array_column($deadlines, 'TRANSP'), 'deadlines block no time');
		self::assertSame('Adventskonzert: Fix the programme and the valid versions', $deadlines[0]['SUMMARY']);
		self::assertSame('Adventskonzert: Clarify the rights of all pieces', $deadlines[1]['SUMMARY']);
		self::assertSame('Adventskonzert: Prepare the concert binders', $deadlines[2]['SUMMARY']);
		self::assertStringContainsString('Deadline 10 weeks before the concert on 2026-12-12.', $deadlines[0]['DESCRIPTION']);
		self::assertMatchesRegularExpression('/^notenarchiv-concert-42-deadline-10-[0-9a-f]{12}$/', $deadlines[0]['UID']);
		self::assertStringNotContainsString('VALARM', $ics);
	}

	public function testImportedHistoryGetsNoDeadlines(): void {
		$events = self::components($this->ics->document([self::concert(['imported' => true, 'status' => 'sung'])], true, 'anna', null));
		self::assertCount(1, $events);
		self::assertSame('CONFIRMED', $events[0]['STATUS']);
	}

	public function testACancelledConcertIsMarkedCancelledWithItsDeadlines(): void {
		$events = self::components($this->ics->document([self::concert(['status' => Event::CANCELLED])], true, 'anna', null));
		self::assertSame(['CANCELLED', 'CANCELLED', 'CANCELLED', 'CANCELLED'], array_column($events, 'STATUS'));
		self::assertStringContainsString('This concert has been cancelled.', $events[0]['DESCRIPTION']);
		self::assertSame('TENTATIVE', self::components($this->ics->document([self::concert(['status' => 'draft'])], false, 'anna', null))[0]['STATUS']);
	}

	public function testTheSequenceGrowsWithEveryChange(): void {
		$before = (int)self::components($this->ics->document([self::concert(['modified' => 1790000000])], false, 'anna', null))[0]['SEQUENCE'];
		$after = (int)self::components($this->ics->document([self::concert(['modified' => 1790000600])], false, 'anna', null))[0]['SEQUENCE'];
		self::assertGreaterThan($before, $after);
		$event = self::components($this->ics->document([self::concert(['modified' => 1790000600])], false, 'anna', null))[0];
		self::assertSame(IcsWriter::utc(1790000600), $event['LAST-MODIFIED']);
	}

	public function testLongTitlesWithUmlautsAreFoldedAndEscaped(): void {
		$title = 'Größtes Jubiläumskonzert; Händel, Bach & Söhne – „Messias“ mit Orchester und Gästen aus Überlingen';
		$ics = $this->ics->document([self::concert(['title' => $title])], true, 'anna', null);
		self::assertRfc5545Form($ics);
		$events = self::components($ics);
		self::assertSame(IcsWriter::text($title), $events[0]['SUMMARY']);
		self::assertStringStartsWith('Größtes Jubiläumskonzert\\; Händel\\, Bach', $events[0]['SUMMARY']);
	}

	public function testEveryEntryIsItsOwnCalendarObject(): void {
		$objects = $this->ics->objects(self::concert(['start_time' => '19:00', 'time_zone' => 'Europe/Berlin']), true, 'anna');
		self::assertCount(4, $objects);
		foreach ($objects as $object) {
			self::assertRfc5545Form($object['ics']);
			$events = self::components($object['ics']);
			self::assertCount(1, $events, 'one UID per calendar object');
			self::assertSame($object['uid'], $events[0]['UID']);
			self::assertStringNotContainsString('X-WR-CALNAME', $object['ics']);
		}
		self::assertCount(1, self::components($objects[0]['ics'], 'VTIMEZONE'), 'the concert with a time carries its zone');
		self::assertSame([], self::components($objects[1]['ics'], 'VTIMEZONE'), 'an all-day deadline needs none');
		self::assertSame($this->ics->uid(self::concert()), $objects[0]['uid']);
		self::assertSame($this->ics->uid(self::concert(), 8), $objects[2]['uid']);
	}
}
