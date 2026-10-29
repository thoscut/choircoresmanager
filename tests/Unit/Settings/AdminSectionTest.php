<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Settings;

use OCA\Cantoria\Settings\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The section "Cantoria" under Administration.
 */
#[CoversClass(AdminSection::class)]
final class AdminSectionTest extends TestCase {
	private function section(): AdminSection {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text): string => '[' . $text . ']');
		$url = $this->createStub(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file);
		return new AdminSection($l, $url);
	}

	public function testIdNameAndOrder(): void {
		$section = $this->section();
		self::assertInstanceOf(IIconSection::class, $section);
		self::assertSame('cantoria', $section->getID());
		// The translated name of the app, as in the app menu
		self::assertSame('[Cantoria]', $section->getName());
		self::assertGreaterThanOrEqual(0, $section->getPriority());
		self::assertLessThanOrEqual(99, $section->getPriority());
	}

	public function testTheIconIsTheDarkAppIcon(): void {
		self::assertSame('/apps/cantoria/img/app-dark.svg', $this->section()->getIcon());
		self::assertFileExists(__DIR__ . '/../../../img/app-dark.svg');
	}
}
