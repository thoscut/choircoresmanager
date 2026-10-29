<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Listener;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Adds the action "Open in Cantoria" to the Files app (module
 * "files_integration"): its script (src/files.ts, without the score viewer
 * and without the app itself) and the initial state `files` it needs.
 *
 * Only accounts with access to the music archive (a role, or an admin, who
 * may read) get it, only while the module is on, and only when the account
 * can see the archive folder. Whoever gets it gains nothing: the server
 * checks every request as always.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptListener implements IEventListener {
	/** Script of the Files app, built from src/files.ts */
	public const SCRIPT = Application::APP_ID . '-files';
	/** Key of the initial state (`loadState('cantoria', 'files')`) */
	public const STATE = 'files';

	public function __construct(
		private IUserSession $session,
		private Roles $roles,
		private Modules $modules,
		private FilesIntegrationService $filesIntegration,
		private IInitialState $initialState,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null || !$this->modules->isEnabled(Modules::FILES_INTEGRATION) || !$this->roles->hasAccess($uid)) {
			return;
		}
		try {
			$state = $this->filesIntegration->clientState($uid);
		} catch (\Throwable $e) {
			// The Files app must work whatever happens here.
			$this->logger->warning('Cantoria: the action for the Files app is not available: ' . $e->getMessage(), ['exception' => $e]);
			return;
		}
		if ($state === null) {
			return;
		}
		$this->initialState->provideInitialState(self::STATE, $state);
		$this->addScript();
	}

	/**
	 * Adds the script as an init script, so that the action is registered
	 * before the Files app starts (its own translations come with it). Kept
	 * apart for the unit tests: OCP\Util needs a running server.
	 */
	protected function addScript(): void {
		Util::addInitScript(Application::APP_ID, self::SCRIPT);
	}
}
