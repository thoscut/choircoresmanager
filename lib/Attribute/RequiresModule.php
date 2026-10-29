<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Attribute;

/**
 * Marks a controller or a controller method as part of a module (see
 * Service\Modules). When the module is not active, Middleware\ModuleMiddleware
 * answers with 404 before the method runs. On a class it applies to every
 * route of the controller; several attributes must all be active.
 *
 * Routes without this attribute belong to the core; a unit test makes sure
 * that every route is either listed as core or has this attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresModule {
	public function __construct(
		public readonly string $module,
	) {
	}
}
