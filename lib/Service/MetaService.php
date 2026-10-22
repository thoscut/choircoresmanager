<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

/**
 * What the UI needs on start: own roles, field registry, archive folder,
 * the active modules and the personal view of the account.
 */
class MetaService {
	public function __construct(
		private Roles $roles,
		private AppSettings $settings,
		private Modules $modules,
		private PersonalView $personalView,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function forUser(string $uid): array {
		return [
			'uid' => $uid,
			'admin' => $this->roles->isAdmin($uid),
			'roles' => $this->roles->rolesOf($uid),
			'roleLabels' => Roles::LABELS,
			'fields' => Fields::all(),
			'licenseUses' => Fields::LICENSE_USES,
			'personFields' => Fields::person(),
			'contributionRoles' => Fields::CONTRIBUTION_ROLES,
			'archive' => $this->settings->archiveInfo(),
			'modules' => $this->modules->states(),
			'personalView' => $this->personalView->get($uid),
		];
	}
}
