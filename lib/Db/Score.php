<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Score entity representing a musical score.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @method int getId()
 * @method void setId(int $value)
 * @method string getTitle()
 * @method void setTitle(string $value)
 * @method string|null getTitleShort()
 * @method void setTitleShort(?string $value)
 * @method string|null getComposer()
 * @method void setComposer(?string $value)
 * @method string|null getArranger()
 * @method void setArranger(?string $value)
 * @method string|null getPublisher()
 * @method void setPublisher(?string $value)
 * @method int|null getYear()
 * @method void setYear(?int $value)
 * @method float|null getDifficulty()
 * @method void setDifficulty(?float $value)
 * @method string|null getDefects()
 * @method void setDefects(?string $value)
 * @method string|null getPhysicalCopiesStatus()
 * @method void setPhysicalCopiesStatus(?string $value)
 * @method string|null getDigitalStatus()
 * @method void setDigitalStatus(?string $value)
 * @method string|null getGemaIds()
 * @method string|null getMedleyContents()
 * @method int|null getDuration()
 * @method void setDuration(?int $value)
 * @method string|null getSignature()
 * @method void setSignature(?string $value)
 * @method string|null getLyricist()
 * @method void setLyricist(?string $value)
 * @method string|null getVoicing()
 * @method void setVoicing(?string $value)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $value)
 * @method string|null getInventoryStatus()
 */
class Score extends Entity implements JsonSerializable {
	/** Inventory status: set manually, describes the physical/digital copy in the archive */
	public const INVENTORY_STATUS_ACTIVE = 'active';
	public const INVENTORY_STATUS_RETIRED = 'retired';
	public const INVENTORY_STATUS_MISSING = 'missing';
	public const INVENTORY_STATUS_LENT = 'lent';
	public const INVENTORY_STATUSES = [
		self::INVENTORY_STATUS_ACTIVE,
		self::INVENTORY_STATUS_RETIRED,
		self::INVENTORY_STATUS_MISSING,
		self::INVENTORY_STATUS_LENT,
	];

	/** Repertoire class: derived from performances, never set manually */
	public const REPERTOIRE_CLASS_CURRENT = 'current';
	public const REPERTOIRE_CLASS_DORMANT = 'dormant';
	public const REPERTOIRE_CLASS_FORMER = 'former';
	public const REPERTOIRE_CLASS_NONE = 'none';

	protected string $title = '';
	protected ?string $titleShort = null;
	protected ?string $composer = null;
	protected ?string $arranger = null;
	protected ?string $publisher = null;
	protected ?int $year = null;
	protected ?float $difficulty = null;
	protected ?string $medleyContents = null;
	protected ?string $defects = null;
	protected ?string $physicalCopiesStatus = null;
	protected ?string $digitalStatus = null;
	protected ?string $gemaIds = null;
	protected ?int $duration = null;
	protected ?string $signature = null;
	protected ?string $lyricist = null;
	protected ?string $voicing = null;
	protected ?string $language = null;
	protected ?string $inventoryStatus = null;

	// transient properties, not stored in DB
	// ensure to add setters & getters for these
	protected ?array $tags = null;
	/** @var array{id: int, index: int}|null */
	protected ?array $scoreBook = null;
	/** Start of the most recent non-draft setlist containing this score (ATOM) */
	protected ?string $lastPerformed = null;
	/** One of the REPERTOIRE_CLASS_* constants, derived from lastPerformed */
	protected string $repertoireClass = self::REPERTOIRE_CLASS_NONE;

