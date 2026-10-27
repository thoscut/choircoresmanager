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
use OCA\Notenarchiv\Db\Work;
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
 * Opens links for the circles beyond the archive roles (milestone 0.7):
 * the choir members (module member_links) and the public (module
 * public_links). Links are made in LinkService.
 *
 * Choir members and the public never get the score file: the server reads
 * it through the account that configured the archive folder (only inside
 * that folder, like the background jobs), unpacks `.mxl` and sends only the
 * content for the view: the measures of the excerpt (MusicXmlExcerpt::cut)
 * or, for a link without measures, the whole piece with its full playing
 * order (MusicXmlExcerpt::whole). A link may also offer the PDF scan chosen
 * when it was made, read the same way; it needs the same release as the
 * notes. The rights policy is checked on every call; calls of the score are
 * counted without recording who made them.
 *
 * What they see: title and composer of the work, the measures (null for the
 * whole piece), voice, verse and tempo of the link, for choir members also
 * its note. Never a shelf mark, an account name or an internal note of the
 * catalog.
 *
 * @psalm-type MemberLink = array{token: string, title: string, composer: string, measure_from: ?string, measure_to: ?string,
 *     voice: ?string, verse: ?int, tempo: ?int, note: ?string, expires: ?string, canPlay: bool, canExportAudio: bool, reason: ?string,
 *     scan: bool}
 * @psalm-type PublicExcerpt = array{title: string, composer: string, measureFrom: ?string, measureTo: ?string, voice: ?string,
 *     verse: ?int, tempo: ?int, canPlay: bool, scan: bool, xml: string}
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
	 * string) says why the piece cannot be played; `scan` whether the link
	 * offers the scan.
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
			if ($this->policy->memberViewDenial($version, $today, $copy) !== null) {
				continue;
			}
			$playDenial = $this->policy->memberPlayDenial($version, $today, $copy);
			$rows[] = [
				'token' => $link->getToken(),
				'title' => (string)$work->getTitle(),
				'composer' => (string)$work->getComposer(),
				'measure_from' => $link->getMeasureFrom(),
				'measure_to' => $link->getMeasureTo(),
				'voice' => $link->getVoice(),
				'verse' => $link->getVerse(),
				'tempo' => $link->getTempo(),
				'note' => $link->getNote(),
				'expires' => $link->getExpires(),
				'canPlay' => $playDenial === null,
				'canExportAudio' => $playDenial === null && $this->modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO)
					&& $this->policy->memberPracticeAudioDenial($version, $today, $copy) === null,
				'reason' => $playDenial,
				'scan' => $link->getScanFileId() !== null,
			];
		}
		return $rows;
	}

	/**
	 * The measures of a link for choir members, or the whole piece, as
	 * MusicXML; counts the call.
	 *
	 * @throws UserFacingException
	 */
	public function memberScore(string $token, string $uid): string {
		['link' => $link] = $this->memberLink($token, $uid);
		$xml = $this->score($link);
		$this->links->countHit($link->getId());
		return $xml;
	}

	/**
	 * The scan a link for choir members offers, after the same checks as
	 * the score.
	 *
	 * @throws UserFacingException
	 */
	public function memberScan(string $token, string $uid): File {
		return $this->scan($this->memberLink($token, $uid)['link']);
	}

	/**
	 * An active link for choir members with its copy and version, after
	 * checking the account and the rights; for the score, the scan and
	 * practice audio files.
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
			throw new NotFoundException('This piece is no longer shared with choir members.');
		}
		if ($this->policy->memberViewDenial($version, $today, $copy) !== null) {
			throw new AccessDeniedException('This piece is no longer shared with choir members.');
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
		$open = $this->publicLink($token);
		if ($open === null) {
			return null;
		}
		['link' => $link, 'copy' => $copy, 'version' => $version, 'work' => $work, 'today' => $today] = $open;
		try {
			$xml = $this->score($link);
		} catch (UserFacingException) {
			return null;
		}
		$this->links->countHit($link->getId());
		return [
			'title' => (string)$work->getTitle(),
			'composer' => (string)$work->getComposer(),
			'measureFrom' => $link->getMeasureFrom(),
			'measureTo' => $link->getMeasureTo(),
			'voice' => $link->getVoice(),
			'verse' => $link->getVerse(),
			'tempo' => $link->getTempo(),
			'canPlay' => $this->policy->publicPlayDenial($version, $today, $copy) === null,
			'scan' => $link->getScanFileId() !== null,
			'xml' => $xml,
		];
	}

	/**
	 * The scan a public link offers, or null in every case in which the page
	 * of the link is refused, and when the link offers no scan or it cannot
	 * be read: the caller answers all of them with the same 404.
	 */
	public function publicScan(string $token): ?File {
		$open = $this->publicLink($token);
		if ($open === null) {
			return null;
		}
		try {
			return $this->scan($open['link']);
		} catch (UserFacingException) {
			return null;
		}
	}

	/**
	 * A public link that may be shown today, with its version and work; null
	 * otherwise.
	 *
	 * @return array{link: ExcerptLink, copy: Copy, version: WorkVersion, work: Work, today: string}|null
	 */
	private function publicLink(string $token): ?array {
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
			|| $this->policy->publicViewDenial($version, $today, $copy) !== null) {
			return null;
		}
		return ['link' => $link, 'copy' => $copy, 'version' => $version, 'work' => $work, 'today' => $today];
	}

	/**
	 * What a link shows of the current score of its copy: the measures of
	 * the excerpt, or the whole piece for a link without measures.
	 *
	 * @throws UserFacingException
	 */
	private function score(ExcerptLink $link): string {
		$notAvailable = new NotFoundException('The score of this link is not available at the moment.');
		$file = ScoreService::pick($this->files->findByCopy($link->getCopyId()));
		if ($file === null) {
			throw $notAvailable;
		}
		$content = UserFiles::content($this->archiveNode($file->getFileId(), $notAvailable), ScoreService::MAX_BYTES, $notAvailable);
		if (!$this->scores->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		$xml = $this->scores->scoreText($content)['xml'];
		$from = $link->getMeasureFrom();
		$to = $link->getMeasureTo();
		return $from === null || $to === null ? MusicXmlExcerpt::whole($xml) : MusicXmlExcerpt::cut($xml, $from, $to);
	}

	/**
	 * The scan of a link: still a PDF of its copy (role `score_pdf`), read
	 * through the account of the archive folder.
	 *
	 * @throws UserFacingException
	 */
	private function scan(ExcerptLink $link): File {
		$notAvailable = new NotFoundException('The scan of this link is not available at the moment.');
		$id = $link->getScanFileId();
		if ($id === null) {
			throw $notAvailable;
		}
		try {
			$file = $this->files->find($id);
		} catch (DoesNotExistException) {
			throw $notAvailable;
		}
		if ($file->getCopyId() !== $link->getCopyId() || $file->getRole() !== 'score_pdf' || $file->getFound() === false) {
			throw $notAvailable;
		}
		$node = $this->archiveNode($file->getFileId(), $notAvailable);
		if (!ScanService::isPdf($node)) {
			throw $notAvailable;
		}
		return $node;
	}

	/**
	 * A file of the archive folder, read through the account that configured
	 * it and only inside it.
	 *
	 * @throws NotFoundException
	 */
	private function archiveNode(int $fileId, NotFoundException $notAvailable): File {
		$account = $this->settings->archiveInfo()['user'];
		if ($account === '') {
			throw $notAvailable;
		}
		$node = $this->settings->archiveFolder($account)->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			throw $notAvailable;
		}
		return $node;
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
