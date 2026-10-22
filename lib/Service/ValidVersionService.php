<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * "Valid for the choir": the version the conductor has chosen, at most one per
 * work. Only the conductor role sets or clears it; no import or automation
 * does. Its history is the audit log.
 *
 * Choosing a version says nothing about its rights; the releases stay
 * separate.
 */
class ValidVersionService {
	public function __construct(
		private IDBConnection $db,
		private WorkVersionMapper $versions,
		private AuditLog $audit,
		private Roles $roles,
		private RightsService $rights,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	public function set(int $versionId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::CONDUCTOR]);
		$version = $this->load($versionId);
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach ($this->versions->findByWork($version->getWorkId()) as $other) {
				if ($other->getId() !== $version->getId() && $other->getValidSince() !== null) {
					$this->apply($other, $uid, $operation, 'valid_cleared', ['valid_since' => null, 'valid_set_by' => null, 'valid_reason' => null], $reason);
				}
			}
			$this->apply($version, $uid, $operation, 'valid_set', [
				'valid_since' => $this->rights->today($uid),
				'valid_set_by' => $this->userManager->getDisplayName($uid) ?? $uid,
				'valid_reason' => $reason === null || trim($reason) === '' ? null : $reason,
			], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function clear(int $versionId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::CONDUCTOR]);
		$version = $this->load($versionId);
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$this->apply($version, $uid, $operation, 'valid_cleared', ['valid_since' => null, 'valid_set_by' => null, 'valid_reason' => null], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param array<string, string|int|bool|null> $values column => new value
	 */
	private function apply(WorkVersion $version, string $uid, string $operation, string $action, array $values, ?string $reason): void {
		$changed = false;
		foreach ($values as $field => $value) {
			$old = $version->getValue($field);
			if ($old === $value) {
				continue;
			}
			$version->setValue($field, $value);
			$this->audit->record($operation, $uid, Fields::VERSION, $version->getId(), $action, $field, $old, $value, $reason);
			$changed = true;
		}
		if ($changed) {
			$version->setModified($this->time->getTime());
			$this->versions->update($version);
		}
	}

	private function load(int $versionId): WorkVersion {
		try {
			return $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
	}
}
