<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A license or permission for a version: who granted it, which uses it covers
 * (each listed separately), how many copies, for how long, and the evidence.
 * Licenses are never deleted; an ended license keeps its end date.
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method string|null getHolder()
 * @method void setHolder(?string $holder)
 * @method string|null getUses()
 * @method void setUses(?string $uses)
 * @method int|null getQuantity()
 * @method void setQuantity(?int $quantity)
 * @method string|null getValidFrom()
 * @method void setValidFrom(?string $validFrom)
 * @method string|null getValidUntil()
 * @method void setValidUntil(?string $validUntil)
 * @method string|null getEvidence()
 * @method void setEvidence(?string $evidence)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class License extends Entity implements JsonSerializable {
	use FieldAccess;

	protected int $versionId = 0;
	protected ?string $holder = null;
	/** Comma-separated keys of Fields::LICENSE_USES */
	protected ?string $uses = null;
	protected ?int $quantity = null;
	protected ?string $validFrom = null;
	protected ?string $validUntil = null;
	protected ?string $evidence = null;
	protected ?string $notes = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['versionId', 'quantity', 'created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['holder', 'uses', 'validFrom', 'validUntil', 'evidence', 'notes'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * @return list<string>
	 */
	public function useList(): array {
		return $this->uses === null || $this->uses === '' ? [] : explode(',', $this->uses);
	}

	/**
	 * Whether the license covers a use on the given day (YYYY-MM-DD).
	 */
	public function covers(string $use, string $day): bool {
		return in_array($use, $this->useList(), true)
			&& ($this->validFrom === null || $this->validFrom <= $day)
			&& ($this->validUntil === null || $this->validUntil >= $day);
	}
}
