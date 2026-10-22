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
 * A file the app wrote into the target folder of the print station (module
 * "print_mirror"): its name, Nextcloud file id and the SHA-256 of what was
 * written, and the scan it was copied from with its ETag at that time. The
 * app replaces or removes only files listed here whose content is still
 * the one it wrote.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method int|null getCopyId()
 * @method void setCopyId(?int $copyId)
 * @method int|null getSourceId()
 * @method void setSourceId(?int $sourceId)
 * @method string|null getSourceEtag()
 * @method void setSourceEtag(?string $sourceEtag)
 * @method int getWritten()
 * @method void setWritten(int $written)
 */
class MirrorFile extends Entity implements JsonSerializable {
	use FieldAccess;

	protected string $name = '';
	protected int $fileId = 0;
	protected string $sha256 = '';
	protected ?int $copyId = null;
	protected ?int $sourceId = null;
	protected ?string $sourceEtag = null;
	protected int $written = 0;

	public function __construct() {
		foreach (['fileId', 'copyId', 'sourceId', 'written'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['name', 'sha256', 'sourceEtag'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
