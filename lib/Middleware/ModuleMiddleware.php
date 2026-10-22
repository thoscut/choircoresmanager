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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;

/**
 * Switched-off modules are off on the server, too: a route of a module that
 * is not active answers 404 with `{ "error": "This function is switched
 * off." }` before the controller method runs (attribute RequiresModule on
 * the method or its controller). Runs after Nextcloud's own checks (login,
 * admin, CSRF), so it tells nothing to whoever may not call the route.
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

	#[\Override]
	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof ModuleDisabledException) {
			return new JSONResponse(['error' => $this->l->t('This function is switched off.')], Http::STATUS_NOT_FOUND);
		}
		throw $exception;
	}
}
