<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\TextReference;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The text reference of the server (API for agents) follows the rules of
 * the UI: the cases of src/__tests__/references.test.ts.
 */
#[CoversClass(TextReference::class)]
final class TextReferenceTest extends TestCase {
	/**
	 * @param array<string, string> $words
	 */
	private function l10n(array $words = []): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($words[$text] ?? $text, $parameters));
		return $l;
	}

	private function german(): IL10N {
		return $this->l10n(['p. %s' => 'S. %s', 'm. %s' => 'T. %s', 'm. %1$s–%2$s' => 'T. %1$s–%2$s', 'verse %s' => 'Str. %s']);
	}

	public function testTheExampleOfTheDocumentation(): void {
		$place = TextReference::place(2, '17', '24', 'Tenor II', null, 'note');
		self::assertSame('5-1 · Freude, schöner Götterfunken · p. 2 · m. 17–24 · Tenor II · note',
			TextReference::text('5-1', 'Freude, schöner Götterfunken', $place, $this->l10n()));
	}

	public function testTheWordsOfTheLanguage(): void {
		self::assertSame('9-19 · T. 62–66 · Tenor II',
			TextReference::text('9-19', null, TextReference::place(null, '62', '66', 'Tenor II', null, null), $this->german()));
	}

	public function testEmptyPartsAreLeftOutAndWhiteSpaceCollapses(): void {
		self::assertSame('2-35 · Abschied vom Walde · Str. 3',
			TextReference::text(' 2-35 ', "  Abschied  vom\nWalde ", TextReference::place(null, null, null, null, 3, null), $this->german()));
		self::assertSame('10-3', TextReference::text('10-3', null, TextReference::place(null, null, null, null, null, null), $this->l10n()));
	}

	public function testOneMeasureIsNamedOnce(): void {
		$l = $this->l10n();
		self::assertSame('m. 0', TextReference::placeText(TextReference::place(null, '0', '0', null, null, null), $l));
		self::assertSame('m. 12', TextReference::placeText(TextReference::place(null, '12', null, null, null, null), $l));
		self::assertSame('m. 34X', TextReference::placeText(TextReference::place(null, '', '34X', null, null, null), $l));
		self::assertSame('S. 3 · T. 1–8 · Bass I · Str. 2', TextReference::placeText(TextReference::place(3, '1', '8', 'Bass I', 2, null), $this->german()));
	}

	public function testInvalidPartsAreRefused(): void {
		foreach ([
			fn () => TextReference::place(0, null, null, null, null, null),
			fn () => TextReference::place(null, '17-24', null, null, null, null),
			fn () => TextReference::place(null, null, null, str_repeat('x', 101), null, null),
			fn () => TextReference::place(null, null, null, null, -1, null),
			fn () => TextReference::place(null, null, null, null, null, str_repeat('x', 501)),
		] as $index => $place) {
			try {
				$place();
				self::fail('accepted: case ' . $index);
			} catch (InvalidInputException) {
				self::assertTrue(true);
			}
		}
	}
}
