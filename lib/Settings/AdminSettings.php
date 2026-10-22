<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Settings;

use OCA\OrchestraScoresManager\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/** @psalm-suppress UnusedClass - Settings class discovered by NextCloud framework */
class AdminSettings implements ISettings {
	private IInitialState $initialStateService;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly ConfigService $configService,
		IInitialState $initialStateService,
		private readonly IURLGenerator $urlGenerator,
	) {
		$this->initialStateService = $initialStateService;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		// Get current allowed groups from config
		$allowedGroups = $this->configService->getAllowedEditGroups();

		// Set initial state for the frontend
		$this->initialStateService->provideInitialState(
			'allowed_groups',
			$allowedGroups
		);

		$this->initialStateService->provideInitialState(
			'repertoire_thresholds',
			$this->configService->getRepertoireThresholds()
		);

		$this->initialStateService->provideInitialState(
			'public_page_enabled',
			$this->configService->isPublicPageEnabled()
		);
		$this->initialStateService->provideInitialState(
			'public_page_url',
			$this->urlGenerator->linkToRouteAbsolute('orchestrascoresmanager.public.index')
		);

		return new TemplateResponse(
			'orchestrascoresmanager',
			'admin-settings',
			[],
			''
		);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'orchestrascoresmanager';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority(): int {
		return 50;
	}
}
