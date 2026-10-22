<?php

declare(strict_types=1);

/**
 * Stub of a private Nextcloud server interface that the public API depends on:
 * OCP\Files\IRootFolder extends OC\Hooks\Emitter, which is not part of the
 * nextcloud/ocp package. Used by Psalm and by the unit tests (so that
 * IRootFolder can be mocked without a server). Signatures as in Nextcloud 33.
 */

namespace OC\Hooks;

/**
 * @deprecated 18.0.0 use events and the \OCP\EventDispatcher\IEventDispatcher service
 */
interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @return void
	 * @deprecated 18.0.0 use \OCP\EventDispatcher\IEventDispatcher::addListener
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string|null $scope
	 * @param string|null $method
	 * @return void
	 * @deprecated 18.0.0 use \OCP\EventDispatcher\IEventDispatcher::removeListener
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
