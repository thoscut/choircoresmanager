<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\ResponseDefinitions;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * What the interface for agents offers the calling account: version, roles,
 * active modules and permissions (API v1, see docs/api-v1.md).
 *
 * @psalm-import-type CantoriaApiStatus from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
class ApiV1StatusController extends OCSController {
	use RunsOcsActions;

	/** The version of this API; changes only with a new path (api/v2) */
	public const API_VERSION = 1;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private Modules $modules,
		private RightsPolicy $policy,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Status of the interface and the modules for this account
	 *
	 * The version of the API, the roles of the account, which modules are
	 * active, what the account may write (tasks, proposals, reports of
	 * transcriptions), the fields that can be proposed and the choices of a
	 * new task. Call it first: a route of a switched-off module answers 404.
	 *
	 * @return DataResponse<Http::STATUS_OK, CantoriaApiStatus, array{}>
	 * @throws OCSForbiddenException The account has no role in the music archive
	 * @throws OCSNotFoundException The interface for agents is switched off
	 *
	 * 200: Status returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/modules')]
	public function modules(): DataResponse {
		return new DataResponse($this->ocs(function (string $uid): array {
			$this->roles->requireAccess($uid);
			$roles = $this->roles->rolesOf($uid);
			return [
				'api_version' => self::API_VERSION,
				'roles' => $roles,
				'modules' => $this->modules->states(),
				'permissions' => [
					'create_tasks' => $this->roles->hasAnyRole($uid, TaskService::CREATORS),
					'answer_tasks_for' => array_values(array_intersect(TaskService::ADDRESSEES, $roles)),
					'propose' => $this->roles->hasAnyRole($uid, ProposalService::PROPOSERS),
					'view_preservation_copies' => $this->policy->mayViewPreservationCopies($uid),
					'submit_transcription_reports' => $this->roles->hasAnyRole($uid, ReviewService::INGESTERS),
				],
				'proposable_fields' => ProposalService::proposableFields(),
				'task_options' => [
					'kinds' => Task::KINDS,
					'addressees' => TaskService::ADDRESSEES,
					'categories' => TaskService::CATEGORIES,
				],
			];
		}));
	}
}
