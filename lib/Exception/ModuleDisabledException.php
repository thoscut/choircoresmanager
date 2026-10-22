<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Exception;

/**
 * A function of a module that the administration has switched off was
 * called. The controllers answer with 404 and "This function is switched
 * off." (see Middleware\ModuleMiddleware).
 */
class ModuleDisabledException extends \Exception {
	public function __construct(string $module) {
		parent::__construct('Module switched off: ' . $module);
	}
}
