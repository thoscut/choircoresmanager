<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Contribution;
use OCA\Notenarchiv\Db\ContributionMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventItem;
use OCA\Notenarchiv\Db\EventItemMapper;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\Person;
use OCA\Notenarchiv\Db\PersonMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\EventService;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\StateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The song order like the music report of GEMA (milestone 0.11): the event
 * first, composer, lyricist and arranger in columns of their own, work number
 * and rights status.
 */
#[CoversClass(EventService::class)]
final class SongOrderTest extends TestCase {
	public function testTheSongOrderSeparatesTheRolesAndNamesTheEvent(): void {
		$event = new Event();
		$event->setId(1);
		$event->setTitle('Adventskonzert');
		$event->setEventDate('2026-12-06');
		$event->setStartTime('17:00');
		$event->setLocation('St. Marien');
		$events = $this->createStub(EventMapper::class);
		$events->method('find')->willReturn($event);
		$items = [];
		foreach ([[1, 3, false], [2, 4, false], [3, 3, true]] as [$position, $versionId, $struck]) {
			$item = new EventItem();
			$item->setId($position);
			$item->setPosition($position);
			$item->setVersionId($versionId);
			$item->setWorkId(7);
			$item->setStruck($struck);
			$item->setDuration($position === 1 ? 185 : null);
			$items[] = $item;
		}
		$itemMapper = $this->createStub(EventItemMapper::class);
		$itemMapper->method('findByEvent')->willReturn($items);
		$work = new Work();
		$work->setId(7);
		$work->setTitle('Es kommt ein Schiff');
		$work->setComposer('trad.');
		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturn([7 => $work]);
		$versions = [];
		foreach ([3 => 'protected', 4 => 'public_domain'] as $id => $status) {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId(7);
			$version->setRightsStatus($status);
			$version->setArrangerText($id === 4 ? 'M. Praetorius' : null);
			$version->setSocietyWorkNo($id === 3 ? '1234567-001' : null);
			$versions[$id] = $version;
		}
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$contributions = [];
		foreach ([['composer', 1], ['lyricist', 2], ['arranger', 3], ['arranger', 4]] as [$role, $personId]) {
			$contribution = new Contribution();
			$contribution->setRole($role);
			$contribution->setPersonId($personId);
			$contributions[] = $contribution;
		}
		$contributionMapper = $this->createStub(ContributionMapper::class);
		$contributionMapper->method('findByVersion')->willReturnCallback(fn (int $id): array => $id === 3 ? $contributions : []);
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('find')->willReturnCallback(function (int $id): Person {
			$person = new Person();
			$person->setName(['', 'Traditionell', 'Daniel Sudermann', 'A. Satz', 'B. Satz'][$id]);
			return $person;
		});
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-27');
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text): string => $text === 'Lyricist' ? 'Text' : $text);
		$service = new EventService($this->createStub(IDBConnection::class), $events, $itemMapper, $this->createStub(CopyMapper::class),
			$versionMapper, $works, $this->createStub(LicenseMapper::class), $rights, $this->createStub(StateService::class),
			$this->createStub(AuditLog::class), $this->createStub(Roles::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $contributionMapper, $persons);

		$csv = $service->songOrderCsv(1, 'carla', $l);
		$rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\r\n", trim(substr($csv, 3))));
		self::assertSame([['Event', 'Adventskonzert'], ['Date', '2026-12-06'], ['Start', '17:00'], ['Location', 'St. Marien'], [null]],
			array_slice($rows, 0, 5), 'the data of the event first');
		self::assertSame(['Position', 'Shelf mark', 'Title', 'Composer', 'Text', 'Arranger', 'Publisher', 'Work number (GEMA)', 'Rights status',
			'Duration', 'Note'], $rows[5], 'headers in the language of the account');
		self::assertSame(['1', '', 'Es kommt ein Schiff', 'Traditionell', 'Daniel Sudermann', 'A. Satz; B. Satz', '', '1234567-001', 'Protected', '3:05', ''],
			$rows[6], 'the roles from the register of persons');
		self::assertSame(['2', '', 'Es kommt ein Schiff', 'trad.', '', 'M. Praetorius', '', '', 'Public domain', '', ''], $rows[7],
			'else the text fields');
		self::assertCount(8, $rows, 'struck pieces are left out');
	}
}
