<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\AppInfo;

use OCA\Notenarchiv\Dashboard\DueWidget;
use OCA\Notenarchiv\Middleware\ModuleMiddleware;
use OCA\Notenarchiv\Notification\Notifier;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class Application extends App implements IBootstrap {
	public const APP_ID = 'notenarchiv';

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
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		$context->injectFn($this->registerNavigation(...));
	}

	/**
	 * The app menu entry is only shown to accounts with a role or admin rights.
	 * Independently of that, the server checks every request.
	 */
	private function registerNavigation(INavigationManager $navigation, IUserSession $session, Roles $roles,
		IURLGenerator $url, IFactory $l10nFactory): void {
		$user = $session->getUser();
		if ($user === null || !$roles->hasAccess($user->getUID())) {
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
