<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\PrintMirrorService;
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
 * The mirror folder of the print station (milestone 0.7, module
 * "print_mirror", see docs/api.md): status, preview and sync for the
 * catalog librarian and administrators (checked by the service); the
 * target folder is an instance setting for administrators.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
#[RequiresModule(Modules::PRINT_MIRROR)]
class PrintMirrorController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private PrintMirrorService $mirror,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/print-mirror')]
	public function status(): JSONResponse {
		return $this->run(fn (string $uid) => $this->statusData($uid));
	}

	/**
	 * What a sync would do now, without writing anything.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/print-mirror/preview')]
	public function preview(): JSONResponse {
		return $this->run(fn (string $uid) => $this->translated($this->mirror->sync($uid, true)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/print-mirror/sync')]
	public function sync(): JSONResponse {
		return $this->run(fn (string $uid) => $this->translated($this->mirror->sync($uid, false)));
	}

	/**
	 * The same as the status, for the settings (administrators).
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/print-mirror')]
	public function settings(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->statusData($uid);
		});
	}

	/**
	 * Checks a folder as target folder without saving it.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/print-mirror/check')]
	public function check(int $folderId = 0): JSONResponse {
		return $this->run(fn (string $uid) => ['folder' => $this->mirror->checkTargetFolder($uid, $folderId)]);
	}

	/**
	 * Saves the target folder; 0 removes it.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings/print-mirror')]
	public function updateSettings(int $folderId = 0): JSONResponse {
		return $this->run(fn (string $uid) => ['folder' => $this->folderOrNull($this->mirror->setTargetFolder($uid, $folderId))]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function statusData(string $uid): array {
		$status = $this->mirror->status($uid);
		$status['report'] = $status['report'] === null ? null : $this->translated($status['report']);
		return $status;
	}

	/**
	 * @param array{id: int, path: string, user: string} $folder
	 * @return array{id: int, path: string, user: string}|null
	 */
	private function folderOrNull(array $folder): ?array {
		return $folder['id'] === 0 ? null : $folder;
	}

	/**
	 * The report (of this sync, or the stored one of the last) with the
	 * reasons of skipped files translated.
	 *
	 * @param array<array-key, mixed> $report
	 * @return array<array-key, mixed>
	 */
	private function translated(array $report): array {
		$report['skipped'] = isset($report['skipped']) && is_array($report['skipped'])
			? array_values(array_filter(array_map(fn (mixed $entry): ?array => $this->translatedEntry($entry), $report['skipped'])))
			: [];
		return $report;
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function translatedEntry(mixed $entry): ?array {
		if (!is_array($entry)) {
			return null;
		}
		$reason = isset($entry['reason']) && is_string($entry['reason']) ? $entry['reason'] : '';
		return ['reasonText' => $this->reason($reason)] + $entry;
	}

	private function reason(string $key): string {
		return match ($key) {
			PrintMirrorService::FOREIGN => $this->l->t('A file of this name is in the target folder, but the app did not write it (or it was replaced). It stays as it is; rename or remove it there if the scan should be copied.'),
			PrintMirrorService::CHANGED => $this->l->t('The file was changed in the target folder after the app wrote it. It stays as it is; remove it there if the app should look after it again.'),
			PrintMirrorService::NOT_ACCESSIBLE => $this->l->t('The account of the target folder cannot read this scan. Give it access to the archive folder.'),
			PrintMirrorService::NOT_PDF => $this->l->t('The file is not a PDF document. Check the scan in the archive folder.'),
			PrintMirrorService::INVALID_NAME => $this->l->t('Nextcloud does not allow this file name. Change the title in the catalog.'),
			PrintMirrorService::PRESERVATION => $this->l->t('Internal preservation copy: never copied to the print station.'),
			PrintMirrorService::WRITE_FAILED => $this->l->t('The file could not be written or removed (no permission, or it is locked). The next sync tries again.'),
			default => $key,
		};
	}
}
