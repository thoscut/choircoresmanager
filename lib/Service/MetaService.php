<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

/**
 * What the UI needs on start: own roles, field registry, archive folder.
 */
class MetaService {
	public function __construct(
		private Roles $roles,
		private AppSettings $settings,
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
		];
	}
}
