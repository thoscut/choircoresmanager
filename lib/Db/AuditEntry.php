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
 * One line of the audit log. Only ever inserted, never changed.
 *
 * The display name is stored as a snapshot because accounts can be deleted;
 * the same applies to the roles held at the time of the change.
 *
 * @method string getOperation()
 * @method void setOperation(string $operation)
 * @method int getTime()
 * @method void setTime(int $time)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getUserName()
 * @method void setUserName(?string $userName)
 * @method string|null getRoles()
 * @method void setRoles(?string $roles)
 * @method string getObjectType()
 * @method void setObjectType(string $objectType)
 * @method int|null getObjectId()
 * @method void setObjectId(?int $objectId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getField()
 * @method void setField(?string $field)
 * @method string|null getOldValue()
 * @method void setOldValue(?string $oldValue)
 * @method string|null getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 */
class AuditEntry extends Entity implements JsonSerializable {
	use FieldAccess;

	protected string $operation = '';
	protected int $time = 0;
	protected ?string $userId = null;
	protected ?string $userName = null;
	protected ?string $roles = null;
	protected string $objectType = '';
	protected ?int $objectId = null;
	protected string $action = '';
	protected ?string $field = null;
	protected ?string $oldValue = null;
	protected ?string $newValue = null;
	protected ?string $reason = null;

	public function __construct() {
		$this->addType('time', 'integer');
		$this->addType('objectId', 'integer');
		foreach (['operation', 'userId', 'userName', 'roles', 'objectType', 'action', 'field', 'oldValue', 'newValue', 'reason'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
