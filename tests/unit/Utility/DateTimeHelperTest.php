<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Utility;

use OCA\ChoirScores\Utility\DateTimeHelper;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DateTimeHelper.
 */
final class DateTimeHelperTest extends TestCase {
	#[TestWith(['2024-02-29'])]
	#[TestWith(['1991-11-09'])]
	public function testParseStrictDateAcceptsValidDates(string $value): void {
		$date = DateTimeHelper::parseStrictDate($value);

		$this->assertSame($value, $date->format('Y-m-d'));
		$this->assertSame('00:00:00', $date->format('H:i:s'));
	}

	#[TestWith(['2023-02-29'])]
	#[TestWith(['2024-13-01'])]
	#[TestWith(['2024-1-5'])]
	#[TestWith(['05.01.2024'])]
	#[TestWith(['2024-01-05 '])]
	#[TestWith(["2024-01-05\n"])]
	#[TestWith([''])]
	public function testParseStrictDateRejectsInvalidDates(string $value): void {
		$this->expectException(\InvalidArgumentException::class);

		DateTimeHelper::parseStrictDate($value);
	}
}
