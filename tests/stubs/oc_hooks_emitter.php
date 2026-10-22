<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
