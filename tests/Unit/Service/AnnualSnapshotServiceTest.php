<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\AnnualSnapshotService;
use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\Sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Comparing two annual snapshots: copies, rights, licenses and files.
 */
#[CoversClass(AnnualSnapshotService::class)]
#[UsesClass(CatalogCsv::class)]
#[UsesClass(Sort::class)]
final class AnnualSnapshotServiceTest extends TestCase {
	/**
	 * A backup as FullBackupService::parse() returns it.
	 *
	 * @param array<string, list<array<string, string|int|bool|null>>> $tables
	 * @return array{formatVersion: int, created: string, appVersion: string, tables: array<string, list<array<string, string|int|float|bool|null>>>}
	 */
	private static function backup(array $tables): array {
		return ['formatVersion' => 1, 'created' => '2026-01-01T00:00:00+00:00', 'appVersion' => '0.1.29', 'tables' => $tables];
	}

	/**
	 * @return array{formatVersion: int, created: string, appVersion: string, tables: array<string, list<array<string, string|int|float|bool|null>>>}
	 */
	private static function lastYear(): array {
		return self::backup([
			'notarch_works' => [['id' => 1, 'title' => 'Ave verum'], ['id' => 2, 'title' => 'Locus iste']],
			'notarch_versions' => [
				['id' => 1, 'work_id' => 1, 'rights_status' => 'open', 'confirmed_at' => null, 'preservation_copy' => '0'],
				['id' => 2, 'work_id' => 2, 'rights_status' => 'public_domain', 'confirmed_at' => '2025-03-01', 'preservation_copy' => '0'],
			],
			'notarch_copies' => [
				['id' => 1, 'version_id' => 1, 'shelfmark' => '4-1', 'status' => 'active', 'kind' => null],
				['id' => 2, 'version_id' => 2, 'shelfmark' => '4-2', 'status' => 'active', 'kind' => null],
				['id' => 3, 'version_id' => 2, 'shelfmark' => '4-10', 'status' => 'active', 'kind' => null],
			],
			'notarch_licenses' => [['id' => 1, 'version_id' => 1, 'holder' => 'Publisher', 'uses' => 'binder', 'valid_until' => '2026-12-31']],
		]);
	}

	public function testTheDifferencesOfTwoSnapshots(): void {
		$old = self::lastYear();
		$new = self::backup([
			'notarch_works' => [['id' => 1, 'title' => 'Ave verum corpus'], ['id' => 2, 'title' => 'Locus iste'], ['id' => 3, 'title' => 'Abendlied']],
			'notarch_versions' => [
				['id' => 1, 'work_id' => 1, 'rights_status' => 'public_domain', 'confirmed_at' => '2026-05-01', 'preservation_copy' => 0],
				['id' => 2, 'work_id' => 2, 'rights_status' => 'public_domain', 'confirmed_at' => '2025-03-01', 'preservation_copy' => false],
				['id' => 3, 'work_id' => 3, 'rights_status' => 'open'],
			],
			'notarch_copies' => [
				['id' => 1, 'version_id' => 1, 'shelfmark' => '4-1', 'status' => 'active', 'kind' => ''],
				['id' => 3, 'version_id' => 2, 'shelfmark' => '4-10', 'status' => 'retired', 'kind' => null],
				['id' => 4, 'version_id' => 3, 'shelfmark' => '4-3', 'status' => 'active', 'kind' => null],
			],
			'notarch_licenses' => [
				['id' => 1, 'version_id' => 1, 'holder' => 'Publisher', 'uses' => 'binder,successor', 'valid_until' => '2026-12-31'],
				['id' => 2, 'version_id' => 3, 'holder' => 'Other', 'uses' => 'portal'],
			],
		]);
		$entry = fn (string $path, string $sha256, int $size): array => ['path' => $path, 'sha256' => $sha256, 'size' => $size];
		$result = AnnualSnapshotService::compareData($old, $new,
			['a.pdf' => $entry('a.pdf', 'aa', 1), 'b.pdf' => $entry('b.pdf', 'bb', 2)],
			['a.pdf' => $entry('a.pdf', 'aa', 1), 'b.pdf' => $entry('b.pdf', 'cc', 2), 'c.pdf' => $entry('c.pdf', 'dd', 3)]);

		self::assertSame([['shelfmark' => '4-3', 'title' => 'Abendlied']], $result['copies']['added']);
		self::assertSame([['shelfmark' => '4-2', 'title' => 'Locus iste']], $result['copies']['removed']);
		self::assertSame([
			['shelfmark' => '4-1', 'title' => 'Ave verum corpus', 'changes' => [['field' => 'title', 'old' => 'Ave verum', 'new' => 'Ave verum corpus']]],
			['shelfmark' => '4-10', 'title' => 'Locus iste', 'changes' => [['field' => 'status', 'old' => 'active', 'new' => 'retired']]],
		], $result['copies']['changed'], 'an empty text is no change; shelf order 4-1 before 4-10');
		self::assertSame([['shelfmark' => '4-1', 'title' => 'Ave verum corpus', 'changes' => [
			['field' => 'rights_status', 'old' => 'open', 'new' => 'public_domain'],
			['field' => 'confirmed_at', 'old' => null, 'new' => '2026-05-01'],
		]]], $result['rights'], '"0", 0 and false are the same');
		self::assertSame(['changed', 'added'], array_column($result['licenses'], 'change'));
		self::assertSame([['field' => 'uses', 'old' => 'binder', 'new' => 'binder,successor']], $result['licenses'][0]['changes']);
		self::assertSame([['path' => 'c.pdf', 'size' => 3]], $result['files']['added']);
		self::assertSame([['path' => 'b.pdf', 'size' => 2]], $result['files']['changed']);
		self::assertSame([], $result['files']['removed']);
		self::assertTrue($result['files']['compared']);
	}

	public function testASnapshotComparedWithItselfHasNoDifferences(): void {
		$result = AnnualSnapshotService::compareData(self::lastYear(), self::lastYear(), [], []);
		self::assertSame([], [...$result['copies']['added'], ...$result['copies']['removed'], ...$result['copies']['changed'],
			...$result['rights'], ...$result['licenses'], ...$result['files']['added'], ...$result['files']['changed']]);
	}

	public function testWithoutAManifestTheFilesAreNotCompared(): void {
		$result = AnnualSnapshotService::compareData(self::lastYear(), self::lastYear(), null, []);
		self::assertFalse($result['files']['compared']);
	}

	public function testTheManifestIsReadBack(): void {
		$csv = CatalogCsv::writeForPeople(['sha256', 'size', 'path'], [['ABC', '12', 'Mappe 1/1-1; Titel.pdf'], ['def', '', 'x.pdf']]);
		self::assertSame([
			'Mappe 1/1-1; Titel.pdf' => ['path' => 'Mappe 1/1-1; Titel.pdf', 'sha256' => 'abc', 'size' => 12],
			'x.pdf' => ['path' => 'x.pdf', 'sha256' => 'def', 'size' => null],
		], AnnualSnapshotService::parseManifest($csv));
		$this->expectException(InvalidInputException::class);
		AnnualSnapshotService::parseManifest("a;b\r\n1;2\r\n");
	}
}
