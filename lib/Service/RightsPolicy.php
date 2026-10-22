<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\WorkVersion;

/**
 * Decides what may be done with the score of a version, based on its rights.
 *
 * A rendered excerpt counts as much as the whole sheet, and playback amounts
 * to practice audio. Therefore:
 * - Internal preservation copies may only be viewed (silently) by the roles
 *   that check transcriptions: catalog librarian and conductor. They are
 *   never played.
 * - Playback is offered for public domain versions and for versions with a
 *   valid license covering practice audio.
 *
 * Messages are English source strings; callers translate them.
 */
class RightsPolicy {
	/** Roles allowed to view internal preservation copies */
	public const PRESERVATION_COPY_VIEWERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];

	public function __construct(
		private Roles $roles,
		private RightsService $rights,
		private LicenseMapper $licenses,
	) {
	}

	/**
	 * @return string|null null when viewing is allowed, otherwise the reason
	 */
	public function viewDenial(WorkVersion $version, string $uid): ?string {
		if (!$this->roles->hasAccess($uid)) {
			return 'You have no access to the music archive.';
		}
		if ($version->getPreservationCopy() === true && !$this->roles->hasAnyRole($uid, self::PRESERVATION_COPY_VIEWERS)) {
			return 'Internal preservation copy: only the catalog librarian and the conductor may view it.';
		}
		return null;
	}

	/**
	 * @return string|null null when playback is allowed, otherwise the reason
	 */
	public function playDenial(WorkVersion $version, string $uid): ?string {
		$viewDenial = $this->viewDenial($version, $uid);
		if ($viewDenial !== null) {
			return $viewDenial;
		}
		if ($version->getPreservationCopy() === true) {
			return 'Internal preservation copy: no playback.';
		}
		if ($version->getRightsStatus() === 'public_domain') {
			return null;
		}
		$day = $this->rights->today($uid);
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'practice_audio', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'protected'
			? 'Protected version: playback needs a license for practice audio.'
			: 'Rights not clarified: no playback until the rights status is known.';
	}
}
