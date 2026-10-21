<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Db\Enum;

/**
 * Rights status of a score: may the club provide the work digitally?
 *
 * The status is an assessment; whether a human has confirmed it is stored
 * separately (rights confirmation date and initials). Whether a score is
 * actually published is decided by its PublicationStatus.
 */
enum RightsStatus: string {
	/** The work is in the public domain */
	case PUBLIC_DOMAIN = 'public_domain';
	/** A licence explicitly allows the digital provision */
	case LICENSED = 'licensed';
	/** The work is protected and no licence allows the digital provision */
	case PROTECTED = 'protected';
	/** Not yet assessed or unresolved; treated like a protected work */
	case OPEN = 'open';
}
