<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Licenses and permissions per version. Each use is listed separately (a print
 * license does not cover the singers' portal). Licenses are not deleted: an
 * ended license keeps its end date as evidence.
 */
class LicenseService {
	private const EDITABLE = ['holder', 'uses', 'quantity', 'valid_from', 'valid_until', 'evidence', 'notes'];

	public function __construct(
		private LicenseMapper $licenses,
		private WorkVersionMapper $versions,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(int $versionId, array $data, string $uid): License {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		$license = new License();
		$license->setVersionId($versionId);
		$license->setCreated($this->time->getTime());
		$license->setModified($this->time->getTime());
		$this->assign($license, $data, true);
		$license = $this->licenses->insert($license);
		$this->audit->record($this->audit->newOperation(), $uid, 'license', $license->getId(), 'created', 'uses', null, $license->getUses());
		return $license;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $licenseId, array $data, string $uid): License {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$license = $this->licenses->find($licenseId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		$before = $license->jsonSerialize();
		$this->assign($license, $data, false);
		$operation = $this->audit->newOperation();
		foreach (self::EDITABLE as $field) {
			if ($before[$field] !== $license->getValue($field)) {
				$this->audit->record($operation, $uid, 'license', $licenseId, 'changed', $field, $before[$field], $license->getValue($field));
			}
		}
		$license->setModified($this->time->getTime());
		return $this->licenses->update($license);
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	private function assign(License $license, array $data, bool $isNew): void {
		foreach (array_keys($data) as $field) {
			$field = (string)$field;
			if (!in_array($field, self::EDITABLE, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			$license->setValue($field, self::value($field, $data[$field]));
		}
		if ($isNew && $license->useList() === []) {
			throw new InvalidInputException('A license needs at least one use.');
		}
		if ($license->getValidFrom() !== null && $license->getValidUntil() !== null && $license->getValidFrom() > $license->getValidUntil()) {
			throw new InvalidInputException('The license ends before it starts.');
		}
	}

	/**
	 * Checks one value from the request and converts it to the stored form.
	 */
	private static function value(string $field, mixed $value): string|int|null {
		if ($value === '' || $value === []) {
			return null;
		}
		switch ($field) {
			case 'uses':
				$uses = Input::strings($value, 'Uses');
				foreach ($uses as $use) {
					if (!isset(Fields::LICENSE_USES[$use])) {
						throw new InvalidInputException('Unknown license use: %s', [$use]);
					}
				}
				return $uses === [] ? null : implode(',', array_values(array_unique($uses)));
			case 'quantity':
				if ($value === null) {
					return null;
				}
				$quantity = filter_var($value, FILTER_VALIDATE_INT);
				if ($quantity === false) {
					throw new InvalidInputException('"%s" must be a whole number.', ['Number of copies']);
				}
				return $quantity;
			case 'valid_from':
			case 'valid_until':
				if ($value !== null && (!is_string($value) || !FieldValue::isDate($value))) {
					throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', [$field === 'valid_from' ? 'Valid from' : 'Valid until']);
				}
				return $value;
			default:
				return Input::text($value, $field);
		}
	}
}
