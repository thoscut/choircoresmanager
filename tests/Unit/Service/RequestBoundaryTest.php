<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\ExcerptLinkMapper;
use OCA\Notenarchiv\Db\TaskMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\InvalidRowsException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\InventoryService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\LinkTokens;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScanService;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Tests\Unit\Support\FakeCrypto;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Request data at the service boundary: unknown fields are refused instead
 * of being ignored, ids must be ids, and lists are bounded.
 */
#[CoversClass(LinkService::class)]
#[CoversClass(TaskService::class)]
#[CoversClass(InventoryService::class)]
final class RequestBoundaryTest extends TestCase {
	private function rights(): RightsService {
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-22');
		return $rights;
	}

	private function links(?ExcerptLinkMapper $mapper = null): LinkService {
		$copy = new Copy();
		$copy->setId(35);
		$copy->setVersionId(3);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($copy);
		$version = new WorkVersion();
		$version->setId(3);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($version);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 32));
		$mapper ??= $this->createStub(ExcerptLinkMapper::class);
		return new LinkService($mapper, $copies, $versions, $this->createStub(ArchiveFileMapper::class),
			$this->createStub(ScoreService::class), $this->createStub(RightsPolicy::class), $this->rights(), $this->createStub(AuditLog::class),
			$this->createStub(Roles::class), $this->createStub(IUserManager::class), new LinkTokens(new FakeCrypto(), $random), $this->createStub(ITimeFactory::class),
			$this->createStub(Modules::class), $this->createStub(ScanService::class));
	}

	public function testLinkRefusesUnknownFields(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown field: %s');
		$this->links()->create(35, ['voice' => 'Tenor 1', 'created_by' => 'someone-else'], 'conductor');
	}

	public function testLinkRefusesATaskIdThatIsNoId(): void {
		try {
			$this->links()->create(35, ['task_id' => 'abc'], 'conductor');
			self::fail('no exception');
		} catch (InvalidInputException $e) {
			self::assertSame(['"%s": value not allowed.', ['Task']], [$e->getMessage(), $e->getParameters()]);
		}
	}

	public function testLinkKeepsAValidTaskId(): void {
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (ExcerptLink $link): ExcerptLink {
			$link->setId(9);
			return $link;
		});
		$link = $this->links($mapper)->create(35, ['task_id' => '4', 'voice' => 'Tenor 1'], 'conductor');
		self::assertSame(4, $link->getTaskId());
		self::assertSame('Tenor 1', $link->getVoice());
	}

	public function testUnknownTokenIsReportedAsSuch(): void {
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('findByToken')->willThrowException(new DoesNotExistException('none'));
		$this->expectException(UnknownLinkException::class);
		$this->expectExceptionMessage('This link does not exist.');
		$this->links($mapper)->resolve(str_repeat('b', 32), 'reader');
	}

	public function testALinkToADeletedCopyIsNoGuess(): void {
		$link = new ExcerptLink();
		$link->setCopyId(99);
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('findByToken')->willReturn($link);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willThrowException(new DoesNotExistException('none'));
		$service = new LinkService($mapper, $copies, $this->createStub(WorkVersionMapper::class), $this->createStub(ArchiveFileMapper::class),
			$this->createStub(ScoreService::class), $this->createStub(RightsPolicy::class), $this->rights(), $this->createStub(AuditLog::class),
			$this->createStub(Roles::class), $this->createStub(IUserManager::class), new LinkTokens(new FakeCrypto(), $this->createStub(ISecureRandom::class)),
			$this->createStub(ITimeFactory::class), $this->createStub(Modules::class), $this->createStub(ScanService::class));
		try {
			$service->resolve(str_repeat('b', 32), 'reader');
			self::fail('no exception');
		} catch (InvalidInputException $e) {
			self::assertNotInstanceOf(UnknownLinkException::class, $e);
		}
	}

	public function testTaskRefusesUnknownFields(): void {
		$service = new TaskService($this->createStub(TaskMapper::class), $this->createStub(CopyMapper::class),
			$this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class), $this->createStub(AuditLog::class),
			$this->createStub(Roles::class), $this->createStub(NotificationService::class), $this->createStub(IUserManager::class),
			$this->createStub(ITimeFactory::class));
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown field: %s');
		$service->create(35, ['addressee' => 'conductor', 'question' => 'Bar 3?', 'status' => 'applied'], 'contributor');
	}

	public function testInventoryCountsAreBounded(): void {
		$catalog = $this->createMock(CatalogService::class);
		$catalog->expects(self::never())->method('update');
		$service = new InventoryService($this->createStub(IDBConnection::class), $this->createStub(CopyMapper::class),
			$this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class), $catalog, $this->rights(),
			$this->createStub(Roles::class), $this->createStub(ArchiveFileMapper::class));
		$counts = array_fill(0, InventoryService::MAX_COUNTS + 1, ['copyId' => 1]);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Too many entries at once (at most %s).');
		$service->recordCounts($counts, null, 'operations');
	}
	public function testInventoryNamesTheFaultyRowsAndRecordsNothing(): void {
		$catalog = $this->createStub(CatalogService::class);
		$catalog->method('update')->willReturnCallback(function (string $type, int $id, array $values): array {
			if ($id === 2) {
				throw new InvalidInputException('"%s" must be a whole number of at least 0.', ['Number of copies']);
			}
			return [];
		});
		$copy = new Copy();
		$copy->setShelfmark('2-7');
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($copy);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('commit');
		$db->expects(self::once())->method('rollBack');
		$service = new InventoryService($db, $copies, $this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class), $catalog,
			$this->rights(), $this->createStub(Roles::class), $this->createStub(ArchiveFileMapper::class));
		try {
			$service->recordCounts([['copyId' => 1, 'copies' => 3], ['copyId' => 2, 'copies' => -1], ['copyId' => 'x']], null, 'operations');
			self::fail('no exception');
		} catch (InvalidRowsException $e) {
			self::assertSame(['2-7', '#3'], array_column($e->getRows(), 'row'));
			self::assertSame(['Number of copies'], $e->getRows()[0]['parameters']);
		}
	}
}
