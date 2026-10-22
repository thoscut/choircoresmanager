<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\AuditEntry;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Writes the audit log: who, when, what, old, new, reason. Changes that belong
 * together share one operation id.
 */
class AuditLog {
	public function __construct(
		private AuditEntryMapper $mapper,
		private IUserManager $userManager,
		private Roles $roles,
		private ITimeFactory $time,
	) {
	}

	public function newOperation(): string {
		return bin2hex(random_bytes(16));
	}

	public function record(
		string $operation,
		?string $uid,
		string $objectType,
		?int $objectId,
		string $action,
		?string $field = null,
		mixed $oldValue = null,
		mixed $newValue = null,
		?string $reason = null,
	): void {
		$entry = new AuditEntry();
		$entry->setOperation($operation);
		$entry->setTime($this->time->getTime());
		$entry->setUserId($uid);
		$entry->setUserName($uid === null ? null : $this->userManager->getDisplayName($uid));
		$roles = $uid === null ? [] : $this->roles->rolesOf($uid);
		if ($uid !== null && $roles === [] && $this->roles->isMember($uid)) {
			// A choir member (practice audio of a shared excerpt)
			$roles = [Roles::MEMBER];
		}
		$entry->setRoles($uid === null ? null : implode(',', $roles));
		$entry->setObjectType($objectType);
		$entry->setObjectId($objectId);
		$entry->setAction($action);
		$entry->setField($field);
		$entry->setOldValue(self::asText($oldValue));
		$entry->setNewValue(self::asText($newValue));
		$entry->setReason($reason === '' ? null : $reason);
		$this->mapper->insert($entry);
	}

	private static function asText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_scalar($value)) {
			return (string)$value;
		}
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
