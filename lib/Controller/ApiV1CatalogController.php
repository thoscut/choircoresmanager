<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\ResponseDefinitions;
use OCA\Notenarchiv\Service\ApiV1\CatalogView;
use OCA\Notenarchiv\Service\ApiV1\Page;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TextReference;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Reading the catalog: search, one copy with its version and work, text
 * references (API v1, see docs/api-v1.md). The catalog belongs to the core;
 * these routes only need the interface for agents. Internal preservation
 * copies are left out for roles that may not view them.
 *
 * @psalm-import-type NotenarchivCatalogPage from ResponseDefinitions
 * @psalm-import-type NotenarchivCopy from ResponseDefinitions
 * @psalm-import-type NotenarchivTextReference from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
class ApiV1CatalogController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private CatalogService $catalog,
		private RightsPolicy $policy,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Search the catalog
	 *
	 * One row per copy (shelf mark), in the order of the shelf. All filters
	 * are optional and combine. Internal preservation copies are left out
	 * for roles that may not view them.
	 *
	 * @param string|null $q Text in shelf mark, title, composer, arranger or work number (case-insensitive, also parts of words)
	 * @param string|null $shelfmark Exact shelf mark, e.g. "5-1" (also finds "5-01")
	 * @param int|null $binder Number of the binder
	 * @param 'active'|'retired'|'missing'|'lent'|null $status Status of the copy
	 * @param 'public_domain'|'protected'|'open'|'none'|null $rights_status Rights status of the version; "none" for versions without one
	 * @param bool|null $confirmed Whether the archive management has confirmed the rights status
	 * @param 'current'|'dormant'|'earlier'|'none'|null $repertoire_class Repertoire class of the work
	 * @param bool|null $has_musicxml Whether the copy has a MusicXML file
	 * @param int<1, 100> $limit Rows per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, NotenarchivCatalogPage, array{}>
	 * @throws OCSBadRequestException A filter value, the limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The interface for agents is switched off
	 *
	 * 200: Page of the catalog returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/catalog')]
	public function search(?string $q = null, ?string $shelfmark = null, ?int $binder = null, ?string $status = null,
		?string $rights_status = null, ?bool $confirmed = null, ?string $repertoire_class = null, ?bool $has_musicxml = null,
		int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($q, $shelfmark, $binder, $status, $rights_status, $confirmed,
			$repertoire_class, $has_musicxml, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$rows = CatalogView::filter(CatalogView::rows($this->catalog->entries(), $this->policy->mayViewPreservationCopies($uid)), [
				'q' => $q, 'shelfmark' => $shelfmark, 'binder' => $binder, 'status' => $status, 'rights_status' => $rights_status,
				'confirmed' => $confirmed, 'repertoire_class' => $repertoire_class, 'has_musicxml' => $has_musicxml,
			]);
			return Page::of($rows, CatalogView::key(...), $cursor, $limit);
		}));
	}

	/**
	 * Read one copy
	 *
	 * The copy with its version and work: every catalog field (without
	 * account names), the rights status with confirmation, releases and the
	 * valid version, the score and the files, the other copies of the work,
	 * its tasks and open proposals. Parts of switched-off modules are empty.
	 *
	 * @param int $id ID of the copy (the "id" of a search row, not the shelf mark)
	 * @return DataResponse<Http::STATUS_OK, NotenarchivCopy, array{}>
	 * @throws OCSForbiddenException The account has no role, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or the interface for agents is switched off
	 *
	 * 200: Copy returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/copies/{id}', requirements: ['id' => '\d+'])]
	public function copy(int $id): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id): array {
			$this->roles->requireAccess($uid);
			$entry = $this->catalog->entry($id, $uid);
			$denial = $this->policy->viewDenial($entry['version'], $uid);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			return CatalogView::copy($entry, $uid, $this->l->t(...));
		}));
	}

	/**
	 * Text reference to a place in a copy
	 *
	 * A plain text such as "5-1 · Title · p. 2 · m. 17–24 · Tenor II" in the
	 * language of the account. It contains no notation and may be passed on
	 * freely. Every part of the place is optional.
	 *
	 * @param int $id ID of the copy
	 * @param int|null $page Page of the score
	 * @param string|null $measure_from First measure as printed, e.g. "17", "0" (upbeat) or "34X"
	 * @param string|null $measure_to Last measure as printed
	 * @param string|null $voice Voice, e.g. "Tenor II"
	 * @param int|null $verse Verse
	 * @param string|null $note Short note at the end (at most 500 characters)
	 * @return DataResponse<Http::STATUS_OK, NotenarchivTextReference, array{}>
	 * @throws OCSBadRequestException A part of the place is not valid
	 * @throws OCSForbiddenException The account has no role, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or the interface for agents is switched off
	 *
	 * 200: Text reference returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/copies/{id}/reference', requirements: ['id' => '\d+'])]
	public function reference(int $id, ?int $page = null, ?string $measure_from = null, ?string $measure_to = null,
		?string $voice = null, ?int $verse = null, ?string $note = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id, $page, $measure_from, $measure_to, $voice, $verse, $note): array {
			$this->roles->requireAccess($uid);
			$place = TextReference::place($page, $measure_from, $measure_to, $voice, $verse, $note);
			['copy' => $copy, 'version' => $version, 'work' => $work] = $this->catalog->copyWithContext($id);
			$denial = $this->policy->viewDenial($version, $uid);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			return [
				'text' => TextReference::text($copy->getShelfmark(), $work->getTitle(), $place, $this->l),
				'shelfmark' => $copy->getShelfmark(),
				'title' => $work->getTitle(),
			];
		}));
	}
}
