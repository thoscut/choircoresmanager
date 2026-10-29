<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Exception\RunInProgressException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Keeps a run (file sync, print station, annual snapshot) from running
 * twice at the same time, whether it was started on the web, by `occ` or by
 * a background job. Uses the locking of Nextcloud (database or memory
 * cache), so a run that dies leaves no lock behind for longer than the lock
 * lifetime of the server.
 *
 * While a run holds the lock, who started it and since when is kept in the
 * app config, for the status display.
 *
 * @psalm-type RunState = array{since: int, by: string|null}
 */
class RunLock {
	public const FILE_SYNC = 'file_sync';
	public const PRINT_MIRROR = 'print_mirror';
	public const CHOIR_HANDOVER = 'choir_handover';
	public const ANNUAL_SNAPSHOT = 'annual_snapshot';

	public function __construct(
		private ILockingProvider $locking,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Runs `$run` while holding the lock of `$name`.
	 *
	 * @template T
	 * @param self::FILE_SYNC|self::PRINT_MIRROR|self::CHOIR_HANDOVER|self::ANNUAL_SNAPSHOT $name
	 * @param string|null $uid who started it; null for a background job
	 * @param callable(): T $run
	 * @return T
	 * @throws RunInProgressException when the run is already in progress
	 */
	public function run(string $name, ?string $uid, callable $run): mixed {
		$path = self::path($name);
		try {
			$this->locking->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, 'Cantoria ' . $name);
		} catch (LockedException) {
			throw match ($name) {
				self::FILE_SYNC => new RunInProgressException('A file sync is already running. Wait until it has finished; the page shows its report afterwards.'),
				self::PRINT_MIRROR => new RunInProgressException('The print station is already being synced. Wait until that run has finished.'),
				self::CHOIR_HANDOVER => new RunInProgressException('The hand-over folder is already being synced. Wait until that run has finished.'),
				self::ANNUAL_SNAPSHOT => new RunInProgressException('An annual snapshot is already being made. Wait until it has finished.'),
			};
		}
		try {
			$this->appConfig->setValueString(Application::APP_ID, self::key($name),
				json_encode(['since' => $this->time->getTime(), 'by' => $uid], JSON_THROW_ON_ERROR));
			// A run started on the web finishes even if the page is closed.
			ignore_user_abort(true);
			return $run();
		} finally {
			$this->appConfig->deleteKey(Application::APP_ID, self::key($name));
			$this->locking->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * The run in progress, or null.
	 *
	 * @param self::FILE_SYNC|self::PRINT_MIRROR|self::CHOIR_HANDOVER|self::ANNUAL_SNAPSHOT $name
	 * @return RunState|null
	 */
	public function state(string $name): ?array {
		if (!$this->locking->isLocked(self::path($name), ILockingProvider::LOCK_EXCLUSIVE)) {
			return null;
		}
		return self::stateOf(json_decode($this->appConfig->getValueString(Application::APP_ID, self::key($name)), true));
	}

	/**
	 * @return RunState
	 */
	private static function stateOf(mixed $state): array {
		if (!is_array($state)) {
			return ['since' => 0, 'by' => null];
		}
		return [
			'since' => isset($state['since']) && is_int($state['since']) ? $state['since'] : 0,
			'by' => isset($state['by']) && is_string($state['by']) ? $state['by'] : null,
		];
	}

	private static function path(string $name): string {
		return 'cantoria/run/' . $name;
	}

	private static function key(string $name): string {
		return 'run_' . $name;
	}
}
