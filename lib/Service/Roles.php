<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * App roles and their mapping to Nextcloud groups.
 *
 * Permissions are bound to groups, never to persons, so a change of office is
 * a change of group membership. Admins may read and change settings, but may
 * only write catalog data if they also hold a role.
 */
class Roles {
	/** Music librarian (catalog): works, versions, rights, import, file sync */
	public const LIBRARIAN = 'librarian';
	/** Music librarian (operations): copy status and counts */
	public const OPERATIONS = 'operations';
	/** Conductor: decides which version is valid for the choir */
	public const CONDUCTOR = 'conductor';
	/** Tools and transcription rounds: proposals only */
	public const CONTRIBUTOR = 'contributor';
	/** Board, auditors: read everything */
	public const READER = 'reader';

	public const ALL = [self::LIBRARIAN, self::OPERATIONS, self::CONDUCTOR, self::CONTRIBUTOR, self::READER];

	public const LABELS = [
		self::LIBRARIAN => 'Catalog (music librarian)',
		self::OPERATIONS => 'Operations (music librarian)',
		self::CONDUCTOR => 'Conductor',
		self::CONTRIBUTOR => 'Proposals (tools)',
		self::READER => 'Read only (board, auditors)',
	];

	/** @var array<string, list<string>> */
	private array $cache = [];

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function groups(string $role): array {
		$groups = $this->appConfig->getValueArray(Application::APP_ID, 'groups_' . $role, []);
		return array_values(array_filter($groups, 'is_string'));
	}

	/**
	 * @param list<string> $groups
	 */
	public function setGroups(string $role, array $groups): void {
		if (!in_array($role, self::ALL, true)) {
			throw new \InvalidArgumentException('Unknown role: ' . $role);
		}
		$this->appConfig->setValueArray(Application::APP_ID, 'groups_' . $role, array_values(array_unique($groups)));
		$this->cache = [];
	}

	/**
	 * @return list<string>
	 */
	public function rolesOf(string $uid): array {
		if (!isset($this->cache[$uid])) {
			$roles = [];
			foreach (self::ALL as $role) {
				foreach ($this->groups($role) as $group) {
					if ($this->groupManager->isInGroup($uid, $group)) {
						$roles[] = $role;
						break;
					}
				}
			}
			$this->cache[$uid] = $roles;
		}
		return $this->cache[$uid];
	}

	public function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}

	public function hasAccess(string $uid): bool {
		return $this->rolesOf($uid) !== [] || $this->isAdmin($uid);
	}

	public function requireAccess(string $uid): void {
		if (!$this->hasAccess($uid)) {
			throw new AccessDeniedException('You have no access to the music archive.');
		}
	}

	/**
	 * @param list<string> $allowed
	 */
	public function hasAnyRole(string $uid, array $allowed): bool {
		return array_intersect($this->rolesOf($uid), $allowed) !== [];
	}

	/**
	 * @param list<string> $allowed
	 */
	public function requireRole(string $uid, array $allowed): void {
		if (!$this->hasAnyRole($uid, $allowed)) {
			$labels = array_map(fn (string $role): string => self::LABELS[$role] ?? $role, $allowed);
			throw new AccessDeniedException('This requires one of these roles: %s', [implode(', ', $labels)]);
		}
	}

	public function requireAdmin(string $uid): void {
		if (!$this->isAdmin($uid)) {
			throw new AccessDeniedException('Only administrators may change this.');
		}
	}
}
