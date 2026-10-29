<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One issue of a choir folder to a person and its return (folder book).
 *
 * `openFolderId` holds the folder id while the folder is out (unique in the
 * database: a folder is issued only once at a time) and is cleared on the
 * return. After the retention period the name, the account and the notes
 * are removed and `anonymized` is set; the row stays.
 *
 * @method int getFolderId()
 * @method void setFolderId(int $folderId)
 * @method int|null getOpenFolderId()
 * @method void setOpenFolderId(?int $openFolderId)
 * @method string|null getHolderUid()
 * @method void setHolderUid(?string $holderUid)
 * @method string|null getHolderName()
 * @method void setHolderName(?string $holderName)
 * @method string getIssuedOn()
 * @method void setIssuedOn(string $issuedOn)
 * @method string|null getIssueNote()
 * @method void setIssueNote(?string $issueNote)
 * @method string|null getReturnedOn()
 * @method void setReturnedOn(?string $returnedOn)
 * @method string|null getReturnWear()
 * @method void setReturnWear(?string $returnWear)
 * @method string|null getReturnMissing()
 * @method void setReturnMissing(?string $returnMissing)
 * @method string|null getReturnNote()
 * @method void setReturnNote(?string $returnNote)
 * @method int|null getAnonymized()
 * @method void setAnonymized(?int $anonymized)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class FolderLoan extends Entity implements JsonSerializable {
	use FieldAccess;

	protected int $folderId = 0;
	protected ?int $openFolderId = null;
	protected ?string $holderUid = null;
	protected ?string $holderName = null;
	protected string $issuedOn = '';
	protected ?string $issueNote = null;
	protected ?string $returnedOn = null;
	protected ?string $returnWear = null;
	protected ?string $returnMissing = null;
	protected ?string $returnNote = null;
	protected ?int $anonymized = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['folderId', 'openFolderId', 'anonymized', 'created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['holderUid', 'holderName', 'issuedOn', 'issueNote', 'returnedOn', 'returnWear', 'returnMissing', 'returnNote'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
