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
use OCA\Notenarchiv\Service\ApiV1\StatisticsView;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\StatisticsService;
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
 * Statistics, read only (API v1, see docs/api-v1.md): figures without
 * personal data of choir members and without account names.
 *
 * @psalm-import-type NotenarchivStatistics from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
#[RequiresModule('statistics')]
class ApiV1StatisticsController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private StatisticsService $statistics,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Statistics of the archive
	 *
	 * Stock, rights and releases, licenses, repertoire classes, concerts and
	 * pieces per year, the most performed works and composers, open tasks
	 * and proposals. Rights, stock and open work are those of today;
	 * concerts follow the period (default: the ten years up to now).
	 *
	 * @param int|null $from First year of the period
	 * @param int|null $to Last year of the period
	 * @return DataResponse<Http::STATUS_OK, NotenarchivStatistics, array{}>
	 * @throws OCSBadRequestException The period is not valid
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException Statistics or the interface for agents are switched off
	 *
	 * 200: Statistics returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/statistics')]
	public function statistics(?int $from = null, ?int $to = null): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($from, $to): array {
			$this->roles->requireAccess($uid);
			return StatisticsView::of($this->statistics->overview($from, $to, $uid));
		}));
	}
}
