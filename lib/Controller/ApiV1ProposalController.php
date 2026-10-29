<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\ResponseDefinitions;
use OCA\Cantoria\Service\ApiV1\Page;
use OCA\Cantoria\Service\ApiV1\TaskView;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\Roles;
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
 * Proposals: create them and read the own ones (API v1, see
 * docs/api-v1.md). A proposal changes nothing; the catalog librarian accepts
 * or rejects it in the app (guideline 6).
 *
 * @psalm-import-type CantoriaProposal from ResponseDefinitions
 * @psalm-import-type CantoriaProposalPage from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
#[RequiresModule('proposals')]
class ApiV1ProposalController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private ProposalService $proposals,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List the own proposals
	 *
	 * The proposals this account made, oldest first, with their state: open,
	 * accepted or rejected (with the reason of the decision).
	 *
	 * @param 'open'|'accepted'|'rejected'|null $status Only proposals in this state
	 * @param int<1, 100> $limit Proposals per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, CantoriaProposalPage, array{}>
	 * @throws OCSBadRequestException The state, the limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The proposals inbox or the interface for agents is switched off
	 *
	 * 200: Page of proposals returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/proposals')]
	public function proposals(?string $status = null, int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($status, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$page = Page::of($this->proposals->own($uid, $status), fn (Proposal $p): array => [$p->getId()], $cursor, $limit);
			return ['items' => array_map(fn (Proposal $p): array => TaskView::proposal($p, $uid), $page['items'])] + $page;
		}));
	}

	/**
	 * Propose changes of catalog fields
	 *
	 * One proposal per field, all or none. Only fields the catalog librarian
	 * may write can be proposed (GET /api/v1/modules lists them); rights
	 * confirmations, licenses and the valid version never. Nothing changes
	 * until the catalog librarian accepts a proposal in the app. Allowed for
	 * the roles contributor and librarian.
	 *
	 * @param string $level Level of the catalog the fields belong to: "work", "version" or "copy"
	 * @param int $object_id ID of the work, version or copy (see "work_id" and "version_id" of a copy)
	 * @param array<string, string|int|float|bool|null> $fields Field => proposed value; null proposes to empty the field
	 * @param string|null $reason Why the change is proposed
	 * @param string|null $source Where it comes from, e.g. the name of a tool or a transcription round (at most 255 characters)
	 * @return DataResponse<Http::STATUS_CREATED, list<CantoriaProposal>, array{}>
	 * @throws OCSBadRequestException The level is unknown, there are no fields, or a field cannot be proposed
	 * @throws OCSForbiddenException The roles of the account may not propose changes
	 * @throws OCSNotFoundException The work, version or copy does not exist, or the proposals inbox or the interface for agents is switched off
	 *
	 * 201: Proposals created
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/proposals')]
	public function propose(string $level = '', int $object_id = 0, array $fields = [], ?string $reason = null, ?string $source = null): DataResponse {
		return new DataResponse($this->ocs(fn (string $uid): array => array_map(fn (Proposal $p): array => TaskView::proposal($p, $uid),
			$this->proposals->propose($level, $object_id, $fields, $uid, $reason, $source))), Http::STATUS_CREATED);
	}
}
