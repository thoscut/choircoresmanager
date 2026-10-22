<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Migration;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Service\Modules;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Writes every module switch that has no stored value yet (appinfo/info.xml:
 * on installation and after every update).
 *
 * An installation from before the modules (installed version below 0.1.27)
 * keeps every function it had: all switches on, the planned interface for
 * agents off. A new installation gets the preset "standard". Switches that
 * exist already are never changed, and a module added by a later version
 * gets its default, so that a later change of the defaults switches nothing
 * at an existing installation.
 */
class ModuleDefaults implements IRepairStep {
	/** The first version with modules */
	public const FIRST_VERSION = '0.1.27';

	public function __construct(
		private IAppConfig $appConfig,
		private Modules $modules,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Store the module switches of the music archive';
	}

	#[\Override]
	public function run(IOutput $output): void {
		// Still the version before the update or installation: Nextcloud sets
		// it after the repair steps; empty for a new installation.
		$installed = $this->appConfig->getAppInstalledVersions()[Application::APP_ID] ?? '';
		$existing = self::isExistingInstallation($installed);
		$written = $this->modules->writeBaseline($existing);
		if ($written !== []) {
			$output->info(($existing ? 'All functions stay on' : 'Preset "standard"') . ': ' . count($written) . ' module switches stored.');
		}
	}

	/**
	 * Whether the installed version is one from before the modules.
	 */
	public static function isExistingInstallation(string $installedVersion): bool {
		return $installedVersion !== '' && version_compare($installedVersion, self::FIRST_VERSION, '<');
	}
}
