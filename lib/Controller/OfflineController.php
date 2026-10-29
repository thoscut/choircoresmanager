<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\Modules;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The service worker of the pages that keep pieces on a device (milestone
 * 0.15): "Shared with me" and public links (module offline) and the concert
 * folder (module concert_folder). Served from the app's own path, so that
 * its scope covers these pages. It keeps nothing by itself: it only answers
 * from what a page put on the device, when there is no network. Registered
 * by the browser, also on the page of a public link, therefore without
 * login and CSRF token; it is a script of the app and contains no data.
 * With both modules off, a worker that deletes everything kept on the
 * device and unregisters itself; therefore the route belongs to the core and
 * is always on.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class OfflineController extends Controller {
	public function __construct(
		IRequest $request,
		private Modules $modules,
		private IAppManager $apps,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 600)]
	#[UserRateLimit(limit: 60, period: 600)]
	#[BruteForceProtection(action: 'cantoria_offline_worker')]
	#[FrontpageRoute(verb: 'GET', url: '/offline-worker')]
	public function worker(): Response {
		$script = self::REMOVING_WORKER;
		if ($this->modules->isEnabled(Modules::OFFLINE) || $this->modules->isEnabled(Modules::CONCERT_FOLDER)) {
			$built = @file_get_contents(__DIR__ . '/../../js/' . Application::APP_ID . '-offline-worker.js');
			if ($built === false) {
				return new JSONResponse(['error' => $this->l->t('The app is not built.')], Http::STATUS_NOT_FOUND);
			}
			// A new version of the app gives a new worker, which drops the old cache of the pages
			$script = str_replace('__CANTORIA_VERSION__', $this->apps->getAppVersion(Application::APP_ID), $built);
		}
		$response = new DataDisplayResponse($script, Http::STATUS_OK, ['Content-Type' => 'text/javascript; charset=utf-8']);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(0, false, false);
		// The policy of the worker itself: requests to this server only
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}

	/** The worker while both modules are off: it removes what the app kept on the device. */
	public const REMOVING_WORKER = <<<'JS'
		self.addEventListener('install', () => self.skipWaiting())
		self.addEventListener('activate', (event) => event.waitUntil((async () => {
			for (const key of await caches.keys()) {
				if (key.startsWith('cantoria-')) {
					await caches.delete(key)
				}
			}
			await self.registration.unregister()
		})()))
		JS;
}
