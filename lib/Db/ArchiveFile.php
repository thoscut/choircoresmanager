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
 * Reference to a file in the archive folder. The app only reads files; it
 * never modifies, moves or deletes them.
 *
 * Path and checksum are stored next to the file id because file ids are only
 * valid within one Nextcloud instance and do not survive a migration.
 *
 * `linked_manually` marks a file the librarian linked to a copy by hand; the
 * file sync never moves such a file to another copy. `sha256_mismatch` is set
 * when the content changed although size and modification time did not
 * (silent corruption); the stored checksum is then kept as reference.
 *
 * @method int|null getCopyId()
 * @method void setCopyId(?int $copyId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method int|null getSize()
 * @method void setSize(?int $size)
 * @method int|null getMtime()
 * @method void setMtime(?int $mtime)
 * @method string|null getEtag()
 * @method void setEtag(?string $etag)
 * @method string|null getSha256()
 * @method void setSha256(?string $sha256)
 * @method bool|null getListed()
 * @method void setListed(?bool $listed)
 * @method bool|null getMusicxmlValid()
 * @method void setMusicxmlValid(?bool $musicxmlValid)
 * @method bool|null getFound()
 * @method void setFound(?bool $found)
 * @method int getSyncedAt()
 * @method void setSyncedAt(int $syncedAt)
 * @method bool|null getLinkedManually()
 * @method void setLinkedManually(?bool $linkedManually)
 * @method int|null getSha256At()
 * @method void setSha256At(?int $sha256At)
 * @method bool|null getSha256Mismatch()
 * @method void setSha256Mismatch(?bool $sha256Mismatch)
 */
class ArchiveFile extends Entity implements JsonSerializable {
	use FieldAccess;

	protected ?int $copyId = null;
	protected int $fileId = 0;
	protected string $path = '';
	protected string $name = '';
	protected ?string $type = null;
	protected ?string $role = null;
	protected ?int $size = null;
	protected ?int $mtime = null;
	protected ?string $etag = null;
	protected ?string $sha256 = null;
	protected ?bool $listed = false;
	protected ?bool $musicxmlValid = false;
	protected ?bool $found = true;
	protected int $syncedAt = 0;
	protected ?bool $linkedManually = false;
	protected ?int $sha256At = null;
	protected ?bool $sha256Mismatch = false;

	public function __construct() {
		foreach (['copyId', 'fileId', 'size', 'mtime', 'syncedAt', 'sha256At'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['path', 'name', 'type', 'role', 'etag', 'sha256'] as $field) {
			$this->addType($field, 'string');
		}
		foreach (['listed', 'musicxmlValid', 'found', 'linkedManually', 'sha256Mismatch'] as $field) {
			$this->addType($field, 'boolean');
		}
	}
}
