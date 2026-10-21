<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\integration\Db;

use OCA\OrchestraScoresManager\Db\Share;
use OCA\OrchestraScoresManager\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Integration tests for ShareMapper.
 *
 * These tests require a NextCloud environment and run in CI.
 */
final class ShareMapperTest extends MapperTestCase {
	private ShareMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ShareMapper($this->db);
	}

	private function createShare(string $type, string $targetType, int $targetId, ?string $token = null, ?string $groupId = null): Share {
		$share = new Share();
		$share->setShareType($type);
		$share->setTargetType($targetType);
		$share->setTargetId($targetId);
		$share->setToken($token);
		$share->setGroupId($groupId);
		$share->setCreatedBy('admin');
		$share->setCreatedAt(1700000000);
		return $this->mapper->insert($share);
	}

	public function testFindLinkShareByToken(): void {
		$share = $this->createShare('link', 'score', 1, 'tokentokentokentokentokentoken01');

		$this->assertSame($share->getId(), $this->mapper->findLinkShareByToken('tokentokentokentokentokentoken01')->getId());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findLinkShareByToken('tokentokentokentokentokentoken02');
	}

	public function testFindForTargetAndDeleteForTarget(): void {
		$this->createShare('link', 'score', 1, 'tokentokentokentokentokentoken01');
		$this->createShare('group', 'score', 1, null, 'tenors');
		$this->createShare('group', 'folder_collection', 1, null, 'tenors');

		$this->assertCount(2, $this->mapper->findForTarget('score', 1));

		$this->mapper->deleteForTarget('score', 1);

		$this->assertSame([], $this->mapper->findForTarget('score', 1));
		$this->assertCount(1, $this->mapper->findForTarget('folder_collection', 1));
	}

	public function testFindGroupSharesForGroups(): void {
		$this->createShare('link', 'score', 1, 'tokentokentokentokentokentoken01');
		$this->createShare('group', 'score', 2, null, 'tenors');
		$this->createShare('group', 'score', 3, null, 'basses');

		$shares = $this->mapper->findGroupSharesForGroups(['tenors', 'altos']);

		$this->assertSame([2], array_map(fn (Share $s) => $s->getTargetId(), $shares));
		$this->assertSame([], $this->mapper->findGroupSharesForGroups([]));
	}
}
