<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Rights decisions on a version and the releases derived from them.
 *
 * The recommendation (initial assessment, imported status) and the
 * confirmation are kept apart: only the catalog librarian confirms, always
 * with reason and evidence. Changing the rights status later removes the
 * confirmation, because it no longer matches.
 *
 * Releases are separate and derived, never set by hand:
 * - singers' portal and print station: confirmed public domain, or a valid
 *   license covering that use; never for preservation copies
 * - practice audio (playback): public domain or a license for practice audio
 */
class RightsService {
	public function __construct(
		private IDBConnection $db,
		private WorkVersionMapper $versions,
		private LicenseMapper $licenses,
		private AuditLog $audit,
		private Roles $roles,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	public function confirm(int $versionId, string $uid, string $status, string $reason, string $evidence): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if (!in_array($status, ['public_domain', 'protected'], true)) {
			throw new InvalidInputException('Only "public domain" or "protected" can be confirmed. Use "set open" when in doubt.');
		}
		if (trim($reason) === '' || trim($evidence) === '') {
			throw new InvalidInputException('A confirmation needs both a reason and evidence.');
		}
		$version = $this->load($versionId);
		$this->change($version, $uid, 'confirmed', [
			'rights_status' => $status,
			'confirmed_by' => $uid,
			'confirmed_by_name' => $this->userManager->getDisplayName($uid) ?? $uid,
			'confirmed_at' => $this->today($uid),
			'confirmed_reason' => $reason,
			'confirmed_evidence' => $evidence,
		], $reason);
	}

	/**
	 * "When in doubt: open" — sets the status to open and removes any
	 * confirmation.
	 */
	public function setOpen(int $versionId, string $uid, string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if (trim($reason) === '') {
			throw new InvalidInputException('Please give a reason.');
		}
		$this->change($this->load($versionId), $uid, 'set_open', ['rights_status' => 'open'] + self::noConfirmation(), $reason);
	}

	public function addToPortal(int $versionId, string $uid, string $since): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if (!FieldValue::isDate($since)) {
			throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['In singers\' portal since']);
		}
		$version = $this->load($versionId);
		$denial = $this->portalDenial($version, $this->licenses->findByVersion($versionId), $this->today($uid));
		if ($denial !== null) {
			throw new InvalidInputException($denial);
		}
		$this->change($version, $uid, 'portal_added', ['portal_since' => $since, 'portal_removed_at' => null, 'portal_removed_reason' => null], null);
	}

	public function removeFromPortal(int $versionId, string $uid, string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if (trim($reason) === '') {
			throw new InvalidInputException('Please give a reason.');
		}
		$this->change($this->load($versionId), $uid, 'portal_removed', [
			'portal_removed_at' => $this->today($uid),
			'portal_removed_reason' => $reason,
		], $reason);
	}

	public function isOnPortal(WorkVersion $version): bool {
		return $version->getPortalSince() !== null && $version->getPortalRemovedAt() === null;
	}

	/**
	 * @param list<License> $licenses
	 * @return string|null null when the version may be offered to singers
	 */
	public function portalDenial(WorkVersion $version, array $licenses, string $day): ?string {
		return $this->releaseDenial($version, $licenses, $day, 'portal');
	}

	/**
	 * @param list<License> $licenses
	 */
	public function printStationDenial(WorkVersion $version, array $licenses, string $day): ?string {
		return $this->releaseDenial($version, $licenses, $day, 'print_station');
	}

	/**
	 * @param list<License> $licenses
	 */
	public function hasLicense(array $licenses, string $use, string $day): bool {
		foreach ($licenses as $license) {
			if ($license->covers($use, $day)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Today in the time zone of the account; without an account (public
	 * pages) in the time zone of the server.
	 */
	public function today(?string $uid): string {
		return $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
	}

	/**
	 * Fields to clear when a confirmation no longer applies.
	 *
	 * @return array<string, null>
	 */
	public static function noConfirmation(): array {
		return ['confirmed_by' => null, 'confirmed_by_name' => null, 'confirmed_at' => null,
			'confirmed_reason' => null, 'confirmed_evidence' => null];
	}

	/**
	 * @param list<License> $licenses
	 */
	private function releaseDenial(WorkVersion $version, array $licenses, string $day, string $use): ?string {
		if ($version->getPreservationCopy() === true) {
			return 'Internal preservation copy: never given to singers.';
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return null;
		}
		if ($this->hasLicense($licenses, $use, $day)) {
			return null;
		}
		if ($version->getRightsStatus() === 'public_domain') {
			return 'Public domain is not confirmed yet.';
		}
		return 'Neither confirmed public domain nor a valid license for this use.';
	}

	/**
	 * @param array<string, string|int|bool|null> $values column => new value
	 */
	private function change(WorkVersion $version, string $uid, string $action, array $values, ?string $reason): void {
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach ($values as $field => $value) {
				$old = $version->getValue($field);
				if ($old === $value) {
					continue;
				}
				$version->setValue($field, $value);
				$this->audit->record($operation, $uid, Fields::VERSION, $version->getId(), $action, $field, $old, $value, $reason);
			}
			$version->setModified($this->time->getTime());
			$this->versions->update($version);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function load(int $versionId): WorkVersion {
		try {
			return $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}
}
