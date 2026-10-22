<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Migration\ModuleDefaults;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\ModuleSettings;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Changing modules from the settings and the command line (with the audit
 * log), the description for the settings, and the switches written by the
 * repair step on installation and update.
 */
#[CoversClass(ModuleSettings::class)]
#[CoversClass(ModuleDefaults::class)]
final class ModuleSettingsTest extends TestCase {
	use ModuleSwitches;

	/** @var list<array{?string, string, string, mixed, mixed, ?string}> uid, object type, field, old, new, reason */
	private array $audit = [];

	private function settings(Modules $modules): ModuleSettings {
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $objectType, ?int $objectId, string $action,
			?string $field = null, mixed $old = null, mixed $new = null, ?string $reason = null): void {
			$this->audit[] = [$uid, $objectType, (string)$field, $old, $new, $reason];
		});
		return new ModuleSettings($modules, $audit);
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text): string => '[' . $text . ']');
		return $l;
	}

	public function testEveryChangedSwitchIsRecorded(): void {
		$settings = $this->settings($this->modulesWithout(['score_viewer', 'playback']));
		$changes = $settings->change(['playback' => 'true'], 'admin');
		self::assertSame(['playback'], $changes['enabled']);
		self::assertSame(['score_viewer'], $changes['alsoEnabled']);
		self::assertSame([
			['admin', 'settings', 'module_playback', 'off', 'on', null],
			['admin', 'settings', 'module_score_viewer', 'off', 'on', null],
		], $this->audit);

		$this->audit = [];
		$settings->applyPreset(Modules::SIMPLE, null);
		self::assertContains([null, 'settings', 'module_concerts', 'on', 'off', 'preset simple'], $this->audit);
		self::assertNotContains('module_playback', array_column($this->audit, 2), 'unchanged switches are not recorded');
	}

	public function testRequestValuesAreChecked(): void {
		$settings = $this->settings($this->modulesWithout());
		foreach ([[], ['concerts' => 'maybe'], ['concerts' => ['x']]] as $wanted) {
			try {
				$settings->change($wanted, 'admin');
				self::fail('accepted: ' . json_encode($wanted));
			} catch (InvalidInputException) {
				self::assertSame([], $this->audit);
			}
		}
	}

	public function testDescribesTheModulesForTheSettings(): void {
		$described = $this->settings($this->modulesWithout(['score_viewer']))->describe($this->l10n());
		$byKey = array_column($described['modules'], null, 'key');
		self::assertArrayHasKey(Modules::AGENT_API, $byKey, 'the interface for agents can be switched');
		self::assertSame('interfaces', $byKey[Modules::AGENT_API]['group']);
		self::assertSame(Modules::switchable(), array_keys($byKey));
		self::assertSame(['key' => 'playback', 'group' => 'scores', 'label' => '[Playback]',
			'description' => '[' . Modules::MODULES['playback']['description'] . ']', 'requires' => ['score_viewer'],
			'enabled' => true, 'active' => false], $byKey['playback']);
		self::assertSame(['rights', 'releases', 'scores', 'collaboration', 'concerts', 'maintenance', 'overview', 'notifications', 'interfaces'],
			array_column($described['groups'], 'key'));
		self::assertSame(Modules::PRESETS, array_column($described['presets'], 'key'));
		foreach ($described['presets'] as $preset) {
			self::assertArrayNotHasKey(Modules::AGENT_API, $preset['modules'], 'no preset switches the interface for agents');
		}
		self::assertSame('[Simple]', $described['presets'][0]['label']);
		self::assertCount(count(Modules::CORE), $described['core']);
	}

	/**
	 * @param array<string, string> $installed app => installed version
	 */
	private function runRepairStep(array $installed): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getAppInstalledVersions')->willReturn($installed);
		(new ModuleDefaults($config, $this->modules()))->run($this->createStub(IOutput::class));
	}

	public function testAnUpdateFromBeforeTheModulesKeepsEveryFunction(): void {
		$this->runRepairStep(['notenarchiv' => '0.1.26', 'files' => '2.0']);
		self::assertTrue($this->moduleConfig['module_statistics']);
		self::assertTrue($this->moduleConfig['module_practice_audio']);
		self::assertFalse($this->moduleConfig['module_agent_api']);
	}

	public function testANewInstallationGetsTheStandard(): void {
		$this->runRepairStep(['files' => '2.0']);
		self::assertFalse($this->moduleConfig['module_statistics']);
		self::assertTrue($this->moduleConfig['module_concerts']);
	}

	public function testALaterUpdateKeepsTheSwitchesAndAddsNewOnes(): void {
		$this->moduleConfig = ['module_concerts' => false];
		$this->runRepairStep(['notenarchiv' => '0.1.27']);
		self::assertFalse($this->moduleConfig['module_concerts'], 'a stored switch is never changed');
		self::assertFalse($this->moduleConfig['module_statistics'], 'a module new to the installation gets its default');
	}

	public function testVersionsBeforeTheModules(): void {
		self::assertTrue(ModuleDefaults::isExistingInstallation('0.1.26'));
		self::assertTrue(ModuleDefaults::isExistingInstallation('0.1.9'));
		self::assertFalse(ModuleDefaults::isExistingInstallation('0.1.27'));
		self::assertFalse(ModuleDefaults::isExistingInstallation('0.2.0'));
		self::assertFalse(ModuleDefaults::isExistingInstallation(''));
	}
}