	public function __construct() {
		$this->addType('title', Types::STRING);
		$this->addType('titleShort', Types::STRING);
		$this->addType('composer', Types::STRING);
		$this->addType('arranger', Types::STRING);
		$this->addType('publisher', Types::STRING);
		$this->addType('year', Types::INTEGER);
		$this->addType('difficulty', Types::FLOAT);
		$this->addType('medleyContents', Types::STRING);
		$this->addType('defects', Types::STRING);
		$this->addType('physicalCopiesStatus', Types::STRING);
		$this->addType('digitalStatus', Types::STRING);
		$this->addType('gemaIds', Types::STRING);
		$this->addType('duration', Types::INTEGER);
		$this->addType('signature', Types::STRING);
		$this->addType('lyricist', Types::STRING);
		$this->addType('voicing', Types::STRING);
		$this->addType('language', Types::STRING);
		$this->addType('inventoryStatus', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'titleShort' => $this->titleShort,
			'composer' => $this->composer,
			'arranger' => $this->arranger,
			'publisher' => $this->publisher,
			'year' => $this->year,
			'difficulty' => $this->difficulty,
			'medleyContents' => $this->decodeJsonArray($this->medleyContents, 'medleyContents'),
			'defects' => $this->defects,
			'physicalCopiesStatus' => $this->physicalCopiesStatus,
			'digitalStatus' => $this->digitalStatus,
			'gemaIds' => $this->decodeJsonArray($this->gemaIds, 'gemaIds'),
			'tags' => $this->tags ?? [],
			'scoreBook' => $this->scoreBook,
			'duration' => $this->duration,
			'signature' => $this->signature,
			'lyricist' => $this->lyricist,
			'voicing' => $this->voicing,
			'language' => $this->language,
			'inventoryStatus' => $this->inventoryStatus,
			'lastPerformed' => $this->lastPerformed,
			'repertoireClass' => $this->repertoireClass,
		];
	}

	/**
	 * Set medley contents array.
	 *
	 * Handles JSON encoding when saving to database.
	 *
	 * @param list<string>|string|null $medleyContents Array or JSON string
	 * @psalm-suppress PossiblyUnusedMethod - Called by Entity framework and API
	 */
	public function setMedleyContents(array|string|null $medleyContents): void {
		$content = is_string($medleyContents) ? $medleyContents : $this->encodeJsonArray($medleyContents, 'medleyContents');
		$this->setter('medleyContents', [$content]);
	}

	/**
	 * Set inventory status.
	 *
	 * Empty strings are stored as null; any other value must be one of INVENTORY_STATUSES.
	 *
	 * @param string|null $inventoryStatus
	 * @throws \InvalidArgumentException if the value is not an allowed status
	 * @psalm-suppress PossiblyUnusedMethod - Called by Entity framework and API
	 */
	public function setInventoryStatus(?string $inventoryStatus): void {
		if ($inventoryStatus === '') {
			$inventoryStatus = null;
		}
		if ($inventoryStatus !== null && !\in_array($inventoryStatus, self::INVENTORY_STATUSES, true)) {
			throw new \InvalidArgumentException('Invalid inventory status: ' . $inventoryStatus);
		}
		$this->setter('inventoryStatus', [$inventoryStatus]);
	}

	/**
	 * Get transient last performed date.
	 *
	 * @return string|null
	 * @psalm-suppress PossiblyUnusedMethod - Called via jsonSerialize
	 */
	public function getLastPerformed(): ?string {
		return $this->lastPerformed;
	}

	/**
	 * Set transient last performed date (ATOM formatted).
	 *
	 * @param string|null $lastPerformed
	 */
	public function setLastPerformed(?string $lastPerformed): void {
		$this->lastPerformed = $lastPerformed;
	}

	/**
	 * Get the derived repertoire class.
	 *
	 * @return string one of the REPERTOIRE_CLASS_* constants
	 * @psalm-suppress PossiblyUnusedMethod - Called via jsonSerialize
	 */
	public function getRepertoireClass(): string {
		return $this->repertoireClass;
	}

