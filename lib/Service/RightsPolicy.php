<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
 * - Practice audio files (WAV or MIDI made from the score view) are copies
 *   that leave the app. Only the catalog librarian and the conductor make
 *   them, and only for confirmed public domain versions or versions with a
 *   valid license covering practice audio; never for preservation copies.
 * - Links for choir members (milestone 0.7) need the release for the
 *   digital provision to members, i.e. the singers' portal (confirmed public
 *   domain or a license for the portal). Playback and practice audio follow
 *   the same rules as above; choir members make practice audio of a shared
 *   excerpt themselves.
 * - Public links need the release for publication (publication status
 *   "free", set by the catalog librarian) and confirmed public domain or a
 *   license for online publication; playback as above.
 *
 * Messages are English source strings; callers translate them. Reasons for
 * the people who share say what to do next.
 */
class RightsPolicy {
	/** Roles allowed to view internal preservation copies */
	public const PRESERVATION_COPY_VIEWERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];
	/** Roles allowed to make practice audio files */
	public const PRACTICE_AUDIO_MAKERS = [Roles::LIBRARIAN, Roles::CONDUCTOR];

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
		if ($version->getPreservationCopy() === true && !$this->mayViewPreservationCopies($uid)) {
			return 'Internal preservation copy: only the catalog librarian and the conductor may view it.';
		}
		return null;
	}

	/**
	 * Whether the account holds a role that may view internal preservation
	 * copies (the API for agents also leaves them out of its lists for the
	 * other roles).
	 */
	public function mayViewPreservationCopies(string $uid): bool {
		return $this->roles->hasAnyRole($uid, self::PRESERVATION_COPY_VIEWERS);
	}

	/**
	 * @return string|null null when playback is allowed, otherwise the reason
	 */
	public function playDenial(WorkVersion $version, string $uid): ?string {
		return $this->viewDenial($version, $uid) ?? $this->playRightsDenial($version, $this->rights->today($uid));
	}

	/**
	 * Whether practice audio files may be made from the score: everything
	 * playback needs (see playDenial), one of the roles that make them, and a
	 * confirmed public domain status unless a license covers practice audio.
	 * Playing inside the app needs no confirmation; a file that leaves the
	 * app does.
	 *
	 * @return string|null null when the export is allowed, otherwise the reason
	 */
	public function practiceAudioDenial(WorkVersion $version, string $uid): ?string {
		$playDenial = $this->playDenial($version, $uid);
		if ($playDenial !== null) {
			return $playDenial;
		}
		if (!$this->roles->hasAnyRole($uid, self::PRACTICE_AUDIO_MAKERS)) {
			return 'Practice audio files are made by the catalog librarian or the conductor.';
		}
		return $this->practiceAudioRightsDenial($version, $this->rights->today($uid));
	}

	/**
	 * Whether a version may be shared with choir members through a link for
	 * them: it must be released for the digital provision to members (the
	 * singers' portal), never an internal preservation copy. Checked when the
	 * link is made and on every call.
	 *
	 * @param string $day today (YYYY-MM-DD), for the validity of licenses
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function memberViewDenial(WorkVersion $version, string $day): ?string {
		if ($version->getPreservationCopy() === true) {
			return 'Internal preservation copy: it is never shared with choir members.';
		}
		if ($this->rights->portalDenial($version, $this->licenses->findByVersion($version->getId()), $day) === null) {
			return null;
		}
		return $version->getRightsStatus() === 'public_domain'
			? 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then the version can be shared with choir members.'
			: 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Playback of a link for choir members: the release for members and the
	 * rights for playback (see playDenial).
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function memberPlayDenial(WorkVersion $version, string $day): ?string {
		return $this->memberViewDenial($version, $day) ?? $this->playRightsDenial($version, $day);
	}

	/**
	 * Practice audio files that choir members make of a link for them: what
	 * playback needs plus the rights for practice audio files (see
	 * practiceAudioDenial); instead of the role check, the link is for
	 * choir members.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function memberPracticeAudioDenial(WorkVersion $version, string $day): ?string {
		return $this->memberPlayDenial($version, $day) ?? $this->practiceAudioRightsDenial($version, $day);
	}

	/**
	 * Whether an excerpt of a version may be shown to everyone through a
	 * public link: released for publication by the catalog librarian, and
	 * confirmed public domain or a valid license for online publication;
	 * never an internal preservation copy.
	 *
	 * @return string|null null when allowed, otherwise the reason with the next step
	 */
	public function publicViewDenial(WorkVersion $version, string $day): ?string {
		if ($version->getPreservationCopy() === true) {
			return 'Internal preservation copy: it is never published.';
		}
		if ($version->getPublicationStatus() !== 'free') {
			return 'Not released for publication. The catalog librarian sets the publication status of the version to "Released for publication".';
		}
		if ($version->getRightsStatus() === 'public_domain' && $version->isConfirmed()) {
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'online_publication', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'public_domain'
			? 'Public domain is not confirmed yet. The catalog librarian confirms it on the tab "Rights"; then a public link can be made.'
			: 'A public link needs confirmed public domain or a valid license for online publication. The catalog librarian records it on the tab "Rights".';
	}

	/**
	 * Playback on the page of a public link.
	 *
	 * @return string|null null when allowed, otherwise the reason
	 */
	public function publicPlayDenial(WorkVersion $version, string $day): ?string {
		return $this->publicViewDenial($version, $day) ?? $this->playRightsDenial($version, $day);
	}

	/**
	 * The rights part of playDenial: public domain or a license for practice
	 * audio; never a preservation copy. No role check.
	 */
	private function playRightsDenial(WorkVersion $version, string $day): ?string {
		if ($version->getPreservationCopy() === true) {
			return 'Internal preservation copy: no playback.';
		}
		if ($version->getRightsStatus() === 'public_domain') {
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'practice_audio', $day)) {
			return null;
		}
		return $version->getRightsStatus() === 'protected'
			? 'Protected version: playback needs a license for practice audio.'
			: 'Rights not clarified: no playback until the rights status is known.';
	}

	/**
	 * The rights part of practiceAudioDenial, once playback is allowed: a
	 * public domain version must be confirmed unless a license covers
	 * practice audio. No role check.
	 */
	private function practiceAudioRightsDenial(WorkVersion $version, string $day): ?string {
		if ($version->getRightsStatus() !== 'public_domain' || $version->isConfirmed()) {
			// Protected or open: playback was allowed by a license for practice audio.
			return null;
		}
		if ($this->rights->hasLicense($this->licenses->findByVersion($version->getId()), 'practice_audio', $day)) {
			return null;
		}
		return 'Public domain is not confirmed yet: practice audio files need a confirmed rights status or a license for practice audio.';
	}
}
