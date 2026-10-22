<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Db\Enum;

/**
 * What a share points to.
 */
enum ShareTargetType: string {
	case SCORE = 'score';
	case FOLDER_COLLECTION = 'folder_collection';
}
