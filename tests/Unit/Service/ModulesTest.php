<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The module register: consistent dependencies, the effective state, the
 * resolution of dependencies when switching, the presets and the switches
 * written on installation and update.
 */
#[CoversClass(Modules::class)]
final class ModulesTest extends TestCase {
	use ModuleSwitches;

	public function testTheRegisterIsConsistent(): void {
		foreach (Modules::MODULES as $key => $module) {
			self::assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key);
			self::assertArrayHasKey($module['group'], Modules::GROUPS, $key);
			self::assertNotSame('', $module['label'], $key);
			self::assertStringEndsWith('.', $module['description'], $key);
			foreach ($module['requires'] as $required) {
				self::assertTrue(Modules::exists($required), $key . ' requires ' . $required);
				self::assertFalse(Modules::isPlanned($required), $key . ' requires a planned module');
			}
			self::assertNotContains($key, Modules::requirements($key), $key . ' depends on itself');
		}
		self::assertSame(array_keys(Modules::PRESET_LABELS), Modules::PRESETS);
		self::assertSame([Modules::AGENT_API], array_values(array_filter(array_keys(Modules::MODULES), Modules::isPlanned(...))));
	}

	public function testEveryNotificationHasASwitch(): void {
		$subjects = array_values((new \ReflectionClass(NotificationService::class))->getConstants());
		self::assertCount(11, $subjects);
		foreach ($subjects as $subject) {
			self::assertIsString($subject);
			$key = Modules::notificationKey($subject);
			self::assertNotNull($key, $subject);
			self::assertSame('notifications', Modules::MODULES[$key]['group']);
			self::assertContains('notifications', Modules::MODULES[$key]['requires'], $key);
		}
		self::assertNull(Modules::notificationKey('unknown'));
	}

	public function testRequirementsAreResolvedTransitively(): void {
		self::assertSame(['playback', 'score_viewer'], Modules::requirements('practice_audio'));
		self::assertSame(['notifications', 'portal', 'releases', 'rights'], Modules::requirements('notify_portal_remove'));
		self::assertSame([], Modules::requirements('concerts'));
	}

	public function testAModuleIsActiveOnlyWithEverythingItRequires(): void {
		$modules = $this->modulesWithout(['score_viewer']);
		self::assertFalse($modules->isEnabled('score_viewer'));
		self::assertTrue($modules->isSwitchedOn('playback'), 'the own switch stays');
		self::assertFalse($modules->isEnabled('playback'));
		self::assertFalse($modules->isEnabled('practice_audio'));
		self::assertFalse($modules->isEnabled('excerpt_links'));
		self::assertTrue($modules->isEnabled('concerts'));
		self::assertFalse($modules->isEnabled(Modules::AGENT_API), 'planned: always off');
		self::assertCount(count(Modules::MODULES), $modules->states());
	}

	public function testUnknownModulesAreProgrammingErrors(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->modules()->isEnabled('nonsense');
	}

	public function testRequireEnabledThrows(): void {
		$modules = $this->modulesWithout(['statistics']);
		$modules->requireEnabled('concerts');
		$this->expectException(ModuleDisabledException::class);
		$modules->requireEnabled('statistics');
	}

	public function testNotificationsFollowTheirSwitchTheMasterAndTheirModule(): void {
		self::assertTrue($this->modulesWithout()->notificationEnabled(NotificationService::TASK_NEW));
		self::assertFalse($this->modulesWithout(['notify_task_new'])->notificationEnabled(NotificationService::TASK_NEW));
		$this->moduleConfig = [];
		self::assertFalse($this->modulesWithout(['tasks'])->notificationEnabled(NotificationService::TASK_NEW));
		$this->moduleConfig = [];
		$modules = $this->modulesWithout(['notifications']);
		self::assertFalse($modules->notificationEnabled(NotificationService::EXPORT_FAILED));
		self::assertFalse($modules->notificationEnabled('unknown subject'), 'follows the master switch');
	}

	public function testSwitchingOnAlsoSwitchesOnWhatItNeeds(): void {
		$modules = $this->modulesWithout(['score_viewer', 'playback', 'practice_audio']);
		$changes = $modules->change(['practice_audio' => true]);
		self::assertSame(['practice_audio'], $changes['enabled']);
		self::assertSame(['score_viewer', 'playback'], $changes['alsoEnabled']);
		self::assertSame([], $changes['disabled']);
		self::assertSame(['excerpt_links'], $changes['activated'], 'its switch was on');
		self::assertTrue($modules->isEnabled('practice_audio'));
		self::assertTrue($this->moduleConfig['module_score_viewer']);
	}

	public function testSwitchingOffKeepsTheSwitchesOfWhatNeedsIt(): void {
		$modules = $this->modulesWithout(['statistics']);
		$changes = $modules->change(['concerts' => false]);
		self::assertSame(['concerts'], $changes['disabled']);
		self::assertSame(['performance_import', 'repertoire', 'calendar', 'notify_concert_deadline', 'notify_concert_confirm'], $changes['inactive']);
		self::assertTrue($modules->isSwitchedOn('calendar'));
		self::assertFalse($modules->isEnabled('calendar'));

		$changes = $modules->change(['concerts' => true]);
		self::assertSame(['concerts'], $changes['enabled']);
		self::assertSame(['performance_import', 'repertoire', 'calendar', 'notify_concert_deadline', 'notify_concert_confirm'], $changes['activated'],
			'switching on shows everything again');
	}

	public function testNothingChangesForASwitchThatIsAlreadyThere(): void {
		$modules = $this->modulesWithout();
		self::assertSame(['enabled' => [], 'alsoEnabled' => [], 'disabled' => [], 'inactive' => [], 'activated' => []],
			$modules->change(['concerts' => true]));
	}

	public function testARequestCannotNeedWhatItSwitchesOff(): void {
		$modules = $this->modulesWithout(['practice_audio']);
		try {
			$modules->change(['practice_audio' => true, 'score_viewer' => false]);
			self::fail('contradiction accepted');
		} catch (InvalidInputException $e) {
			self::assertSame('"%1$s" needs "%2$s".', $e->getMessage());
			self::assertSame(['Practice audio files', 'Score view'], $e->getParameters());
		}
		self::assertFalse($this->moduleConfig['module_practice_audio'], 'nothing was written');
		self::assertTrue($this->moduleConfig['module_score_viewer']);
	}

	public function testOnlyKnownSwitchableModulesCanBeChanged(): void {
		$modules = $this->modulesWithout();
		foreach (['nonsense' => 'Unknown module: %s', Modules::AGENT_API => 'This function is not available yet: %s'] as $key => $message) {
			try {
				$modules->change([$key => true]);
				self::fail($key . ' accepted');
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}

	public function testPresets(): void {
		$simple = Modules::presetValues(Modules::SIMPLE);
		self::assertSame(['score_viewer', 'playback', 'notifications'], array_values(array_filter(
			array_keys(array_filter($simple)), fn (string $key): bool => !str_starts_with($key, Modules::NOTIFY_PREFIX))));
		self::assertNotContains(false, Modules::presetValues(Modules::COMPLETE));
		$standard = Modules::presetValues(Modules::STANDARD);
		self::assertSame(['print_station', 'practice_audio', 'proposals', 'checksums', 'statistics'], array_keys(array_filter($standard, fn (bool $on): bool => !$on)));
		foreach (Modules::PRESETS as $preset) {
			self::assertArrayNotHasKey(Modules::AGENT_API, Modules::presetValues($preset), 'never part of a preset');
		}

		$modules = $this->modulesWithout();
		$changes = $modules->applyPreset(Modules::SIMPLE);
		self::assertContains('concerts', $changes['disabled']);
		self::assertNotContains('notify_task_new', $changes['disabled']);
		self::assertContains('notify_task_new', $changes['inactive'], 'switched on, but tasks are off');
		self::assertTrue($modules->isEnabled('playback'));
		self::assertTrue($modules->isEnabled('notify_export_failed'), 'a failed backup is still reported');
		self::assertFalse($modules->isEnabled('notify_task_new'));
		self::assertTrue($modules->isSwitchedOn('notify_task_new'));

		$changes = $modules->applyPreset(Modules::COMPLETE);
		self::assertSame([], $changes['disabled']);
		self::assertContains('concerts', $changes['enabled']);
		self::assertContains('notify_task_new', $changes['activated']);
		foreach (Modules::switchable() as $key) {
			self::assertTrue($modules->isEnabled($key), $key);
		}
	}

	public function testOpeningFromTheFilesAppIsPartOfStandardAndComplete(): void {
		$module = Modules::MODULES[Modules::FILES_INTEGRATION];
		self::assertSame('scores', $module['group']);
		self::assertSame('Opening from the Files app', $module['label']);
		self::assertSame([], $module['requires'], 'without the score view it opens the copy');
		self::assertFalse(Modules::presetValues(Modules::SIMPLE)[Modules::FILES_INTEGRATION]);
		self::assertTrue(Modules::presetValues(Modules::STANDARD)[Modules::FILES_INTEGRATION]);
		self::assertTrue(Modules::presetValues(Modules::COMPLETE)[Modules::FILES_INTEGRATION]);
		self::assertContains(Modules::FILES_INTEGRATION, Modules::switchable());

		$modules = $this->modulesWithout(['score_viewer']);
		self::assertTrue($modules->isEnabled(Modules::FILES_INTEGRATION), 'independent of the score view');
		$modules->change([Modules::FILES_INTEGRATION => false]);
		self::assertFalse($modules->isEnabled(Modules::FILES_INTEGRATION));
	}

	public function testUnknownPreset(): void {
		$this->expectException(InvalidInputException::class);
		Modules::presetValues('everything');
	}

	public function testANewInstallationGetsTheStandard(): void {
		$modules = $this->modules();
		$written = $modules->writeBaseline(false);
		self::assertSame(array_keys(Modules::MODULES), $written);
		foreach (Modules::presetValues(Modules::STANDARD) as $key => $on) {
			self::assertSame($on, $this->moduleConfig['module_' . $key], $key);
		}
		self::assertFalse($this->moduleConfig['module_agent_api']);
	}

	public function testAnExistingInstallationKeepsEveryFunction(): void {
		$modules = $this->modules();
		$modules->writeBaseline(true);
		foreach (Modules::switchable() as $key) {
			self::assertTrue($this->moduleConfig['module_' . $key], $key);
			self::assertTrue($modules->isEnabled($key), $key);
		}
		self::assertFalse($this->moduleConfig['module_agent_api'], 'the interface for agents starts off');
	}

	public function testStoredSwitchesAreNeverOverwritten(): void {
		$modules = $this->modules(['statistics' => true, 'concerts' => false]);
		$written = $modules->writeBaseline(false);
		self::assertNotContains('statistics', $written);
		self::assertNotContains('concerts', $written);
		self::assertTrue($this->moduleConfig['module_statistics']);
		self::assertFalse($this->moduleConfig['module_concerts']);
		self::assertSame([], $modules->writeBaseline(true), 'a second run writes nothing');
	}

	public function testWithoutStoredSwitchesTheStandardApplies(): void {
		$modules = $this->modules();
		self::assertTrue($modules->isEnabled('concerts'));
		self::assertFalse($modules->isEnabled('statistics'));
		self::assertFalse($modules->isEnabled('practice_audio'));
	}
}
