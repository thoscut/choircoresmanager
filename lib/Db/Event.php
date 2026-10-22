<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A concert (or other appearance) or a song list for a period.
 *
 * Concerts move from draft to fixed to sung; confirming "sung" turns its items
 * into documented performances. Song lists (e.g. "2025/1") say what singers
 * should have in their binders; they are no proof of performance.
 *
 * `event_date` is YYYY-MM-DD, or only YYYY for imported undated programmes.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getEventDate()
 * @method void setEventDate(?string $eventDate)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method string|null getPeriod()
 * @method void setPeriod(?string $period)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool|null getIsPublic()
 * @method void setIsPublic(?bool $isPublic)
 * @method bool|null getBoardAgreed()
 * @method void setBoardAgreed(?bool $boardAgreed)
 * @method bool|null getImported()
 * @method void setImported(?bool $imported)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class Event extends Entity implements JsonSerializable {
	use FieldAccess;

	public const CONCERT = 'concert';
	public const SONG_LIST = 'song_list';
	public const KINDS = [self::CONCERT, self::SONG_LIST];
	public const STATUSES = ['draft', 'fixed', 'sung'];

	protected string $kind = self::CONCERT;
	protected ?string $title = null;
	protected ?string $eventDate = null;
	protected ?string $location = null;
	protected ?string $period = null;
	protected string $status = 'draft';
	protected ?bool $isPublic = false;
	protected ?bool $boardAgreed = false;
	protected ?bool $imported = false;
	protected ?string $source = null;
	protected ?string $notes = null;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['kind', 'title', 'eventDate', 'location', 'period', 'status', 'source', 'notes', 'createdBy'] as $field) {
			$this->addType($field, 'string');
		}
		foreach (['isPublic', 'boardAgreed', 'imported'] as $field) {
			$this->addType($field, 'boolean');
		}
	}
}
