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
 * A point to review in a transcription: an uncertain reading, something the
 * transcription left out, a finding about the material or a question, with
 * a location in the score (printed measures, voice, verse, page of the scan)
 * and the role responsible for it.
 *
 * Status instead of deletion: open → confirmed (the reading is right),
 * correct (correction order for the tool chain), question (turned into a
 * task), dismissed; corrected is set by the app only (a correction in the
 * app or a new version of the MusicXML). `file_id`/`file_sha256` keep the
 * MusicXML (notarch_files.id) and its checksum the point was made for.
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method int getCopyId()
 * @method void setCopyId(int $copyId)
 * @method int|null getReportId()
 * @method void setReportId(?int $reportId)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getFileSha256()
 * @method void setFileSha256(?string $fileSha256)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string|null getMeasures()
 * @method void setMeasures(?string $measures)
 * @method string|null getMeasureFrom()
 * @method void setMeasureFrom(?string $measureFrom)
 * @method string|null getMeasureTo()
 * @method void setMeasureTo(?string $measureTo)
 * @method string|null getVoice()
 * @method void setVoice(?string $voice)
 * @method int|null getVerse()
 * @method void setVerse(?int $verse)
 * @method int|null getPage()
 * @method void setPage(?int $page)
 * @method string|null getText()
 * @method void setText(?string $text)
 * @method string|null getPointKey()
 * @method void setPointKey(?string $pointKey)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getCorrection()
 * @method void setCorrection(?string $correction)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method string|null getDecidedByName()
 * @method void setDecidedByName(?string $decidedByName)
 * @method int|null getDecided()
 * @method void setDecided(?int $decided)
 * @method string|null getDecisionReason()
 * @method void setDecisionReason(?string $decisionReason)
 * @method int|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method int|null getResolvedReportId()
 * @method void setResolvedReportId(?int $resolvedReportId)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class ReviewPoint extends Entity implements JsonSerializable {
	use FieldAccess;

	public const UNCERTAIN = 'uncertain';
	public const OMITTED = 'omitted';
	public const MATERIAL = 'material';
	public const QUESTION = 'question';
	public const CATEGORIES = [self::UNCERTAIN, self::OMITTED, self::MATERIAL, self::QUESTION];

	public const OPEN = 'open';
	public const CONFIRMED = 'confirmed';
	public const CORRECT = 'correct';
	public const CORRECTED = 'corrected';
	public const ASKED = 'question';
	public const DISMISSED = 'dismissed';
	public const STATUSES = [self::OPEN, self::CONFIRMED, self::CORRECT, self::CORRECTED, self::ASKED, self::DISMISSED];
	/** Statuses that still wait for something: a decision, an answer or the tool chain */
	public const UNRESOLVED = [self::OPEN, self::CORRECT, self::ASKED];

	protected int $versionId = 0;
	protected int $copyId = 0;
	protected ?int $reportId = null;
	protected ?int $fileId = null;
	protected ?string $fileSha256 = null;
	protected string $category = self::UNCERTAIN;
	protected ?string $measures = null;
	protected ?string $measureFrom = null;
	protected ?string $measureTo = null;
	protected ?string $voice = null;
	protected ?int $verse = null;
	protected ?int $page = null;
	protected ?string $text = null;
	protected ?string $pointKey = null;
	protected string $role = '';
	protected string $status = self::OPEN;
	protected ?string $correction = null;
	protected ?string $decidedBy = null;
	protected ?string $decidedByName = null;
	protected ?int $decided = null;
	protected ?string $decisionReason = null;
	protected ?int $taskId = null;
	protected ?int $resolvedReportId = null;
	protected ?string $createdBy = null;
	protected int $created = 0;

	public function __construct() {
		foreach (['versionId', 'copyId', 'reportId', 'fileId', 'verse', 'page', 'decided', 'taskId', 'resolvedReportId', 'created'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['fileSha256', 'category', 'measures', 'measureFrom', 'measureTo', 'voice', 'text', 'pointKey', 'role', 'status',
			'correction', 'decidedBy', 'decidedByName', 'decisionReason', 'createdBy'] as $field) {
			$this->addType($field, 'string');
		}
		// The setter ignores a value equal to the default, so a point of the
		// default category would be inserted without one; the column has no
		// database default.
		$this->markFieldUpdated('category');
	}
}
