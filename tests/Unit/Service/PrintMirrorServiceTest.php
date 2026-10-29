<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\PrintMirrorService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * File names in the target folder of the print station: shelf mark and
 * title, without characters that Windows or Nextcloud refuse.
 */
#[CoversClass(PrintMirrorService::class)]
final class PrintMirrorServiceTest extends TestCase {
	public function testTheFileNameIsShelfMarkAndTitle(): void {
		self::assertSame('2-35 Ave verum corpus', PrintMirrorService::fileBase('2-35', 'Ave verum corpus'));
		self::assertSame('5-1', PrintMirrorService::fileBase('5-1', null));
		self::assertSame('5-1', PrintMirrorService::fileBase('5-1', '   '));
	}

	public function testForbiddenCharactersAreReplaced(): void {
		self::assertSame('1-2 Wer hat dich, du schöner Wald', PrintMirrorService::fileBase('1-2', 'Wer hat dich, du schöner Wald?'));
		self::assertSame('1-3 A B C D E F G', PrintMirrorService::fileBase('1-3', 'A/B\\C:D*E"F<G>|'));
		self::assertSame('1-4 Zeile eins Zeile zwei', PrintMirrorService::fileBase('1-4', "Zeile eins\nZeile zwei\t"));
		self::assertSame('1-5 Ende', PrintMirrorService::fileBase('1-5', 'Ende...'), 'no dots at the end (Windows)');
	}

	public function testLongTitlesAreShortenedWithoutCuttingACharacter(): void {
		$base = PrintMirrorService::fileBase('3-1', str_repeat('Ä', 200));
		self::assertSame(120, mb_strlen($base));
		self::assertTrue(mb_check_encoding($base, 'UTF-8'));
		self::assertStringStartsWith('3-1 ÄÄ', $base);
	}
}
