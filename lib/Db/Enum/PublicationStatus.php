<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Db\Enum;

/**
 * Publication status of a score, decided independently of its rights status.
 */
enum PublicationStatus: string {
	/** Approved for publication on the public page */
	case APPROVED = 'approved';
	/** Not decided yet */
	case TO_CLARIFY = 'to_clarify';
	/** Must not be published, optionally until a given date */
	case BLOCKED = 'blocked';
	/**
	 * Copy kept only to preserve the work, e.g. of a protected work.
	 * Its notes are neither rendered nor played back, and only editors may download the file.
	 */
	case INTERNAL_PRESERVATION_COPY = 'internal_preservation_copy';
}
