<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Service\AnnualSnapshotService;
use OCA\Notenarchiv\Service\Modules;
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
 * Annual snapshots of the archive (module "annual_snapshot", milestone 0.8,
 * see docs/api.md): the list with what a new snapshot writes where, making
 * one (catalog librarian), comparing two, and the setting for the turn of
 * the year (catalog librarian). The service checks the roles.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class AnnualSnapshotController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private AnnualSnapshotService $snapshots,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresModule(Modules::ANNUAL_SNAPSHOT)]
	#[FrontpageRoute(verb: 'GET', url: '/api/annual-snapshots')]
	public function overview(): JSONResponse {
		return $this->run(function (string $uid) {
			$overview = $this->snapshots->overview($uid);
			$error = $overview['lastError'];
			$overview['lastError'] = $error === null ? null : $this->translate($error['message'], $error['parameters']);
			return $overview;
		});
	}

	/**
	 * Reads every archive file for the checksums: limited per account.
	 */
	#[NoAdminRequired]
	#[RequiresModule(Modules::ANNUAL_SNAPSHOT)]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/annual-snapshots')]
	public function create(): JSONResponse {
		return $this->run(fn (string $uid) => $this->snapshots->createManually($uid));
	}

	#[NoAdminRequired]
	#[RequiresModule(Modules::ANNUAL_SNAPSHOT)]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/annual-snapshots/compare')]
	public function compare(string $older = '', string $newer = ''): JSONResponse {
		return $this->run(fn (string $uid) => $this->snapshots->compare($uid, $older, $newer));
	}

	#[NoAdminRequired]
	#[RequiresModule(Modules::ANNUAL_SNAPSHOT)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/annual-snapshots/settings')]
	public function settings(bool $auto = true): JSONResponse {
		return $this->run(fn (string $uid) => ['auto' => $this->snapshots->setAuto($auto, $uid)]);
	}
}
