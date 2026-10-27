<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\IL10N;

/**
 * Changing the module switches from the settings or the command line, with
 * an entry in the audit log for every switch that changed (object type
 * `settings`, field `module_<key>`, values `on`/`off`), and the description
 * of all modules for the settings.
 *
 * @psalm-type ModuleChanges = array{enabled: list<string>, alsoEnabled: list<string>, disabled: list<string>, inactive: list<string>, activated: list<string>}
 */
class ModuleSettings {
	public function __construct(
		private Modules $modules,
		private AuditLog $audit,
	) {
	}

	/**
	 * @param array<array-key, mixed> $wanted module key => on (request data)
	 * @return ModuleChanges
	 * @throws InvalidInputException
	 */
	public function change(array $wanted, ?string $uid, bool $admin = true): array {
		if ($wanted === []) {
			throw new InvalidInputException('Please choose a module or a preset.');
		}
		$values = [];
		foreach (array_keys($wanted) as $key) {
			$values[(string)$key] = Input::bool($wanted[$key], (string)$key);
		}
		if (!$admin) {
			// The modules that open the archive to the outside (agents, choir
			// members, the public, the print station) are switched on by an
			// administrator only, also when another module needs them.
			foreach (array_keys(array_filter($values)) as $key) {
				foreach ([$key, ...Modules::requirements($key)] as $module) {
					if (in_array($module, Modules::OUTSIDE_PRESETS, true) && !$this->modules->isSwitchedOn($module)) {
						throw new AccessDeniedException('Only an administrator can switch on the modules that open the archive to agents, choir members, the public or the print station.');
					}
				}
			}
		}
		return $this->record($this->modules->change($values), $uid, null);
	}

	/**
	 * @return ModuleChanges
	 * @throws InvalidInputException
	 */
	public function applyPreset(string $preset, ?string $uid): array {
		return $this->record($this->modules->applyPreset($preset), $uid, $preset);
	}

	/**
	 * Everything the settings show, translated: the modules with their
	 * switch (`enabled`) and whether they are active (`active`), the groups,
	 * the presets and the core.
	 *
	 * @return array{modules: list<array{key: string, group: string, label: string, description: string, requires: list<string>, enabled: bool, active: bool}>, groups: list<array{key: string, label: string}>, presets: list<array{key: string, label: string, description: string, modules: array<string, bool>}>, core: list<string>}
	 */
	public function describe(IL10N $l): array {
		$active = $this->modules->states();
		$modules = [];
		$groups = [];
		foreach (Modules::switchable() as $key) {
			$module = Modules::MODULES[$key];
			$modules[] = [
				'key' => $key,
				'group' => $module['group'],
				'label' => $l->t($module['label']),
				'description' => $l->t($module['description']),
				'requires' => $module['requires'],
				'enabled' => $this->modules->isSwitchedOn($key),
				'active' => $active[$key],
			];
			$groups[$module['group']] = true;
		}
		$presets = [];
		foreach (Modules::PRESETS as $preset) {
			$presets[] = [
				'key' => $preset,
				'label' => $l->t(Modules::PRESET_LABELS[$preset]['label']),
				'description' => $l->t(Modules::PRESET_LABELS[$preset]['description']),
				'modules' => Modules::presetValues($preset),
			];
		}
		return [
			'modules' => $modules,
			'groups' => array_map(fn (string $group): array => ['key' => $group, 'label' => $l->t(Modules::GROUPS[$group] ?? $group)],
				array_keys($groups)),
			'presets' => $presets,
			'core' => array_map(fn (string $text): string => $l->t($text), Modules::CORE),
		];
	}

	/**
	 * @param ModuleChanges $changes
	 * @return ModuleChanges
	 */
	private function record(array $changes, ?string $uid, ?string $preset): array {
		$operation = $this->audit->newOperation();
		$reason = $preset === null ? null : 'preset ' . $preset;
		foreach ([...$changes['enabled'], ...$changes['alsoEnabled']] as $key) {
			$this->audit->record($operation, $uid, 'settings', null, 'changed', Modules::CONFIG_PREFIX . $key, 'off', 'on', $reason);
		}
		foreach ($changes['disabled'] as $key) {
			$this->audit->record($operation, $uid, 'settings', null, 'changed', Modules::CONFIG_PREFIX . $key, 'on', 'off', $reason);
		}
		return $changes;
	}
}
