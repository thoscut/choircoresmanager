<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Support;

use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * A temp manager stub that hands out real temporary files and removes them
 * after the test.
 *
 * @psalm-require-extends TestCase
 */
trait TempFiles {
	/** @var list<string> */
	private array $tempFiles = [];

	protected function tempManager(): ITempManager {
		$manager = $this->createStub(ITempManager::class);
		$manager->method('getTemporaryFile')->willReturnCallback(function (string $postFix = ''): string {
			$path = tempnam(sys_get_temp_dir(), 'notenarchiv-test');
			if ($path === false) {
				throw new \RuntimeException('No temporary file');
			}
			$this->tempFiles[] = $path;
			return $path;
		});
		return $manager;
	}

	protected function tempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'notenarchiv-test');
		if ($path === false) {
			throw new \RuntimeException('No temporary file');
		}
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	/**
	 * Builds a ZIP archive in memory from name => content.
	 *
	 * @param array<string, string> $entries
	 */
	protected function zip(array $entries): string {
		$path = $this->tempFile('');
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Cannot create ZIP');
		}
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return (string)file_get_contents($path);
	}

	#[\PHPUnit\Framework\Attributes\After]
	protected function removeTempFiles(): void {
		foreach ($this->tempFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
		$this->tempFiles = [];
	}
}
