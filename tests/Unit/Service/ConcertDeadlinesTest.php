<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Service\ConcertDeadlines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deadlines 10, 8 and 6 weeks before a concert and the confirmation
 * after it, as used by notifications, start page, dashboard and calendar.
 */
#[CoversClass(ConcertDeadlines::class)]
final class ConcertDeadlinesTest extends TestCase {
	private const TODAY = '2026-10-22';

	private static function concert(string $date, string $status = 'draft', bool $imported = false, string $kind = Event::CONCERT): Event {
		$event = new Event();
		$event->setKind($kind);
		$event->setEventDate($date);
		$event->setStatus($status);
		$event->setImported($imported);
		return $event;
	}

	private static function inDays(int $days): string {
		return (new \DateTimeImmutable(self::TODAY))->modify(($days < 0 ? '' : '+') . $days . ' days')->format('Y-m-d');
	}

	/**
	 * @return iterable<string, array{int, string}>
	 */
	public static function stages(): iterable {
		yield 'more than 10 weeks ahead' => [71, ''];
		yield 'exactly 10 weeks ahead' => [70, '10'];
		yield 'just over 8 weeks ahead' => [57, '10'];
		yield 'exactly 8 weeks ahead' => [56, '8'];
		yield 'just over 6 weeks ahead' => [43, '8'];
		yield 'exactly 6 weeks ahead' => [42, '6'];
		yield 'tomorrow' => [1, '6'];
		yield 'today' => [0, '6'];
		yield 'yesterday' => [-1, 'confirm'];
		yield 'long ago' => [-400, 'confirm'];
	}

	#[DataProvider('stages')]
	public function testTheStageFollowsTheDaysUntilTheConcert(int $days, string $stage): void {
		self::assertSame($stage, ConcertDeadlines::stage(self::concert(self::inDays($days)), self::TODAY));
		self::assertSame($stage, ConcertDeadlines::stage(self::concert(self::inDays($days), 'fixed'), self::TODAY));
	}

	public function testSungCancelledImportedAndUndatedEventsHaveNoStage(): void {
		self::assertSame('', ConcertDeadlines::stage(self::concert(self::inDays(-3), 'sung'), self::TODAY));
		self::assertSame('', ConcertDeadlines::stage(self::concert(self::inDays(30), Event::CANCELLED), self::TODAY));
		self::assertSame('', ConcertDeadlines::stage(self::concert(self::inDays(-3), Event::CANCELLED), self::TODAY));
		self::assertSame('', ConcertDeadlines::stage(self::concert(self::inDays(30), 'fixed', true), self::TODAY));
		self::assertSame('', ConcertDeadlines::stage(self::concert('2026'), self::TODAY));
		self::assertSame('', ConcertDeadlines::stage(self::concert(self::inDays(30), 'draft', false, Event::SONG_LIST), self::TODAY));
		self::assertFalse(ConcertDeadlines::apply(self::concert(self::inDays(30), Event::CANCELLED)));
		self::assertTrue(ConcertDeadlines::apply(self::concert(self::inDays(30))));
	}

	public function testTheDeadlinesAreWholeWeeksBeforeTheConcert(): void {
		self::assertSame([10 => '2026-10-03', 8 => '2026-10-17', 6 => '2026-10-31'], ConcertDeadlines::dates('2026-12-12'));
		// across the change of the year and a leap day
		self::assertSame([10 => '2027-12-27', 8 => '2028-01-10', 6 => '2028-01-24'], ConcertDeadlines::dates('2028-03-06'));
	}

	public function testEveryStageHasALabel(): void {
		foreach (['10', '8', '6', 'confirm'] as $stage) {
			self::assertNotSame('', ConcertDeadlines::label($stage));
		}
		self::assertSame(ConcertDeadlines::label('10'), ConcertDeadlines::label(10));
		self::assertSame('', ConcertDeadlines::label('unknown'));
	}
}
