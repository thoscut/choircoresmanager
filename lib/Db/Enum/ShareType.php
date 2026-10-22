<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Db\Enum;

/**
 * How a score or folder collection is shared.
 */
enum ShareType: string {
	/** Secret link that works without login, showing freely available scores only */
	case LINK = 'link';
	/** Visible to the members of a group */
	case GROUP = 'group';
}
