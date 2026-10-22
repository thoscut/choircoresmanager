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
 * A task about one copy: a question to the conductor, a check for the
 * librarian, or an error report on a transcription. It can point to a place
 * in the score (page, printed measures, voice, verse).
 *
 * Flow: open → answered (by the addressee) → applied or discarded (by the
 * catalog librarian). Checks without an answer go straight from open to
 * applied/discarded.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getCopyId()
 * @method void setCopyId(int $copyId)
 * @method int|null getPage()
 * @method void setPage(?int $page)
 * @method string|null getMeasureFrom()
 * @method void setMeasureFrom(?string $measureFrom)
 * @method string|null getMeasureTo()
 * @method void setMeasureTo(?string $measureTo)
 * @method string|null getVoice()
 * @method void setVoice(?string $voice)
 * @method int|null getVerse()
 * @method void setVerse(?int $verse)
 * @method string getAddressee()
 * @method void setAddressee(string $addressee)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method string|null getOrigin()
 * @method void setOrigin(?string $origin)
 * @method string|null getQuestion()
 * @method void setQuestion(?string $question)
 * @method string|null getAnswer()
 * @method void setAnswer(?string $answer)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getDue()
 * @method void setDue(?string $due)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method string|null getAnsweredBy()
 * @method void setAnsweredBy(?string $answeredBy)
 * @method string|null getAnsweredByName()
 * @method void setAnsweredByName(?string $answeredByName)
 * @method int|null getAnswered()
 * @method void setAnswered(?int $answered)
 * @method string|null getClosedBy()
 * @method void setClosedBy(?string $closedBy)
 * @method int|null getClosed()
 * @method void setClosed(?int $closed)
 * @method string|null getClosingNote()
 * @method void setClosingNote(?string $closingNote)
 */
class Task extends Entity implements JsonSerializable {
	use FieldAccess;

	public const KINDS = ['question', 'check', 'report'];
	public const OPEN = 'open';
	public const ANSWERED = 'answered';
	public const APPLIED = 'applied';
	public const DISCARDED = 'discarded';

	protected string $kind = 'question';
	protected int $copyId = 0;
	protected ?int $page = null;
	protected ?string $measureFrom = null;
	protected ?string $measureTo = null;
	protected ?string $voice = null;
	protected ?int $verse = null;
	protected string $addressee = '';
	protected ?string $category = null;
	protected ?string $origin = null;
	protected ?string $question = null;
	protected ?string $answer = null;
	protected string $status = self::OPEN;
	protected ?string $due = null;
	protected ?string $createdBy = null;
	protected int $created = 0;
	protected ?string $answeredBy = null;
	protected ?string $answeredByName = null;
	protected ?int $answered = null;
	protected ?string $closedBy = null;
	protected ?int $closed = null;
	protected ?string $closingNote = null;

	public function __construct() {
		foreach (['copyId', 'page', 'verse', 'created', 'answered', 'closed'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['kind', 'measureFrom', 'measureTo', 'voice', 'addressee', 'category', 'origin', 'question', 'answer',
			'status', 'due', 'createdBy', 'answeredBy', 'answeredByName', 'closedBy', 'closingNote'] as $field) {
			$this->addType($field, 'string');
		}
	}
}
