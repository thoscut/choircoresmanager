<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Middleware;

use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Service\Modules;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;

/**
 * Switched-off modules are off on the server, too: a route of a module that
 * is not active answers 404 with `{ "error": "This function is switched
 * off." }` before the controller method runs (attribute RequiresModule on
 * the method or its controller); a route of the API for agents (OCS) answers
 * 404 with the message in the OCS meta. Runs after Nextcloud's own checks
 * (login, admin, CSRF, rate limits), so it tells nothing to whoever may not
 * call the route.
 *
 * A public page (#[PublicPage], milestone 0.7) answers with the same 404 page
 * as an unknown, expired or revoked link (notFoundPage()), so that nobody
 * learns from outside why a link does not work.
 */
class ModuleMiddleware extends Middleware {
	public function __construct(
		private Modules $modules,
		private IL10N $l,
	) {
	}

	/**
	 * The modules a controller method needs: those of its class and its own.
	 *
	 * @param object|class-string $controller
	 * @return list<string>
	 */
	public static function required(object|string $controller, string $methodName): array {
		$attributes = (new \ReflectionClass($controller))->getAttributes(RequiresModule::class);
		if (method_exists($controller, $methodName)) {
			$attributes = [...$attributes, ...(new \ReflectionMethod($controller, $methodName))->getAttributes(RequiresModule::class)];
		}
		return array_values(array_unique(array_map(fn (\ReflectionAttribute $a): string => $a->newInstance()->module, $attributes)));
	}

	/**
	 * @throws ModuleDisabledException
	 */
	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		foreach (self::required($controller, $methodName) as $module) {
			$this->modules->requireEnabled($module);
		}
	}

	/**
	 * The one answer of the public pages for everything they do not show:
	 * Nextcloud's 404 page, marked for the brute-force protection, without
	 * a reason.
	 */
	public static function notFoundPage(): Response {
		$response = new NotFoundResponse();
		$response->throttle(['reason' => 'not_found']);
		return $response;
	}

	/**
	 * Whether a controller method is a public page.
	 *
	 * @param object|class-string $controller
	 */
	public static function isPublicPage(object|string $controller, string $methodName): bool {
		return method_exists($controller, $methodName)
			&& (new \ReflectionMethod($controller, $methodName))->getAttributes(PublicPage::class) !== [];
	}

	/**
	 * @throws OCSNotFoundException for a route of the API for agents (OCS)
	 */
	#[\Override]
	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof ModuleDisabledException) {
			if (self::isPublicPage($controller, $methodName)) {
				return self::notFoundPage();
			}
			$message = $this->l->t('This function is switched off.');
			if ($controller instanceof OCSController) {
				// Nextcloud's OCS middleware comes earlier in the chain, so it
				// handles this exception next and answers 404 with OCS meta.
				throw new OCSNotFoundException($message);
			}
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}
		throw $exception;
	}
}
