<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\RunInProgressException;
use OCA\Cantoria\Service\RunLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A run never runs twice at the same time; the lock is given back also when
 * the run fails, and the status names who started it.
 */
#[CoversClass(RunLock::class)]
final class RunLockTest extends TestCase {
	/** @var array<string, true> */
	private array $locks = [];
	/** @var array<string, string> */
	private array $config = [];

	public function testASecondRunIsRefusedWhileTheFirstHoldsTheLock(): void {
		$lock = $this->runLock();
		$inner = null;
		$result = $lock->run(RunLock::FILE_SYNC, 'lena', function () use ($lock, &$inner): string {
			try {
				$lock->run(RunLock::FILE_SYNC, null, fn (): string => 'second');
			} catch (RunInProgressException $e) {
				$inner = $e->getMessage();
			}
			// Another kind of run is not held up.
			return $lock->run(RunLock::PRINT_MIRROR, null, fn (): string => 'first');
		});
		self::assertSame('first', $result);
		self::assertSame('A file sync is already running. Wait until it has finished; the page shows its report afterwards.', $inner);
		self::assertSame([], $this->locks);
	}

	public function testTheLockIsGivenBackWhenTheRunFails(): void {
		$lock = $this->runLock();
		try {
			$lock->run(RunLock::ANNUAL_SNAPSHOT, 'lena', function (): never {
				throw new \RuntimeException('disk full');
			});
		} catch (\RuntimeException) {
		}
		self::assertSame([], $this->locks);
		self::assertSame('again', $lock->run(RunLock::ANNUAL_SNAPSHOT, 'lena', fn (): string => 'again'));
	}

	public function testTheStateNamesWhoStartedTheRunAndSinceWhen(): void {
		$lock = $this->runLock();
		self::assertNull($lock->state(RunLock::FILE_SYNC));
		$during = $lock->run(RunLock::FILE_SYNC, 'lena', fn (): ?array => $lock->state(RunLock::FILE_SYNC));
		self::assertSame(['since' => 1_700_000_000, 'by' => 'lena'], $during);
		self::assertNull($lock->state(RunLock::FILE_SYNC));
		self::assertSame([], $this->config);
	}

	private function runLock(): RunLock {
		$locking = $this->createStub(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(function (string $path): void {
			if (isset($this->locks[$path])) {
				throw new LockedException($path);
			}
			$this->locks[$path] = true;
		});
		$locking->method('releaseLock')->willReturnCallback(function (string $path): void {
			unset($this->locks[$path]);
		});
		$locking->method('isLocked')->willReturnCallback(fn (string $path): bool => isset($this->locks[$path]));
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $this->config[$key] ?? '');
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		return new RunLock($locking, $config, $time);
	}
}
