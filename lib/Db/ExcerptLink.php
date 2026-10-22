<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A link to an excerpt of a score: printed measures, a voice as focus, an
 * optional verse, tempo and note. The link carries no rights of its own: the
 * rights are checked again on every call. It expires and can be revoked;
 * calls are only counted, not attributed to persons.
 *
 * `file_etag` remembers the state of the MusicXML when the link was created,
 * so a later correction of the transcription can be shown as "changed".
 *
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getCopyId()
 * @method void setCopyId(int $copyId)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getFileEtag()
 * @method void setFileEtag(?string $fileEtag)
 * @method string|null getMeasureFrom()
 * @method void setMeasureFrom(?string $measureFrom)
 * @method string|null getMeasureTo()
 * @method void setMeasureTo(?string $measureTo)
 * @method string|null getVoice()
 * @method void setVoice(?string $voice)
 * @method int|null getVerse()
 * @method void setVerse(?int $verse)
 * @method int|null getTempo()
 * @method void setTempo(?int $tempo)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string getCircle()
 * @method void setCircle(string $circle)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method string|null getExpires()
 * @method void setExpires(?string $expires)
 * @method int|null getRevoked()
 * @method void setRevoked(?int $revoked)
 * @method int getHits()
 * @method void setHits(int $hits)
 */
class ExcerptLink extends Entity implements JsonSerializable {
	use FieldAccess;

	public const CIRCLE_INTERNAL = 'internal';

	protected string $token = '';
	protected int $copyId = 0;
	protected ?int $fileId = null;
	protected ?string $fileEtag = null;
	protected ?string $measureFrom = null;
	protected ?string $measureTo = null;
	protected ?string $voice = null;
	protected ?int $verse = null;
	protected ?int $tempo = null;
	protected ?string $note = null;
	protected ?int $taskId = null;
	protected string $circle = self::CIRCLE_INTERNAL;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected ?string $expires = null;
	protected ?int $revoked = null;
	protected int $hits = 0;

	public function __construct() {
		foreach (['copyId', 'fileId', 'verse', 'tempo', 'taskId', 'created', 'revoked', 'hits'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['token', 'fileEtag', 'measureFrom', 'measureTo', 'voice', 'note', 'circle', 'createdBy', 'expires'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
