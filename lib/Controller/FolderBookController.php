<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Service\FolderBookService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The folder book (milestone 0.7, module "folder_book", see docs/api.md):
 * read by the catalog librarian, operations, the conductor and readers,
 * maintained by the catalog librarian and operations (checked by the
 * service); the retention period is an instance setting for administrators.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule(Modules::FOLDER_BOOK)]
class FolderBookController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private FolderBookService $folderBook,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/folder-book')]
	public function overview(): JSONResponse {
		return $this->run(fn (string $uid) => $this->folderBook->overview($uid));
	}

	/**
	 * @param array<array-key, mixed> $folder label, voice, contents, wear, missing, note
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/folder-book/folders')]
	public function createFolder(array $folder = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->folderBook->createFolder($folder, $uid));
	}

	/**
	 * @param array<array-key, mixed> $folder the fields to change, also status and statusReason
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/folder-book/folders/{id}', requirements: ['id' => '\d+'])]
	public function updateFolder(int $id, array $folder = []): JSONResponse {
		return $this->run(function (string $uid) use ($id, $folder) {
			$this->folderBook->updateFolder($id, $folder, $uid);
			return ['ok' => true];
		});
	}

	/**
	 * @param array<array-key, mixed> $loan holderUid or holderName, issuedOn, note
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/folder-book/folders/{id}/issue', requirements: ['id' => '\d+'])]
	public function issue(int $id, array $loan = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->folderBook->issue($id, $loan, $uid));
	}

	/**
	 * @param array<array-key, mixed> $loan returnedOn, wear, missing, note
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/folder-book/loans/{id}/return', requirements: ['id' => '\d+'])]
	public function takeBack(int $id, array $loan = []): JSONResponse {
		return $this->run(function (string $uid) use ($id, $loan) {
			$this->folderBook->takeBack($id, $loan, $uid);
			return ['ok' => true];
		});
	}

	/**
	 * The retention period of the personal data (administrators).
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/folder-book')]
	public function settings(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->settingsData();
		});
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings/folder-book')]
	public function updateSettings(int $retentionMonths = 0): JSONResponse {
		return $this->run(function (string $uid) use ($retentionMonths) {
			$this->roles->requireSettingsAccess($uid);
			$this->folderBook->setRetentionMonths($retentionMonths, $uid);
			return $this->settingsData();
		});
	}

	/**
	 * @return array{retentionMonths: int, min: int, max: int}
	 */
	private function settingsData(): array {
		return ['retentionMonths' => $this->folderBook->retentionMonths(), 'min' => FolderBookService::RETENTION_MIN,
			'max' => FolderBookService::RETENTION_MAX];
	}
}
