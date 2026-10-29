<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Cantoria\Dashboard\DueWidget;
use OCA\Cantoria\Listener\FilesScriptListener;
use OCA\Cantoria\Listener\UserDeletedListener;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Notification\Notifier;
use OCA\Cantoria\Search\CatalogSearchProvider;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'cantoria';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		// Shown only to accounts with an archive role (IConditionalWidget)
		// while the module "dashboard_widget" is on.
		$context->registerDashboardWidget(DueWidget::class);
		// Routes of switched-off modules answer 404 (attribute RequiresModule).
		$context->registerMiddleware(ModuleMiddleware::class);
		// The action "Open in music archive" in the Files app (module
		// "files_integration"), only for accounts with access.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptListener::class);
		// A deleted account no longer stays in the folder book and the audit log.
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		// Copies in the search of Nextcloud (module "unified_search"), only
		// for accounts with a role of the catalog.
		$context->registerSearchProvider(CatalogSearchProvider::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		$context->injectFn($this->registerNavigation(...));
	}

	/**
	 * The app menu entry is only shown to accounts with a role or admin rights,
	 * and to choir members while the module member_links is on (they see only
	 * the page "Shared with me"). Independently of that, the server checks
	 * every request.
	 */
	private function registerNavigation(INavigationManager $navigation, IUserSession $session, Roles $roles,
		IURLGenerator $url, IFactory $l10nFactory, Modules $modules): void {
		$user = $session->getUser();
		if ($user === null) {
			return;
		}
		$uid = $user->getUID();
		if (!$roles->hasAccess($uid) && !($roles->isMember($uid) && $modules->isEnabled(Modules::MEMBER_LINKS))) {
			return;
		}
		$navigation->add(function () use ($url, $l10nFactory): array {
			return [
				'id' => self::APP_ID,
				'order' => 70,
				'href' => $url->linkToRoute(self::APP_ID . '.page.index'),
				'icon' => $url->imagePath(self::APP_ID, 'app.svg'),
				'name' => $l10nFactory->get(self::APP_ID)->t('Music archive'),
			];
		});
	}
}
