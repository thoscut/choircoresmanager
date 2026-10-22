<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Controller;

use OCA\Notenarchiv\Controller\PageController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The page "Access forbidden" links the section of the guide about a
 * missing role, in the language of the account (the anchors themselves are
 * checked against the guides in src/__tests__/docs.test.ts).
 */
#[CoversClass(PageController::class)]
final class PageControllerTest extends TestCase {
	public function testLinksTheGermanQuickStartForGerman(): void {
		foreach (['de', 'de_DE'] as $language) {
			self::assertSame('https://github.com/thoscut/choircoresmanager/blob/main/docs/de/schnellstart.md#kein-zugang-zum-notenarchiv',
				PageController::noAccessGuideUrl($language));
		}
	}

	public function testLinksTheUserGuideOtherwise(): void {
		self::assertSame('https://github.com/thoscut/choircoresmanager/blob/main/docs/user-guide.md#no-access-to-the-music-archive',
			PageController::noAccessGuideUrl('en'));
	}
}
