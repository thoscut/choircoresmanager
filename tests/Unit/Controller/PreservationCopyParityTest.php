<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Controller;

use OCA\Cantoria\Controller\ApiController;
use OCA\Cantoria\Controller\ApiV1CatalogController;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\LicenseMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\ExportService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\ImportService;
use OCA\Cantoria\Service\MetaService;
use OCA\Cantoria\Service\RepertoireService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\TaskService;
use OCA\Cantoria\Service\UserFiles;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The internal API (the app's own pages) and the API for agents (v1) show
 * and refuse the same internal preservation copies for every role: a
 * version marked as one, and a copy of the kind "Preservation copy". Both
 * ask RightsPolicy; the catalog and the policy are real, the mappers are
 * test doubles.
 */
#[CoversClass(CatalogService::class)]
#[CoversClass(RightsPolicy::class)]
final class PreservationCopyParityTest extends TestCase {
	use ModuleSwitches;

	/** Copy 1: ordinary, copy 2: version marked, copy 3: copy of the kind preservation copy */
	private const ORDINARY = 1;
	private const MARKED_VERSION = 2;
	private const PRESERVATION_KIND = 3;

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function roles(): array {
		return [
			'catalog librarian' => [Roles::LIBRARIAN, true],
			'conductor' => [Roles::CONDUCTOR, true],
			'operations' => [Roles::OPERATIONS, false],
			'contributor' => [Roles::CONTRIBUTOR, false],
		];
	}

	private function roleStub(string $role): Roles {
		$roles = $this->createStub(Roles::class);
		$roles->method('rolesOf')->willReturn([$role]);
		$roles->method('hasAccess')->willReturn(true);
		$roles->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => in_array($role, $allowed, true));
		return $roles;
	}

	/**
	 * @return array{CatalogService, RightsPolicy, Roles}
	 */
	private function services(string $role): array {
		$works = [];
		$versions = [];
		$copies = [];
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$work = new Work();
			$work->setId(100 + $id);
			$work->setTitle('Werk ' . $id);
			$works[$work->getId()] = $work;
			$version = new WorkVersion();
			$version->setId(200 + $id);
			$version->setWorkId(100 + $id);
			$version->setPreservationCopy($id === self::MARKED_VERSION);
			$version->setRightsStatus('public_domain');
			$versions[$version->getId()] = $version;
			$copy = new Copy();
			$copy->setId($id);
			$copy->setVersionId(200 + $id);
			$copy->setShelfmark('1-' . $id);
			$copy->setBinder(1);
			$copy->setNumber($id);
			$copy->setStatus('active');
			$copy->setKind($id === self::PRESERVATION_KIND ? RightsPolicy::PRESERVATION_COPY_KIND : null);
			$copies[$id] = $copy;
		}
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$workMapper->method('find')->willReturnCallback(fn (int $id): Work => $works[$id]);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$versionMapper->method('find')->willReturnCallback(fn (int $id): WorkVersion => $versions[$id]);
		$versionMapper->method('findByWork')->willReturnCallback(fn (int $id): array => [$versions[$id + 100]]);
		$copyMapper = $this->createStub(CopyMapper::class);
		$copyMapper->method('findAll')->willReturn(array_values($copies));
		$copyMapper->method('find')->willReturnCallback(fn (int $id): Copy => $copies[$id]);
		$copyMapper->method('findByVersion')->willReturnCallback(fn (int $id): array => [$copies[$id - 200]]);
		$fileMapper = $this->createStub(ArchiveFileMapper::class);
		$fileMapper->method('countPerCopy')->willReturn([]);
		$fileMapper->method('findByCopy')->willReturn([]);
		$repertoire = $this->createStub(RepertoireService::class);
		$repertoire->method('perWork')->willReturn([]);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-27');

		$roles = $this->roleStub($role);
		$modules = $this->modules(array_fill_keys(\OCA\Cantoria\Service\Modules::switchable(), false));
		$catalog = new CatalogService($this->createStub(IDBConnection::class), $workMapper, $versionMapper, $copyMapper, $fileMapper,
			$this->createStub(\OCA\Cantoria\Db\AuditEntryMapper::class), $this->createStub(\OCA\Cantoria\Service\AuditLog::class), $roles,
			$this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $this->createStub(ScoreService::class), $rights,
			$this->createStub(LicenseMapper::class), $this->createStub(\OCA\Cantoria\Db\ProposalMapper::class),
			$this->createStub(IUserManager::class), $this->createStub(TaskService::class), $this->createStub(\OCA\Cantoria\Service\LinkService::class),
			$repertoire, $this->createStub(\OCA\Cantoria\Service\PersonService::class), $this->createStub(\OCA\Cantoria\Service\MergeService::class),
			$modules, $this->createStub(\OCA\Cantoria\Db\ReviewPointMapper::class), $this->createStub(\OCA\Cantoria\Service\StateService::class));
		return [$catalog, new RightsPolicy($roles, $rights, $this->createStub(LicenseMapper::class)), $roles];
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	private function internal(CatalogService $catalog, Roles $roles): ApiController {
		return new ApiController($this->createStub(IRequest::class), 'account', $roles, $this->createStub(MetaService::class), $catalog,
			$this->createStub(ImportService::class), $this->createStub(ExportService::class), $this->createStub(FileSyncService::class),
			$this->createStub(\OCA\Cantoria\Db\AuditEntryMapper::class), $this->createStub(UserFiles::class), $this->createStub(ITimeFactory::class),
			$this->createStub(IDateTimeZone::class), $this->createStub(ScoreService::class), $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	private function agents(CatalogService $catalog, RightsPolicy $policy, Roles $roles): ApiV1CatalogController {
		return new ApiV1CatalogController($this->createStub(IRequest::class), 'account', $roles, $catalog, $policy, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	#[DataProvider('roles')]
	public function testBothInterfacesListTheSameCopies(string $role, bool $mayView): void {
		[$catalog, $policy, $roles] = $this->services($role);

		$internal = $this->internal($catalog, $roles)->entries()->getData();
		self::assertIsArray($internal);
		$internalIds = array_map(fn (mixed $row): mixed => is_array($row) ? $row['id'] : null, $internal);
		$v1 = $this->agents($catalog, $policy, $roles)->search()->getData();
		self::assertIsArray($v1);
		self::assertIsArray($v1['items']);
		$v1Ids = array_map(fn (mixed $row): mixed => is_array($row) ? $row['id'] : null, $v1['items']);

		$expected = $mayView ? [self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] : [self::ORDINARY];
		self::assertSame($expected, $internalIds, 'internal API');
		self::assertSame($expected, $v1Ids, 'API for agents');
	}

	#[DataProvider('roles')]
	public function testBothInterfacesRefuseTheSameCopies(string $role, bool $mayView): void {
		[$catalog, $policy, $roles] = $this->services($role);
		foreach ([self::ORDINARY, self::MARKED_VERSION, self::PRESERVATION_KIND] as $id) {
			$internal = $this->internal($catalog, $roles)->entry($id);
			try {
				$this->agents($catalog, $policy, $roles)->copy($id);
				$v1Refused = false;
			} catch (OCSForbiddenException) {
				$v1Refused = true;
			}
			$refused = !$mayView && $id !== self::ORDINARY;
			self::assertSame($refused ? Http::STATUS_FORBIDDEN : Http::STATUS_OK, $internal->getStatus(), 'internal API, copy ' . $id);
			self::assertSame($refused, $v1Refused, 'API for agents, copy ' . $id);
			self::assertSame($refused, $policy->viewDenial($catalog->copyWithContext($id)['version'], 'account', $catalog->copyWithContext($id)['copy']) !== null);
		}
	}
}
