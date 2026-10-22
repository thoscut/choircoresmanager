<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ArchiveAccess;
use OCA\Notenarchiv\Service\FullBackupService;
use OCA\Notenarchiv\Service\Input;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\RepertoireService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Settings of the app (page Administration → Music archive, see
 * docs/api.md): archive folder, roles and groups (with a search for groups),
 * and the check who can open the archive folder.
 *
 * For admins and for the groups the administration has given the page under
 * Administration privileges: Nextcloud checks #[AuthorizedAdminSetting]
 * before the app, Roles::requireSettingsAccess() checks again. The modules
 * (ModuleController) and the repertoire thresholds (EventController) follow
 * the same rule. Restoring a backup does not (WorkflowController::restore).
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends Controller {
	use RunsActions;

	/** Groups found at most by one search */
	private const MAX_GROUPS = 25;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private AppSettings $settings,
		private ArchiveAccess $access,
		private Modules $modules,
		private RepertoireService $repertoire,
		private FullBackupService $backup,
		private IGroupManager $groupManager,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings')]
	public function index(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireSettingsAccess($uid);
			return $this->data($uid);
		});
	}

	/**
	 * @param array<array-key, mixed>|null $roles role => list of group ids (request data)
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function update(?array $roles = null, ?int $archiveFolderId = null): JSONResponse {
		return $this->run(function (string $uid) use ($roles, $archiveFolderId) {
			$this->roles->requireSettingsAccess($uid);
			$roles ??= [];
			foreach (array_keys($roles) as $role) {
				$role = (string)$role;
				if (!in_array($role, Roles::GROUP_ROLES, true)) {
					throw new InvalidInputException('Unknown role: %s', [$role]);
				}
				$this->roles->setGroups($role, Input::stringList($roles[$role], 'Groups'));
			}
			if ($archiveFolderId !== null) {
				$this->settings->setArchiveFolder($uid, $archiveFolderId);
			}
			return $this->data($uid);
		});
	}

	/**
	 * Groups to choose for a role: id and display name, at most 25. Nextcloud's
	 * own list of groups with display names needs further administration
	 * privileges (sharing or accounts), which the groups given the settings
	 * page do not have.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/groups')]
	public function groups(string $search = ''): JSONResponse {
		return $this->run(function (string $uid) use ($search) {
			$this->roles->requireSettingsAccess($uid);
			$groups = [];
			foreach ($this->groupManager->search(mb_substr(trim($search), 0, 100), self::MAX_GROUPS) as $group) {
				$groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
			}
			return $groups;
		});
	}

	/**
	 * Who of the role groups (and of the admins without a role) can open the
	 * archive folder, or with $folderId a folder just chosen and not saved
	 * yet. Only reads; each check sets up the files of an account, hence the
	 * rate limit.
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: 20, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings/access')]
	public function access(?int $folderId = null): JSONResponse {
		return $this->run(function (string $uid) use ($folderId) {
			$this->roles->requireSettingsAccess($uid);
			return $this->access->report($uid, ArchiveAccess::MAX_ACCOUNTS, $folderId);
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function data(string $uid): array {
		$roles = [];
		$groupNames = [];
		$groupsMissing = [];
		foreach (Roles::GROUP_ROLES as $role) {
			$roles[$role] = $this->roles->groups($role);
			foreach ($roles[$role] as $groupId) {
				if (array_key_exists($groupId, $groupNames)) {
					continue;
				}
				$group = $this->groupManager->get($groupId);
				// A group deleted after it was given the role keeps its id.
				$groupNames[$groupId] = $group?->getDisplayName() ?? $groupId;
				if ($group === null) {
					$groupsMissing[] = $groupId;
				}
			}
		}
		$admin = $this->roles->isAdmin($uid);
		$counts = $this->backup->counts();
		return [
			'roles' => $roles,
			// Display names of the groups above; an object also when empty
			'groupNames' => (object)$groupNames,
			'groupsMissing' => $groupsMissing,
			'roleLabels' => Roles::LABELS,
			'archive' => $this->settings->archiveInfo(),
			// The thresholds belong to the module "repertoire".
			'repertoire' => $this->modules->isEnabled('repertoire') ? $this->repertoire->thresholds() : null,
			// Sections of modules that start off (milestone 0.7)
			'folderBook' => $this->modules->isEnabled('folder_book'),
			'printMirror' => $this->modules->isEnabled('print_mirror'),
			// For the setup steps: imported copies and files found by the file sync
			'catalog' => ['copies' => $counts['notarch_copies'] ?? 0, 'files' => $counts['notarch_files'] ?? 0],
			// Restoring a backup stays with admins (and the catalog role in the app).
			'admin' => $admin,
			'catalogEmpty' => $admin ? array_sum($counts) === 0 : null,
		];
	}
}
