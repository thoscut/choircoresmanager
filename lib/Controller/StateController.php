<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\StateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Revisions of a version (module "version_states", milestone 0.8, see
 * docs/api.md): the list for every archive role, setting the current
 * revision for the catalog librarian. Comparing two revisions happens in the
 * browser with the file contents (`GET /api/files/{id}/content`, which checks
 * the rights itself). The service checks the roles.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class StateController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private StateService $states,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresModule(Modules::VERSION_STATES)]
	#[FrontpageRoute(verb: 'GET', url: '/api/versions/{id}/states', requirements: ['id' => '\d+'])]
	public function list(int $id): JSONResponse {
		return $this->run(fn (string $uid) => $this->states->forVersion($id, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule(Modules::VERSION_STATES)]
	#[UserRateLimit(limit: 60, period: 600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/states/{id}/current', requirements: ['id' => '\d+'])]
	public function setCurrent(int $id, ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->states->setCurrent($id, $uid, $reason));
	}
}
