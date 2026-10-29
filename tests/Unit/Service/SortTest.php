<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\Sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sort::class)]
final class SortTest extends TestCase {
	public function testShelfMarksInNaturalOrder(): void {
		$items = ['5-10', '5-2', '12-1', '5-1'];
		self::assertSame(['5-1', '5-2', '5-10', '12-1'], Sort::byKeys($items, array_map(fn (string $s): array => [$s], $items)));
	}

	public function testIntegersByValueThenTextNaturally(): void {
		$items = ['c', 'a', 'b', 'd'];
		$keys = [[2, '5-1'], [1, '5-10'], [1, '5-2'], [0, 'x']];
		self::assertSame(['d', 'b', 'a', 'c'], Sort::byKeys($items, $keys));
	}

	public function testEqualKeysKeepTheirOrder(): void {
		$items = [['id' => 3], ['id' => 1], ['id' => 2]];
		self::assertSame($items, Sort::byKeys($items, [[0], [0], [0]]));
	}

	public function testEmpty(): void {
		self::assertSame([], Sort::byKeys([], []));
	}
}
