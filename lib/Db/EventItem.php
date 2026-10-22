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
 * One line of a concert programme or song list.
 *
 * An item points to a copy and its version when known, or only to the work
 * when the version is unknown. Imported lines keep the title and composer as
 * printed; `assignment` says whether the shelf mark is certain, ambiguous
 * (`candidates` lists the possible shelf marks) or not in the catalog.
 *
 * `counts` marks a documented performance by the choir itself: final
 * programme, not a draft, reserve piece or "encore if needed", and not sung by
 * another choir.
 *
 * @method int getEventId()
 * @method void setEventId(int $eventId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method int|null getCopyId()
 * @method void setCopyId(?int $copyId)
 * @method int|null getVersionId()
 * @method void setVersionId(?int $versionId)
 * @method int|null getWorkId()
 * @method void setWorkId(?int $workId)
 * @method string|null getTitleText()
 * @method void setTitleText(?string $titleText)
 * @method string|null getComposerText()
 * @method void setComposerText(?string $composerText)
 * @method string|null getParticipation()
 * @method void setParticipation(?string $participation)
 * @method string getAssignment()
 * @method void setAssignment(string $assignment)
 * @method string|null getCandidates()
 * @method void setCandidates(?string $candidates)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method bool|null getCounts()
 * @method void setCounts(?bool $counts)
 * @method bool|null getSung()
 * @method void setSung(?bool $sung)
 * @method bool|null getStruck()
 * @method void setStruck(?bool $struck)
 * @method bool|null getEncore()
 * @method void setEncore(?bool $encore)
 * @method int|null getDuration()
 * @method void setDuration(?int $duration)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 */
class EventItem extends Entity implements JsonSerializable {
	use FieldAccess;

	public const CERTAIN = 'certain';
	public const AMBIGUOUS = 'ambiguous';
	public const NOT_IN_CATALOG = 'not_in_catalog';

	protected int $eventId = 0;
	protected int $position = 0;
	protected ?int $copyId = null;
	protected ?int $versionId = null;
	protected ?int $workId = null;
	protected ?string $titleText = null;
	protected ?string $composerText = null;
	protected ?string $participation = null;
	protected string $assignment = self::CERTAIN;
	protected ?string $candidates = null;
	protected ?string $state = null;
	protected ?bool $counts = false;
	protected ?bool $sung = null;
	protected ?bool $struck = false;
	protected ?bool $encore = false;
	protected ?int $duration = null;
	protected ?string $note = null;

	public function __construct() {
		foreach (['eventId', 'position', 'copyId', 'versionId', 'workId', 'duration'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['titleText', 'composerText', 'participation', 'assignment', 'candidates', 'state', 'note'] as $field) {
			$this->addType($field, 'string');
		}
		foreach (['counts', 'sung', 'struck', 'encore'] as $field) {
			$this->addType($field, 'boolean');
		}
	}
}
