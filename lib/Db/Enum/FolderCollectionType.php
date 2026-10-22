<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Db\Enum;

enum FolderCollectionType: string {
	case ALPHABETICAL = 'alphabetical';
	case INDEXED = 'indexed';
}
