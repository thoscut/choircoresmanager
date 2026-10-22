<?php

declare(strict_types=1);

/**
 * Stub of the server-internal interface that OCP\Files\IRootFolder extends,
 * so that static analysis works without a Nextcloud server checkout.
 */

namespace OC\Hooks;

interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @param callable $callback
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string|null $scope
	 * @param string|null $method
	 * @param callable|null $callback
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
