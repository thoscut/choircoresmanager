<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Db;

use OCA\Notenarchiv\Db\Merge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a merge recorded as moved; the undo relies on it.
 */
#[CoversClass(Merge::class)]
final class MergeTest extends TestCase {
	private static function merge(string $kind, ?string $movedIds): Merge {
		return Merge::fromParams(['kind' => $kind, 'movedIds' => $movedIds]);
	}

	public function testMergeOfWorks(): void {
		$merge = self::merge(Merge::WORK, '{"versions":[3,4],"eventItems":[17],"workNo":"W-0007"}');
		$expected = ['versions' => [3, 4], 'eventItems' => [17], 'workNo' => 'W-0007'];
		self::assertSame($expected, $merge->movedWork());
		self::assertSame($expected, $merge->moved());
	}

	public function testMoveOfACopy(): void {
		$merge = self::merge(Merge::COPY, '{"fromVersion":5,"eventItems":[{"id":17,"version":5,"work":2},{"id":18,"version":null,"work":null}]}');
		$expected = ['fromVersion' => 5, 'eventItems' => [['id' => 17, 'version' => 5, 'work' => 2], ['id' => 18, 'version' => null, 'work' => null]]];
		self::assertSame($expected, $merge->movedCopy());
		self::assertSame($expected, $merge->moved());
	}

	public function testDamagedOrMissingDataGivesEmptyValues(): void {
		self::assertSame(['versions' => [], 'eventItems' => [], 'workNo' => null], self::merge(Merge::WORK, null)->movedWork());
		self::assertSame(['versions' => [4], 'eventItems' => [], 'workNo' => null],
			self::merge(Merge::WORK, '{"versions":["4","x",null],"eventItems":"none","workNo":7}')->movedWork());
		self::assertSame(['fromVersion' => 0, 'eventItems' => [['id' => 9, 'version' => null, 'work' => null]]],
			self::merge(Merge::COPY, '{"eventItems":[{"version":5},{"id":9},"x"]}')->movedCopy());
	}

	public function testInvalidJsonIsAnError(): void {
		$this->expectException(\JsonException::class);
		self::merge(Merge::WORK, '{broken')->movedWork();
	}
}
