<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\PerformanceImportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counting rule for documented performances and the detection of the
 * own choir. The import itself is covered by tests/Integration/events_test.php.
 */
#[CoversClass(PerformanceImportService::class)]
final class PerformanceImportServiceTest extends TestCase {
	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string, 3: bool}>
	 */
	public static function lines(): iterable {
		// participation, state, own choir, counts
		yield 'nothing recorded' => [null, null, null, true];
		yield 'sung by the own choir' => ['MGV Liederkranz', 'Programm', 'MGV Liederkranz', true];
		yield 'own choir, other case and blanks' => ['  mgv liederkranz ', null, 'MGV Liederkranz', true];
		yield 'empty participation counts as own choir' => ['', null, 'MGV Liederkranz', true];
		yield 'sung by a guest choir' => ['Frauenchor Eintracht', null, 'MGV Liederkranz', false];
		yield 'own choir unknown' => ['Frauenchor Eintracht', null, null, true];
		yield 'own choir empty' => ['Frauenchor Eintracht', null, '', true];
		yield 'draft (German)' => [null, 'Entwurf vom 3.5.', null, false];
		yield 'draft (English)' => [null, 'Draft 2', null, false];
		yield 'reserve piece' => [null, 'Reserve', null, false];
		yield 'encore if needed (German)' => [null, 'Zugabe nach Bedarf', null, false];
		yield 'encore if needed (English)' => [null, 'Encore if needed', null, false];
		yield 'planned encore counts' => [null, 'Zugabe', null, true];
		yield 'final programme' => [null, 'endgültig', null, true];
	}

	#[DataProvider('lines')]
	public function testCountsByRule(?string $participation, ?string $state, ?string $ownChoir, bool $counts): void {
		self::assertSame($counts, PerformanceImportService::countsByRule($participation, $state, $ownChoir));
	}

	public function testMostFrequentValueOfAColumn(): void {
		$sheet = ['sheet' => 'Programme', 'header' => ['Datum', 'Mitwirkung'], 'rows' => [
			['2024-05-01', 'MGV Liederkranz'],
			['2024-05-01', ' MGV Liederkranz '],
			['2024-05-01', 'Gastchor'],
			['2024-06-01', ''],
			['2024-06-01'],
		]];
		self::assertSame('MGV Liederkranz', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testOnATieTheFirstValueWins(): void {
		$sheet = ['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [['B'], ['A'], ['A'], ['B']]];
		self::assertSame('B', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testNumericValuesStayText(): void {
		$sheet = ['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [['1990'], ['1990']]];
		self::assertSame('1990', PerformanceImportService::mostFrequent($sheet, 'Mitwirkung'));
	}

	public function testNoValue(): void {
		self::assertNull(PerformanceImportService::mostFrequent(['sheet' => 'S', 'header' => ['Datum'], 'rows' => [['x']]], 'Mitwirkung'));
		self::assertNull(PerformanceImportService::mostFrequent(['sheet' => 'S', 'header' => ['Mitwirkung'], 'rows' => [[''], [' ']]], 'Mitwirkung'));
	}
}
