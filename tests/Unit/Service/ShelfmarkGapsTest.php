<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\InventoryService;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The report of gaps in the shelf marks (milestone 0.8): information only,
 * the numbers between 1 and the highest one used that no copy has.
 */
#[CoversClass(InventoryService::class)]
final class ShelfmarkGapsTest extends TestCase {
	public function testGapsAreGroupedIntoRanges(): void {
		self::assertSame([
			'binder' => 5, 'highest' => 12, 'copies' => 6, 'missing' => 6,
			'ranges' => [['from' => 1, 'to' => 2], ['from' => 5, 'to' => 7], ['from' => 11, 'to' => 11]],
			'withFile' => [6, 11],
		], InventoryService::gapReport(5, [3, 4, 8, 9, 10, 12], [6, 11, 12]));
	}

	public function testANumberUsedOnlyByAFileRaisesTheHighestNumber(): void {
		$report = InventoryService::gapReport(2, [1, 2], [5]);
		self::assertSame(5, $report['highest']);
		self::assertSame([['from' => 3, 'to' => 5]], $report['ranges']);
		self::assertSame([5], $report['withFile']);
	}

	public function testABinderWithoutGaps(): void {
		$report = InventoryService::gapReport(1, [1, 2, 3], []);
		self::assertSame(0, $report['missing']);
		self::assertSame([], $report['ranges']);
	}

	public function testEveryBinderOfCopiesAndFileNames(): void {
		$copy = new Copy();
		$copy->setBinder(1);
		$copy->setNumber(2);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAll')->willReturn([$copy]);
		$named = new ArchiveFile();
		$named->setName('3-04_Title.pdf');
		$other = new ArchiveFile();
		$other->setName('LIESMICH.txt');
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findAllByFileId')->willReturn([1 => $named, 2 => $other]);
		$service = new InventoryService($this->createStub(IDBConnection::class), $copies, $this->createStub(WorkVersionMapper::class),
			$this->createStub(WorkMapper::class), $this->createStub(CatalogService::class), $this->createStub(RightsService::class),
			$this->createStub(Roles::class), $files);

		$gaps = $service->gaps();
		self::assertSame([1, 3], array_column($gaps, 'binder'));
		self::assertSame([['from' => 1, 'to' => 1]], $gaps[0]['ranges']);
		self::assertSame([['from' => 1, 'to' => 4]], $gaps[1]['ranges']);
		self::assertSame([4], $gaps[1]['withFile']);
	}
}
