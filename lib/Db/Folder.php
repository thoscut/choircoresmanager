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
 * A choir folder of the folder book (milestone 0.7, module "folder_book"):
 * label, voice, contents (copies of the catalog with their count, as JSON),
 * condition and the sheets missing at the moment. Never deleted; a folder
 * taken out of service gets the status `retired`.
 *
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string|null getVoice()
 * @method void setVoice(?string $voice)
 * @method string|null getContents()
 * @method void setContents(?string $contents)
 * @method string getWear()
 * @method void setWear(string $wear)
 * @method string|null getMissing()
 * @method void setMissing(?string $missing)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getStatusReason()
 * @method void setStatusReason(?string $statusReason)
 * @method string|null getStatusSince()
 * @method void setStatusSince(?string $statusSince)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class Folder extends Entity implements JsonSerializable {
	use FieldAccess;

	public const ACTIVE = 'active';
	public const RETIRED = 'retired';

	protected string $label = '';
	protected ?string $voice = null;
	protected ?string $contents = null;
	protected string $wear = 'good';
	protected ?string $missing = null;
	protected ?string $note = null;
	protected string $status = self::ACTIVE;
	protected ?string $statusReason = null;
	protected ?string $statusSince = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['label', 'voice', 'contents', 'wear', 'missing', 'note', 'status', 'statusReason', 'statusSince'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
