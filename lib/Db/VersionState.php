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
 * A revision of the MusicXML of a version (milestone 0.8, module
 * "version_states"): a record that points to the file, with its checksum,
 * what changed (measures, voices, note, the changes of a correction), who
 * and when. The app copies no file; a revision is never changed afterwards,
 * except for the mark of the current revision (`valid_since`,
 * `valid_set_by`) that the catalog librarian sets.
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method int|null getCopyId()
 * @method void setCopyId(?int $copyId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getSha256()
 * @method void setSha256(?string $sha256)
 * @method int getNumber()
 * @method void setNumber(int $number)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string|null getMeasures()
 * @method void setMeasures(?string $measures)
 * @method string|null getVoices()
 * @method void setVoices(?string $voices)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string|null getChanges()
 * @method void setChanges(?string $changes)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getCreatedByName()
 * @method void setCreatedByName(?string $createdByName)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method string|null getValidSince()
 * @method void setValidSince(?string $validSince)
 * @method string|null getValidSetBy()
 * @method void setValidSetBy(?string $validSetBy)
 */
class VersionState extends Entity implements JsonSerializable {
	use FieldAccess;

	/** The MusicXML as found when the module started recording revisions */
	public const FIRST = 'first';
	/** A small correction saved in the app */
	public const CORRECTION = 'correction';
	/** A new or changed MusicXML found by the file sync */
	public const SYNC = 'sync';
	public const SOURCES = [self::FIRST, self::CORRECTION, self::SYNC];

	protected int $versionId = 0;
	protected ?int $copyId = null;
	protected int $fileId = 0;
	protected string $fileName = '';
	protected ?string $sha256 = null;
	protected int $number = 0;
	protected string $source = self::SYNC;
	protected ?string $measures = null;
	protected ?string $voices = null;
	protected ?string $note = null;
	protected ?string $changes = null;
	protected ?string $createdBy = null;
	protected ?string $createdByName = null;
	protected int $created = 0;
	protected ?string $validSince = null;
	protected ?string $validSetBy = null;

	public function __construct() {
		foreach (['versionId', 'copyId', 'fileId', 'number', 'created'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['fileName', 'sha256', 'source', 'measures', 'voices', 'note', 'changes', 'createdBy', 'createdByName',
			'validSince', 'validSetBy'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
