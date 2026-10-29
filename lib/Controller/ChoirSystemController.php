<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\ChoirSystemCompareService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\UserFiles;
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
 * The comparison of an export of the choir system with the list of the
 * singers' portal (milestone 0.12, see docs/api.md): a dry run first, then
 * the differences as proposals in the inbox. Catalog librarian only.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule('portal')]
#[RequiresModule('proposals')]
class ChoirSystemController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private ChoirSystemCompareService $compare,
		private UserFiles $userFiles,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param int $fileId Nextcloud file id of the export (CSV or XLSX)
	 * @param bool $dryRun only compare, propose nothing
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/lists/portal/compare')]
	public function compare(int $fileId, bool $dryRun = true): JSONResponse {
		return $this->run(function (string $uid) use ($fileId, $dryRun) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			$file = $this->userFiles->read($uid, $fileId, UserFiles::MAX_IMPORT_BYTES,
				new InvalidInputException('The selected file is not accessible.'));
			return $this->compare->compare($file['content'], $file['name'], $uid, $dryRun);
		});
	}
}
