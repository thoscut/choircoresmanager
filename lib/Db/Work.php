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
 * The work: the composition itself, independent of setting and copy.
 *
 * A work that was merged into another one keeps its row with `merged_into`
 * set to the target work; it has no versions any more and is not listed.
 *
 * @method string|null getWorkNo()
 * @method void setWorkNo(?string $workNo)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getComposer()
 * @method void setComposer(?string $composer)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getRepertoireImport()
 * @method void setRepertoireImport(?string $repertoireImport)
 * @method string|null getPerformancesImport()
 * @method void setPerformancesImport(?string $performancesImport)
 * @method string|null getLastPerformedImport()
 * @method void setLastPerformedImport(?string $lastPerformedImport)
 * @method string|null getSongListsImport()
 * @method void setSongListsImport(?string $songListsImport)
 * @method int|null getMergedInto()
 * @method void setMergedInto(?int $mergedInto)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class Work extends Entity implements JsonSerializable {
	use FieldAccess;

	protected ?string $workNo = null;
	protected ?string $title = null;
	protected ?string $composer = null;
	protected ?string $notes = null;
	protected ?string $repertoireImport = null;
	protected ?string $performancesImport = null;
	protected ?string $lastPerformedImport = null;
	protected ?string $songListsImport = null;
	protected ?int $mergedInto = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['workNo', 'title', 'composer', 'notes', 'repertoireImport', 'performancesImport',
			'lastPerformedImport', 'songListsImport'] as $field) {
			$this->addType($field, 'string');
		}
		$this->addType('mergedInto', 'integer');
		$this->addType('created', 'integer');
		$this->addType('modified', 'integer');
	}
}
