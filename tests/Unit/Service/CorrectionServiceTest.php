<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\CorrectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The name of the new file of a correction: next to the old one, dated, in
 * the account's language, never overwriting a file and never repeating an
 * earlier correction suffix.
 */
#[CoversClass(CorrectionService::class)]
final class CorrectionServiceTest extends TestCase {
	public function testNameOfTheNewFile(): void {
		$none = fn (string $name): bool => false;
		self::assertSame('5-01_Titel (korrigiert 2026-10-22).musicxml',
			CorrectionService::newFileName('5-01_Titel.musicxml', '2026-10-22', 'korrigiert', $none));
		self::assertSame('5-01_Titel (corrected 2026-10-22).mxl',
			CorrectionService::newFileName('5-01_Titel.mxl', '2026-10-22', 'corrected', $none));
		self::assertSame('5-01_Ein Lied, 2. Fassung (korrigiert 2026-10-22).MXL',
			CorrectionService::newFileName('5-01_Ein Lied, 2. Fassung.MXL', '2026-10-22', 'korrigiert', $none));
	}

	public function testAnExistingNameGetsANumber(): void {
		$taken = ['5-01_Titel (korrigiert 2026-10-22).mxl', '5-01_Titel (korrigiert 2026-10-22, 2).mxl'];
		self::assertSame('5-01_Titel (korrigiert 2026-10-22, 3).mxl',
			CorrectionService::newFileName('5-01_Titel.mxl', '2026-10-22', 'korrigiert', fn (string $name): bool => in_array($name, $taken, true)));
	}

	public function testAnEarlierSuffixIsReplaced(): void {
		$none = fn (string $name): bool => false;
		self::assertSame('5-01_Titel (korrigiert 2026-10-23).mxl',
			CorrectionService::newFileName('5-01_Titel (korrigiert 2026-10-22).mxl', '2026-10-23', 'korrigiert', $none));
		self::assertSame('5-01_Titel (korrigiert 2026-10-23).mxl',
			CorrectionService::newFileName('5-01_Titel (corrected 2026-10-22, 4).mxl', '2026-10-23', 'korrigiert', $none));
		self::assertSame('5-01_Titel (alt) (korrigiert 2026-10-23).mxl',
			CorrectionService::newFileName('5-01_Titel (alt).mxl', '2026-10-23', 'korrigiert', $none), 'other brackets stay');
	}

	public function testALongNameIsShortened(): void {
		$name = CorrectionService::newFileName('5-01_' . str_repeat('ä', 200) . '.musicxml', '2026-10-22', 'korrigiert', fn (string $n): bool => false);
		self::assertLessThanOrEqual(250, strlen($name));
		self::assertStringEndsWith(' (korrigiert 2026-10-22).musicxml', $name);
		self::assertTrue(mb_check_encoding($name, 'UTF-8'), 'no character is cut in half');
	}

	public function testANameWithoutExtension(): void {
		self::assertSame('5-01_Titel (korrigiert 2026-10-22)',
			CorrectionService::newFileName('5-01_Titel', '2026-10-22', 'korrigiert', fn (string $n): bool => false));
	}
}
