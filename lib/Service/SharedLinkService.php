<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\ExcerptLinkMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;

/**
 * Opens excerpt links for the circles beyond the archive roles (milestone
 * 0.7): the choir members (module member_links) and the public (module
 * public_links). Links are made in LinkService.
 *
 * Choir members and the public never get the score file: the server reads
 * it through the account that configured the archive folder (only inside
 * that folder, like the background jobs), cuts the measures of the excerpt
 * (MusicXmlExcerpt) and sends only them. The rights policy is checked on
 * every call; calls are counted without recording who made them.
 *
 * What they see: title and composer of the work, the measures, voice,
 * verse and tempo of the link, for choir members also its note. Never a
 * shelf mark, an account name or an internal note of the catalog.
 *
 * @psalm-type MemberLink = array{token: string, title: string, composer: string, measure_from: string, measure_to: string,
 *     voice: ?string, verse: ?int, tempo: ?int, note: ?string, expires: ?string, canPlay: bool, canExportAudio: bool, reason: ?string}
 * @psalm-type PublicExcerpt = array{title: string, composer: string, measureFrom: string, measureTo: string, voice: ?string,
 *     verse: ?int, tempo: ?int, canPlay: bool, xml: string}
 */
class SharedLinkService {
	public function __construct(
		private ExcerptLinkMapper $links,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ArchiveFileMapper $files,
		private ScoreService $scores,
		private RightsPolicy $policy,
		private RightsService $rights,
		private Roles $roles,
		private Modules $modules,
		private AppSettings $settings,
	) {
	}

	/**
	 * The links for choir members that are active and still allowed, newest
	 * first: what the page "Shared with me" lists. `reason` (English source
	 * string) says why the excerpt cannot be played.
	 *
	 * @return list<MemberLink>
	 * @throws AccessDeniedException when the account is no choir member
	 */
	public function forMember(string $uid): array {
		$this->requireMember($uid);
		$today = $this->rights->today($uid);
		$rows = [];
		foreach ($this->links->findUnrevokedByCircle(ExcerptLink::CIRCLE_MEMBERS) as $link) {
			if (self::expired($link, $today)) {
				continue;
			}
			try {
				$copy = $this->copies->find($link->getCopyId());
				$version = $this->versions->find($copy->getVersionId());
				$work = $this->works->find($version->getWorkId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($this->policy->memberViewDenial($version, $today) !== null) {
				continue;
			}
			$playDenial = $this->policy->memberPlayDenial($version, $today);
			$rows[] = [
				'token' => $link->getToken(),
				'title' => (string)$work->getTitle(),
				'composer' => (string)$work->getComposer(),
				'measure_from' => (string)$link->getMeasureFrom(),
				'measure_to' => (string)$link->getMeasureTo(),
				'voice' => $link->getVoice(),
				'verse' => $link->getVerse(),
				'tempo' => $link->getTempo(),
				'note' => $link->getNote(),
				'expires' => $link->getExpires(),
				'canPlay' => $playDenial === null,
				'canExportAudio' => $playDenial === null && $this->modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO)
					&& $this->policy->memberPracticeAudioDenial($version, $today) === null,
				'reason' => $playDenial,
			];
		}
		return $rows;
	}

	/**
	 * The measures of a link for choir members as MusicXML; counts the call.
	 *
	 * @throws UserFacingException
	 */
	public function memberScore(string $token, string $uid): string {
		['link' => $link] = $this->memberLink($token, $uid);
		$xml = $this->excerpt($link);
		$this->links->countHit($link->getId());
		return $xml;
	}

	/**
	 * An active link for choir members with its copy and version, after
	 * checking the account and the rights; for the score and for practice
	 * audio files.
	 *
	 * @return array{link: ExcerptLink, copy: Copy, version: WorkVersion}
	 * @throws UserFacingException
	 */
	public function memberLink(string $token, string $uid): array {
		$this->requireMember($uid);
		try {
			$link = $this->links->findByToken($token);
		} catch (DoesNotExistException) {
			throw new UnknownLinkException('This link does not exist.');
		}
		if ($link->getCircle() !== ExcerptLink::CIRCLE_MEMBERS) {
			// Internal and public links are not opened here; the same answer as for an unknown token.
			throw new UnknownLinkException('This link does not exist.');
		}
		if ($link->getRevoked() !== null) {
			throw new InvalidInputException('This link has been revoked.');
		}
		$today = $this->rights->today($uid);
		if (self::expired($link, $today)) {
			throw new InvalidInputException('This link has expired.');
		}
		try {
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('This excerpt is no longer shared with choir members.');
		}
		if ($this->policy->memberViewDenial($version, $today) !== null) {
			throw new AccessDeniedException('This excerpt is no longer shared with choir members.');
		}
		return ['link' => $link, 'copy' => $copy, 'version' => $version];
	}

	/**
	 * What the page of a public link shows, or null for a token that is
	 * unknown, of another circle, revoked or expired, or whose version is no
	 * longer released for publication: the caller answers all of them with
	 * the same 404. Counts the call.
	 *
	 * @return PublicExcerpt|null
	 */
	public function publicExcerpt(string $token): ?array {
		try {
			$link = $this->links->findByToken($token);
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
			$work = $this->works->find($version->getWorkId());
		} catch (DoesNotExistException) {
			return null;
		}
		$today = $this->rights->today(null);
		if ($link->getCircle() !== ExcerptLink::CIRCLE_PUBLIC || $link->getRevoked() !== null || self::expired($link, $today)
			|| $this->policy->publicViewDenial($version, $today) !== null) {
			return null;
		}
		try {
			$xml = $this->excerpt($link);
		} catch (UserFacingException) {
			return null;
		}
		$this->links->countHit($link->getId());
		return [
			'title' => (string)$work->getTitle(),
			'composer' => (string)$work->getComposer(),
			'measureFrom' => (string)$link->getMeasureFrom(),
			'measureTo' => (string)$link->getMeasureTo(),
			'voice' => $link->getVoice(),
			'verse' => $link->getVerse(),
			'tempo' => $link->getTempo(),
			'canPlay' => $this->policy->publicPlayDenial($version, $today) === null,
			'xml' => $xml,
		];
	}

	/**
	 * The measures of a link, cut from the current score of its copy, read
	 * through the account of the archive folder and only inside it.
	 *
	 * @throws UserFacingException
	 */
	private function excerpt(ExcerptLink $link): string {
		$notAvailable = new NotFoundException('The score of this excerpt is not available at the moment.');
		$file = ScoreService::pick($this->files->findByCopy($link->getCopyId()));
		$account = $this->settings->archiveInfo()['user'];
		if ($file === null || $account === '') {
			throw $notAvailable;
		}
		$node = $this->settings->archiveFolder($account)->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			throw $notAvailable;
		}
		$content = UserFiles::content($node, ScoreService::MAX_BYTES, $notAvailable);
		if (!$this->scores->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		return MusicXmlExcerpt::cut($this->scores->scoreText($content)['xml'], (string)$link->getMeasureFrom(), (string)$link->getMeasureTo());
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function requireMember(string $uid): void {
		if (!$this->roles->isMember($uid)) {
			throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
		}
	}

	private static function expired(ExcerptLink $link, string $today): bool {
		return $link->getExpires() !== null && $link->getExpires() < $today;
	}
}