	/**
	 * Derive the repertoire class from the last performance.
	 *
	 * - current: performed within the last $currentYears years
	 * - dormant: performed within the last $dormantYears years
	 * - former: performed longer ago
	 * - none: no performance recorded
	 *
	 * @param int $currentYears threshold for "current" in years
	 * @param int $dormantYears threshold for "dormant" in years
	 * @param \DateTimeImmutable $now reference date
	 */
	public function classifyRepertoire(int $currentYears, int $dormantYears, \DateTimeImmutable $now): void {
		if ($this->lastPerformed === null) {
			$this->repertoireClass = self::REPERTOIRE_CLASS_NONE;
			return;
		}
		$lastPerformed = new \DateTimeImmutable($this->lastPerformed);
		if ($lastPerformed >= $now->modify("-{$currentYears} years")) {
			$this->repertoireClass = self::REPERTOIRE_CLASS_CURRENT;
		} elseif ($lastPerformed >= $now->modify("-{$dormantYears} years")) {
			$this->repertoireClass = self::REPERTOIRE_CLASS_DORMANT;
		} else {
			$this->repertoireClass = self::REPERTOIRE_CLASS_FORMER;
		}
	}

	/**
	 * Set GEMA IDs array.
	 *
	 * Handles JSON encoding when saving to database.
	 *
	 * @param list<string>|string|null $gemaIds Array or JSON string
	 * @psalm-suppress PossiblyUnusedMethod - Called by Entity framework and API
	 */
	public function setGemaIds(array|string|null $gemaIds): void {
		$content = is_string($gemaIds) ? $gemaIds : $this->encodeJsonArray($gemaIds, 'gemaIds');
		$this->setter('gemaIds', [$content]);
	}

	/**
	 * Decode a JSON field value.
	 *
	 * Handles both array (from API) and JSON string (from database) inputs.
	 *
	 * @param list<string>|string|null $value Array or JSON string
	 * @param string $fieldName Field name for error logging
	 * @return array|null Decoded array or null
	 */
	private function decodeJsonArray(array|string|null $value, string $fieldName): ?array {
		if (is_string($value)) {
			// Handle empty string as null
			if ($value === '') {
				return null;
			}
			/** @psalm-suppress MixedAssignment - json_decode with true returns mixed, but we check with is_array */
			$decoded = json_decode($value, true);
			// Check for JSON decode errors
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \RuntimeException("Failed to decode {$fieldName} JSON: " . json_last_error_msg());
			}
			return is_array($decoded) ? $decoded : null;
		}
		return $value;
	}

	/**
	 * Encode a JSON field value for database storage.
	 *
	 * @param array|null $value Array to encode
	 * @param string $fieldName Field name for error logging
	 * @return string|null JSON string or null
	 */
	private function encodeJsonArray(?array $value, string $fieldName): ?string {
		if ($value === null) {
			return null;
		}
		$encoded = json_encode($value);
		if ($encoded === false) {
			throw new \RuntimeException("Failed to encode {$fieldName} to JSON: " . json_last_error_msg());
		}

		return $encoded;
	}

	/**
	 * Get transient tags array.
	 *
	 * @return array|null
	 * @psalm-suppress PossiblyUnusedMethod - Called via jsonSerialize
	 */
	public function getTags(): ?array {
		return $this->tags;
	}

	/**
	 * Set transient tags array.
	 *
	 * @param list<string>|null $tags
	 */
	public function setTags(?array $tags): void {
		if ($tags === null) {
			$this->tags = null;
			return;
		}
		// ensure all values are strings
		$this->tags = array_map('strval', $tags);
	}

	/**
	 * Get transient score book info (id and index).
	 *
	 * @return array{id: int, index: int}|null
	 * @psalm-suppress PossiblyUnusedMethod - Called via jsonSerialize
	 */
	public function getScoreBook(): ?array {
		return $this->scoreBook;
	}

	/**
	 * Set transient score book info (id and index).
	 *
	 * @param array{id: int, index: int}|null $scoreBook
	 */
	public function setScoreBook(?array $scoreBook): void {
		$this->scoreBook = $scoreBook;
	}
}
