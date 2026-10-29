<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
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
 * The action "Open in Cantoria" of the Files app (see docs/api.md):
 * which copy a file of Nextcloud belongs to.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::FILES_INTEGRATION)]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class FilesIntegrationController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private FilesIntegrationService $filesIntegration,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Copy, shelf mark and role of a file, by its Nextcloud file id; 404 when
	 * the account cannot see the file or it belongs to no copy. Read-only,
	 * but called by a script, so the CSRF check stays.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/nextcloud-files/{fileId}/copy', requirements: ['fileId' => '\d+'])]
	public function copyOfFile(int $fileId): JSONResponse {
		return $this->run(function (string $uid) use ($fileId) {
			$this->roles->requireAccess($uid);
			return $this->filesIntegration->copyOf($fileId, $uid);
		});
	}
}
