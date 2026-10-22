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
 * The report of a transcription (format bericht/1) as read for one copy.
 * The received text is kept unchanged as evidence (`raw`, with `sha256`);
 * only the known fields are taken over: the review state of the version
 * (`origin`, `review`, `reasoning`) and a summary for display. A later report
 * of the same copy supersedes it (`superseded_by`).
 *
 * @method int getVersionId()
 * @method void setVersionId(int $versionId)
 * @method int getCopyId()
 * @method void setCopyId(int $copyId)
 * @method int|null getReportFileId()
 * @method void setReportFileId(?int $reportFileId)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string|null getRound()
 * @method void setRound(?string $round)
 * @method string|null getReportDate()
 * @method void setReportDate(?string $reportDate)
 * @method string getBasename()
 * @method void setBasename(string $basename)
 * @method int|null getMusicxmlId()
 * @method void setMusicxmlId(?int $musicxmlId)
 * @method string|null getMusicxmlSha256()
 * @method void setMusicxmlSha256(?string $musicxmlSha256)
 * @method string|null getMethod()
 * @method void setMethod(?string $method)
 * @method string|null getOrigin()
 * @method void setOrigin(?string $origin)
 * @method string|null getReview()
 * @method void setReview(?string $review)
 * @method string|null getReasoning()
 * @method void setReasoning(?string $reasoning)
 * @method string|null getRightsClass()
 * @method void setRightsClass(?string $rightsClass)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getRaw()
 * @method void setRaw(?string $raw)
 * @method int|null getSupersededBy()
 * @method void setSupersededBy(?int $supersededBy)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class TranscriptionReport extends Entity implements JsonSerializable {
	use FieldAccess {
		jsonSerialize as private columns;
	}

	public const SOURCE_FILE = 'file';
	public const SOURCE_API = 'api';

	protected int $versionId = 0;
	protected int $copyId = 0;
	protected ?int $reportFileId = null;
	protected string $source = self::SOURCE_API;
	protected string $sha256 = '';
	protected ?string $round = null;
	protected ?string $reportDate = null;
	protected string $basename = '';
	protected ?int $musicxmlId = null;
	protected ?string $musicxmlSha256 = null;
	protected ?string $method = null;
	protected ?string $origin = null;
	protected ?string $review = null;
	protected ?string $reasoning = null;
	protected ?string $rightsClass = null;
	protected ?string $summary = null;
	protected ?string $raw = null;
	protected ?int $supersededBy = null;
	protected ?string $createdBy = null;
	protected int $created = 0;

	public function __construct() {
		foreach (['versionId', 'copyId', 'reportFileId', 'musicxmlId', 'supersededBy', 'created'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['source', 'sha256', 'round', 'reportDate', 'basename', 'musicxmlSha256', 'method', 'origin', 'review', 'reasoning',
			'rightsClass', 'summary', 'raw', 'createdBy'] as $field) {
			$this->addType($field, 'string');
		}
	}

	/**
	 * Columns without the received text (it can be large and is evidence
	 * only), with the summary decoded.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$data = $this->columns();
		unset($data['raw']);
		$data['summary'] = $this->summaryData();
		return $data;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function summaryData(): array {
		if ($this->summary === null || $this->summary === '') {
			return [];
		}
		try {
			return self::arrayOrEmpty(json_decode($this->summary, true, 16, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			return [];
		}
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private static function arrayOrEmpty(mixed $value): array {
		return is_array($value) ? $value : [];
	}
}
