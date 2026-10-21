<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db\Enum;

/**
 * Whether a score may be published.
 *
 * A score is shown publicly only if it is released for publication (FREE)
 * and its rights status allows it, see RightsStatus::publishableValues().
 */
enum PublicationStatus: string {
	/** Released for publication */
	case FREE = 'free';
	/** Not decided yet */
	case TO_CLARIFY = 'to_clarify';
	/** Must not be published, optionally until a given date */
	case BLOCKED = 'blocked';
	/** Internal preservation copy of a protected work: no player, files only for editors */
	case INTERNAL_COPY = 'internal_copy';
}
