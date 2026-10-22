<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Settings;

use OCA\Notenarchiv\Settings\Admin;
use OCA\Notenarchiv\Settings\AdminSection;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The page Administration → Music archive: a delegated setting in the app's
 * own section, registered in appinfo/info.xml, with its own small bundle.
 */
#[CoversClass(Admin::class)]
final class AdminTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public function testIsASettingTheAdministrationCanDelegate(): void {
		$admin = new Admin();
		self::assertInstanceOf(IDelegatedSettings::class, $admin);
		self::assertSame(AdminSection::ID, $admin->getSection());
		self::assertSame('notenarchiv', $admin->getSection());
		self::assertGreaterThanOrEqual(0, $admin->getPriority());
		self::assertLessThanOrEqual(100, $admin->getPriority());
		// The only settings of the section: listed under the section's name
		self::assertNull($admin->getName());
	}

	public function testGivesNoAppConfigToNextcloudsGenericConfigApi(): void {
		// Every setting goes through the app's routes (checks, audit log).
		self::assertSame([], (new Admin())->getAuthorizedAppConfig());
	}

	public function testTheFormIsTheTemplateWithTheSettingsBundle(): void {
		$form = (new Admin())->getForm();
		self::assertInstanceOf(TemplateResponse::class, $form);
		self::assertSame('notenarchiv', $form->getApp());
		self::assertSame('settings-admin', $form->getTemplateName());
		self::assertSame([], $form->getParams());

		$template = file_get_contents(self::ROOT . '/templates/settings-admin.php');
		self::assertIsString($template);
		// Its own bundle (entry "admin-settings" in vite.config.ts), not the one of the app page
		self::assertStringContainsString("APP_ID . '-admin-settings'", $template);
		self::assertStringNotContainsString("APP_ID . '-main'", $template);
		self::assertStringContainsString('id="notenarchiv-admin-settings"', $template);
	}

	public function testIsRegisteredInTheAppInfo(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		self::assertNotFalse($info);
		self::assertSame(Admin::class, (string)$info->settings->admin);
		self::assertSame(AdminSection::class, (string)$info->settings->{'admin-section'});
	}
}
