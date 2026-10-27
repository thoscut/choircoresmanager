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
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\LinkTokens;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScanService;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Tests\Unit\Support\FakeCrypto;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Link tokens (milestone 0.11): the table holds the hash and the encrypted
 * token, never the token itself; lists show the token of a link for choir
 * members or the public only to its creator and the catalog librarian, and
 * no account names; links to preservation copies only to the roles that
 * may view them.
 */
#[CoversClass(LinkTokens::class)]
#[CoversClass(ExcerptLink::class)]
#[CoversClass(LinkService::class)]
final class LinkTokensTest extends TestCase {
	private const TOKEN = 'abcdefghijABCDEFGHIJ0123456789xy';

	private function tokens(): LinkTokens {
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(self::TOKEN);
		return new LinkTokens(new FakeCrypto(), $random);
	}

	private function stored(string $circle, string $createdBy = 'lena', int $copyId = 1): ExcerptLink {
		$made = new ExcerptLink();
		$this->tokens()->issue($made);
		// as read back from the table: without the token just made
		$link = new ExcerptLink();
		$link->setId(9);
		$link->setToken($made->getToken());
		$link->setTokenSecret($made->getTokenSecret());
		$link->setCopyId($copyId);
		$link->setCircle($circle);
		$link->setCreatedBy($createdBy);
		$link->setExpires('2099-12-31');
		return $link;
	}

	public function testTheTableHoldsTheHashAndTheEncryptedTokenOnly(): void {
		$link = new ExcerptLink();
		$this->tokens()->issue($link);
		self::assertSame(hash('sha256', self::TOKEN), $link->getToken());
		self::assertNotSame(self::TOKEN, $link->getTokenSecret());
		self::assertStringNotContainsString(self::TOKEN, (string)$link->getTokenSecret());
		self::assertSame(self::TOKEN, $link->issuedToken(), 'the creator gets the token once');
		self::assertArrayNotHasKey('token', $link->jsonSerialize());
		self::assertArrayNotHasKey('token_secret', $link->jsonSerialize());
	}

	public function testTheTokenIsReadBackOnlyWithTheSecretOfTheInstance(): void {
		$link = $this->stored(ExcerptLink::CIRCLE_PUBLIC);
		self::assertNull($link->issuedToken());
		self::assertSame(self::TOKEN, $this->tokens()->reveal($link));
		$link->setTokenSecret('from another instance');
		self::assertNull($this->tokens()->reveal($link));
		$link->setTokenSecret(null);
		self::assertNull($this->tokens()->reveal($link), 'restored from a backup: the token is gone');
		$other = $this->stored(ExcerptLink::CIRCLE_PUBLIC);
		$other->setToken(ExcerptLink::hashToken('another token'));
		self::assertNull($this->tokens()->reveal($other), 'a secret that does not match the hash');
	}

	/**
	 * @param list<string> $roles
	 */
	private function service(array $roles, bool $preservationKind = false): LinkService {
		$links = $this->createStub(ExcerptLinkMapper::class);
		$links->method('findByCopy')->willReturn([
			$this->stored(ExcerptLink::CIRCLE_INTERNAL),
			$this->stored(ExcerptLink::CIRCLE_MEMBERS),
			$this->stored(ExcerptLink::CIRCLE_PUBLIC),
			$this->stored(ExcerptLink::CIRCLE_PUBLIC, 'anna'),
			$this->stored(ExcerptLink::CIRCLE_INTERNAL, 'lena', 2),
		]);
		$copies = [];
		foreach ([1, 2] as $id) {
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId(10 + $id);
			$copy->setKind($id === 2 && $preservationKind ? RightsPolicy::PRESERVATION_COPY_KIND : null);
			$copies[] = $copy;
		}
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAll')->willReturn($copies);
		$versions = [];
		foreach ([11, 12] as $id) {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setPreservationCopy($id === 12 && !$preservationKind);
			$versions[$id] = $version;
		}
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$roleStub = $this->createStub(Roles::class);
		$roleStub->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => array_intersect($roles, $allowed) !== []);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-27');
		$users = $this->createStub(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(fn (string $uid): string => ucfirst($uid));
		return new LinkService($links, $copyMapper, $versionMapper, $this->createStub(ArchiveFileMapper::class), $this->createStub(ScoreService::class),
			new RightsPolicy($roleStub, $rights, $this->createStub(\OCA\Notenarchiv\Db\LicenseMapper::class)), $rights,
			$this->createStub(AuditLog::class), $roleStub, $users, $this->tokens(), $this->createStub(ITimeFactory::class),
			$this->createStub(Modules::class), $this->createStub(ScanService::class));
	}

	/**
	 * @return array<string, array{string, list<string>, list<?string>, int}>
	 */
	public static function viewers(): array {
		$t = self::TOKEN;
		return [
			// the internal links, lena's members and public link, anna's public link, the link to copy 2
			'catalog librarian' => ['anna', [Roles::LIBRARIAN], [$t, $t, $t, $t, $t], 5],
			'creator (operations)' => ['lena', [Roles::OPERATIONS], [$t, $t, $t, null], 4],
			'another role' => ['olaf', [Roles::OPERATIONS], [$t, null, null, null], 4],
			'conductor' => ['carla', [Roles::CONDUCTOR], [$t, null, null, null, $t], 5],
		];
	}

	/**
	 * @param list<string> $roles
	 * @param list<?string> $tokens
	 */
	#[DataProvider('viewers')]
	public function testOnlyTheCreatorAndTheLibrarianSeeTokensThatOpenALinkToOthers(string $uid, array $roles, array $tokens, int $count): void {
		foreach ([false, true] as $preservationKind) {
			$rows = $this->service($roles, $preservationKind)->active(null, $uid);
			self::assertCount($count, $rows, 'links to a preservation copy only for the roles that may view it');
			self::assertSame($tokens, array_column($rows, 'token'));
			foreach ($rows as $row) {
				self::assertArrayNotHasKey('created_by', $row, 'no account names');
				self::assertArrayNotHasKey('token_secret', $row);
				self::assertSame($row['created_by_name'] === ucfirst($uid), $row['own']);
			}
		}
	}
}
