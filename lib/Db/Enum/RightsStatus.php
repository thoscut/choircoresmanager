<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db\Enum;

/**
 * Publication rights status of a score.
 *
 * Only scores with status FREE (e.g. public domain works) may be shown publicly.
 */
enum RightsStatus: string {
	case FREE = 'free';
	case UNCLEAR = 'unclear';
	case RESTRICTED = 'restricted';
}
