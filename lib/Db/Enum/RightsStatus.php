<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db\Enum;

/**
 * Copyright status of a score.
 *
 * This records what is known about the rights. Whether a score is published
 * is decided separately, see PublicationStatus.
 */
enum RightsStatus: string {
	/** The work is in the public domain */
	case PUBLIC_DOMAIN = 'public_domain';
	/** The rights holder has granted a license */
	case LICENSED = 'licensed';
	/** The work is protected by copyright */
	case PROTECTED = 'protected';
	/** The rights have not been determined yet */
	case OPEN = 'open';

	/**
	 * Rights statuses that allow publishing a score.
	 *
	 * @return list<string>
	 */
	public static function publishableValues(): array {
		return [self::PUBLIC_DOMAIN->value, self::LICENSED->value];
	}
}
