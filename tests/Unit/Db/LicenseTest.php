<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Db;

use OCA\Notenarchiv\Db\License;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(License::class)]
final class LicenseTest extends TestCase {
	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string, 3: string, 4: string, 5: bool}>
	 */
	public static function coverageCases(): iterable {
		// uses, valid from, valid until, use, day, covered
		yield 'listed use without limits' => ['portal', null, null, 'portal', '2026-10-22', true];
		yield 'one of several uses' => ['binder,portal,practice_audio', null, null, 'practice_audio', '2026-10-22', true];
		yield 'use not listed' => ['binder,portal', null, null, 'print_station', '2026-10-22', false];
		yield 'no partial match of use names' => ['print_station', null, null, 'print', '2026-10-22', false];
		yield 'no uses at all' => [null, null, null, 'portal', '2026-10-22', false];
		yield 'empty uses' => ['', null, null, 'portal', '2026-10-22', false];
		yield 'day before start' => ['portal', '2026-11-01', null, 'portal', '2026-10-31', false];
		yield 'first day' => ['portal', '2026-11-01', null, 'portal', '2026-11-01', true];
		yield 'last day' => ['portal', null, '2026-12-31', 'portal', '2026-12-31', true];
		yield 'day after end' => ['portal', null, '2026-12-31', 'portal', '2027-01-01', false];
		yield 'within period' => ['portal', '2026-01-01', '2026-12-31', 'portal', '2026-06-15', true];
		yield 'ended license' => ['portal', '1999-01-01', '2000-01-01', 'portal', '2026-10-22', false];
		yield 'period across a year boundary' => ['binder', '2025-12-01', '2026-01-31', 'binder', '2026-01-15', true];
	}

	#[DataProvider('coverageCases')]
	public function testCovers(?string $uses, ?string $from, ?string $until, string $use, string $day, bool $covered): void {
		$license = License::fromParams(['uses' => $uses, 'validFrom' => $from, 'validUntil' => $until]);
		self::assertSame($covered, $license->covers($use, $day));
	}

	public function testUseList(): void {
		self::assertSame([], License::fromParams(['uses' => null])->useList());
		self::assertSame([], License::fromParams(['uses' => ''])->useList());
		self::assertSame(['binder', 'portal'], License::fromParams(['uses' => 'binder,portal'])->useList());
	}
}
