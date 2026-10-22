<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\ResponseDefinitions;
use OCA\Notenarchiv\Service\ApiV1\ConcertView;
use OCA\Notenarchiv\Service\ApiV1\Page;
use OCA\Notenarchiv\Service\EventService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\Roles;
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
 * Concerts and song lists with their programmes, read only (API v1, see
 * docs/api-v1.md). Planning stays with the conductor and the catalog
 * librarian in the app.
 *
 * @psalm-import-type NotenarchivConcertPage from ResponseDefinitions
 * @psalm-import-type NotenarchivConcert from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
#[RequiresModule('concerts')]
class ApiV1ConcertController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private EventService $events,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List concerts and song lists
	 *
	 * By date, events without a date last. "pieces" counts the lines of the
	 * programme.
	 *
	 * @param 'concert'|'song_list'|null $kind Only concerts or only song lists
	 * @param 'draft'|'fixed'|'sung'|'cancelled'|null $status Only events in this state
	 * @param string|null $from Only events on or after this day (YYYY-MM-DD)
	 * @param string|null $to Only events on or before this day (YYYY-MM-DD)
	 * @param int<1, 100> $limit Events per page
	 * @param string|null $cursor The "next_cursor" of the page before; omit it for the first page
	 * @return DataResponse<Http::STATUS_OK, NotenarchivConcertPage, array{}>
	 * @throws OCSBadRequestException A filter value, the limit or the cursor is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException Concerts or the interface for agents are switched off
	 *
	 * 200: Page of events returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/concerts')]
	public function concerts(?string $kind = null, ?string $status = null, ?string $from = null, ?string $to = null,
		int $limit = 25, ?string $cursor = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($kind, $status, $from, $to, $limit, $cursor): array {
			$this->roles->requireAccess($uid);
			$rows = ConcertView::filter(ConcertView::rows($this->events->list(null)), $kind, $status, $from, $to);
			return Page::of($rows, ConcertView::key(...), $cursor, $limit);
		}));
	}

	/**
	 * Read a concert or song list with its programme
	 *
	 * The programme lines in their order with copy, work, duration and the
	 * rights warnings (translated), and the total duration of the lines that
	 * are not struck.
	 *
	 * @param int $id ID of the concert or song list
	 * @return DataResponse<Http::STATUS_OK, NotenarchivConcert, array{}>
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The event does not exist, or concerts or the interface for agents are switched off
	 *
	 * 200: Event returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/concerts/{id}', requirements: ['id' => '\d+'])]
	public function concert(int $id): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id): array {
			$this->roles->requireAccess($uid);
			return ConcertView::concert($this->events->detail($id, $uid), $this->l->t(...));
		}));
	}
}
