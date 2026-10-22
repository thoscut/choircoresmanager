<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Service\ChecksumService;
use OCA\Notenarchiv\Service\CopyAssistantService;
use OCA\Notenarchiv\Service\InventoryService;
use OCA\Notenarchiv\Service\MergeService;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Maintaining the stock (milestone 0.5, see docs/api.md): new accessions with
 * shelf labels, manual file links, merges that can be undone, inventory and
 * checksums. Every service checks the required role itself.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class MaintenanceController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private CopyAssistantService $assistant,
		private MergeService $merges,
		private InventoryService $inventory,
		private ChecksumService $checksums,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresModule('accession')]
	#[FrontpageRoute(verb: 'GET', url: '/api/accession/next')]
	public function nextShelfmark(?int $binder = null): JSONResponse {
		return $this->run(function (string $uid) use ($binder) {
			$this->roles->requireAccess($uid);
			return $this->assistant->next($binder);
		});
	}

	/**
	 * @param list<mixed> $binders
	 */
	#[NoAdminRequired]
	#[RequiresModule('accession')]
	#[FrontpageRoute(verb: 'PUT', url: '/api/accession/open-binders')]
	public function setOpenBinders(array $binders = []): JSONResponse {
		return $this->run(fn (string $uid) => ['openBinders' => $this->assistant->setOpenBinders($binders, $uid)]);
	}

	/**
	 * @param array<string, mixed> $copy
	 */
	#[NoAdminRequired]
	#[RequiresModule('accession')]
	#[FrontpageRoute(verb: 'POST', url: '/api/accession')]
	public function createAccession(array $copy = [], ?int $fileId = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->assistant->create($copy, $fileId, $uid));
	}

	/**
	 * @param string $ids comma-separated copy ids
	 */
	#[NoAdminRequired]
	#[RequiresModule('accession')]
	#[FrontpageRoute(verb: 'GET', url: '/api/labels')]
	public function labels(string $ids = ''): JSONResponse {
		return $this->run(function (string $uid) use ($ids) {
			$this->roles->requireAccess($uid);
			$list = array_values(array_filter(array_map('intval', explode(',', $ids)), fn (int $id): bool => $id > 0));
			return $this->assistant->labels(array_slice($list, 0, 500));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/copies/{id}/files', requirements: ['id' => '\d+'])]
	public function linkFile(int $id, int $fileId = 0): JSONResponse {
		return $this->run(fn (string $uid) => $this->assistant->linkFile($id, $fileId, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/files/{id}/link', requirements: ['id' => '\d+'])]
	public function unlinkFile(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->assistant->unlinkFile($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('merge')]
	#[FrontpageRoute(verb: 'GET', url: '/api/merges')]
	public function merges(int $all = 0): JSONResponse {
		return $this->run(function (string $uid) use ($all) {
			$this->roles->requireAccess($uid);
			return $this->merges->list($all === 1);
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('merge')]
	#[FrontpageRoute(verb: 'GET', url: '/api/merges/candidates')]
	public function mergeCandidates(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->merges->candidates();
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('merge')]
	#[FrontpageRoute(verb: 'POST', url: '/api/merges/works')]
	public function mergeWorks(int $sourceWorkId = 0, int $targetWorkId = 0, ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->merges->mergeWorks($sourceWorkId, $targetWorkId, $uid, $reason));
	}

	#[NoAdminRequired]
	#[RequiresModule('merge')]
	#[FrontpageRoute(verb: 'POST', url: '/api/merges/copies')]
	public function moveCopy(int $copyId = 0, int $targetVersionId = 0, ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->merges->moveCopy($copyId, $targetVersionId, $uid, $reason));
	}

	#[NoAdminRequired]
	#[RequiresModule('merge')]
	#[FrontpageRoute(verb: 'POST', url: '/api/merges/{id}/undo', requirements: ['id' => '\d+'])]
	public function undoMerge(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->merges->undo($id, $uid, $reason);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('inventory')]
	#[FrontpageRoute(verb: 'GET', url: '/api/inventory')]
	public function inventory(?int $binder = null, int $all = 0): JSONResponse {
		return $this->run(function (string $uid) use ($binder, $all) {
			$this->roles->requireAccess($uid);
			return $this->inventory->list($binder, $all === 1);
		});
	}

	/**
	 * Gaps in the shelf marks of every binder (information only; numbers are
	 * never reused).
	 */
	#[NoAdminRequired]
	#[RequiresModule('inventory')]
	#[FrontpageRoute(verb: 'GET', url: '/api/inventory/gaps')]
	public function shelfmarkGaps(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return ['binders' => $this->inventory->gaps()];
		});
	}

	/**
	 * @param list<mixed> $counts
	 */
	#[NoAdminRequired]
	#[RequiresModule('inventory')]
	#[FrontpageRoute(verb: 'POST', url: '/api/inventory/count')]
	public function recordCounts(array $counts = [], ?string $date = null): JSONResponse {
		return $this->run(fn (string $uid) => ['recorded' => $this->inventory->recordCounts($counts, $date, $uid)]);
	}

	#[NoAdminRequired]
	#[RequiresModule('checksums')]
	#[FrontpageRoute(verb: 'GET', url: '/api/checksums')]
	public function checksums(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->checksums->summary();
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('checksums')]
	#[FrontpageRoute(verb: 'POST', url: '/api/files/{id}/checksum-accept', requirements: ['id' => '\d+'])]
	public function acceptChecksum(int $id, ?string $reason = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $reason) {
			$this->checksums->accept($id, $uid, $reason);
			return ['ok' => true];
		});
	}
}
