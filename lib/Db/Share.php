<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Share of a score or folder collection, via secret link or with a group.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @method int getId()
 * @method void setId(int $value)
 * @method string getShareType()
 * @method void setShareType(string $value)
 * @method string|null getToken()
 * @method void setToken(?string $value)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $value)
 * @method string getTargetType()
 * @method void setTargetType(string $value)
 * @method int getTargetId()
 * @method void setTargetId(int $value)
 * @method string|null getLabel()
 * @method void setLabel(?string $value)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $value)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $value)
 */
class Share extends Entity implements JsonSerializable {
	protected string $shareType = '';
	protected ?string $token = null;
	protected ?string $groupId = null;
	protected string $targetType = '';
	protected int $targetId = 0;
	protected ?string $label = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('shareType', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('groupId', Types::STRING);
		$this->addType('targetType', Types::STRING);
		$this->addType('targetId', Types::BIGINT);
		$this->addType('label', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'shareType' => $this->shareType,
			'token' => $this->token,
			'groupId' => $this->groupId,
			'targetType' => $this->targetType,
			'targetId' => $this->targetId,
			'label' => $this->label,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
		];
	}
}
