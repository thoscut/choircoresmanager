<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Support;

use OCA\Notenarchiv\Service\Modules;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The real module register on an app config in memory, so that tests switch
 * modules the way the settings do.
 *
 * @psalm-require-extends TestCase
 */
trait ModuleSwitches {
	/** @var array<string, bool> app config values `module_<key>` */
	private array $moduleConfig = [];

	/**
	 * Modules with these switches stored; the others are not stored (their
	 * default applies).
	 *
	 * @param array<string, bool> $switches module key => on
	 */
	protected function modules(array $switches = []): Modules {
		foreach ($switches as $key => $on) {
			$this->moduleConfig[Modules::CONFIG_PREFIX . $key] = $on;
		}
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => $this->moduleConfig[$key] ?? $default);
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->moduleConfig[$key] = $value;
			return true;
		});
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => array_key_exists($key, $this->moduleConfig));
		return new Modules($config);
	}

	/**
	 * Every module switched on, except the ones given.
	 *
	 * @param list<string> $off
	 */
	protected function modulesWithout(array $off = []): Modules {
		$switches = array_fill_keys(Modules::switchable(), true);
		foreach ($off as $key) {
			$switches[$key] = false;
		}
		return $this->modules($switches);
	}
}
