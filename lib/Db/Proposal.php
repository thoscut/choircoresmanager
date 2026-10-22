<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A proposed change from a tool or transcription round. Proposals never change
 * the catalog themselves; the catalog librarian accepts or rejects them.
 * The proposed value is stored JSON-encoded to keep its type.
 *
 * @method string getObjectType()
 * @method void setObjectType(string $objectType)
 * @method int getObjectId()
 * @method void setObjectId(int $objectId)
 * @method string getField()
 * @method void setField(string $field)
 * @method string|null getNewValue()
 * @method void setNewValue(?string $newValue)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method int|null getDecided()
 * @method void setDecided(?int $decided)
 * @method string|null getDecisionReason()
 * @method void setDecisionReason(?string $decisionReason)
 */
class Proposal extends Entity implements JsonSerializable {
	use FieldAccess;

	public const OPEN = 'open';
	public const ACCEPTED = 'accepted';
	public const REJECTED = 'rejected';

	protected string $objectType = '';
	protected int $objectId = 0;
	protected string $field = '';
	protected ?string $newValue = null;
	protected ?string $reason = null;
	protected ?string $source = null;
	protected string $status = self::OPEN;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected ?string $decidedBy = null;
	protected ?int $decided = null;
	protected ?string $decisionReason = null;

	public function __construct() {
		foreach (['objectId', 'created', 'decided'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['objectType', 'field', 'newValue', 'reason', 'source', 'status', 'createdBy', 'decidedBy', 'decisionReason'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * The proposed value, decoded.
	 *
	 * @throws \JsonException
	 */
	public function value(): string|int|float|bool|null {
		return $this->newValue === null ? null : self::plain(json_decode($this->newValue, true, 512, JSON_THROW_ON_ERROR));
	}

	private static function plain(mixed $value): string|int|float|bool|null {
		if ($value === null || is_scalar($value)) {
			return $value;
		}
		throw new \UnexpectedValueException('A proposed value must be a single value');
	}
}
