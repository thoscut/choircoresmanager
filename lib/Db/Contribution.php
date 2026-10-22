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
 * The contribution of a person to a version, with the role. Rights hang on
 * the version, so contributions do too.
 *
 * `text_for_composition` records that a text was written for this
 * composition (§ 65 (3) German copyright act). It is documentation only: the
 * protection proposal always waits for the last of all persons involved.
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method int getPersonId()
 * @method void setPersonId(int $personId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method bool|null getTextForComposition()
 * @method void setTextForComposition(?bool $textForComposition)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class Contribution extends Entity implements JsonSerializable {
	use FieldAccess;

	public const ROLES = ['composer', 'lyricist', 'arranger', 'translator', 'text_editor', 'editor', 'unspecified'];

	protected int $versionId = 0;
	protected int $personId = 0;
	protected string $role = 'unspecified';
	protected ?bool $textForComposition = false;
	protected int $created = 0;

	public function __construct() {
		foreach (['versionId', 'personId', 'created'] as $field) {
			$this->addType($field, 'integer');
		}
		$this->addType('role', 'string');
		$this->addType('textForComposition', 'boolean');
	}
}
