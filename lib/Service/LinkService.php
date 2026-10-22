<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\ExcerptLinkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Excerpt links: a shelf mark, printed measures, a voice as focus, verse,
 * tempo and a note, for one circle:
 *
 * - `internal`: logged-in holders of an archive role (milestone 0.3);
 * - `members`: the choir members (module member_links, milestone 0.7), for
 *   versions released for the singers' portal;
 * - `public`: everyone, without login (module public_links), for versions
 *   released for publication, without a note.
 *
 * The rights policy is checked when a link is made and again on every call,
 * so a downgraded version or an expired or revoked link shows nothing.
 * Choir members and the public get only the measures of the excerpt (see
 * SharedLinkService); a link for them therefore needs both measures, and
 * they must exist in the score.
 *
 * @psalm-type CircleOption = array{available: bool, allowed: bool, reason: ?string, playback: bool, practiceAudio: bool}
 */
class LinkService {
	public const MAX_DAYS = 183;
	private const CREATORS = [Roles::LIBRARIAN, Roles::CONDUCTOR, Roles::OPERATIONS];
	/** Fields a request may set when creating a link */
	private const FIELDS = ['measure_from', 'measure_to', 'voice', 'verse', 'tempo', 'note', 'task_id', 'expires', 'circle'];
	/** The module of each circle beyond the archive roles */
	public const CIRCLE_MODULES = [
		ExcerptLink::CIRCLE_MEMBERS => Modules::MEMBER_LINKS,
		ExcerptLink::CIRCLE_PUBLIC => Modules::PUBLIC_LINKS,
	];
	/** Reasons for the dialog (English source strings, translated by the controller) */
	public const SWITCHED_OFF = 'This kind of link is switched off. An administrator can switch it on in the settings of the music archive under "Modules".';
	public const NO_SCORE = 'This copy has no MusicXML file. Links for choir members and public links show the notes of a MusicXML file; the catalog librarian links one on the tab "Files".';

	public function __construct(
		private ExcerptLinkMapper $links,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private ArchiveFileMapper $files,
		private ScoreService $scores,
		private RightsPolicy $policy,
		private RightsService $rights,
		private AuditLog $audit,
		private Roles $roles,
		private IUserManager $userManager,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private Modules $modules,
	) {
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function create(int $copyId, array $data, string $uid): ExcerptLink {
		$values = Input::values($data);
		$circle = $values['circle'] ?? ExcerptLink::CIRCLE_INTERNAL;
		if (!is_string($circle) || !in_array($circle, ExcerptLink::CIRCLES, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Circle']);
		}
		$module = self::CIRCLE_MODULES[$circle] ?? null;
		if ($module !== null) {
			$this->modules->requireEnabled($module);
		}
		$this->roles->requireRole($uid, self::CREATORS);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		[$version, $score] = $this->load($copyId, $uid);

		$today = $this->rights->today($uid);
		$shared = $module !== null;
		if ($shared) {
			$denial = $this->shareDenial($circle, $version, $today);
			if ($denial !== null) {
				throw new AccessDeniedException($denial);
			}
			if ($score === null) {
				throw new InvalidInputException(self::NO_SCORE);
			}
			foreach (['measure_from', 'measure_to'] as $field) {
				$measure = $values[$field] ?? null;
				if ($measure === null || trim((string)$measure) === '') {
					throw new InvalidInputException('Please give the first and the last measure: choir members and the public only see these measures.');
				}
			}
			if ($circle === ExcerptLink::CIRCLE_PUBLIC && self::text($values['note'] ?? null, 2000) !== null) {
				throw new InvalidInputException('A public link has no note, because everyone could read it. Please leave the note empty.');
			}
		}
		$latest = (new \DateTimeImmutable($today))->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d');
		$expires = $values['expires'] ?? null;
		if ($expires === null || $expires === '') {
			$expires = $latest;
		} elseif (!is_string($expires) || !FieldValue::isDate($expires) || $expires < $today || $expires > $latest) {
			throw new InvalidInputException('The expiry date must lie between today and six months from now.');
		}

		$link = new ExcerptLink();
		$link->setToken($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
		$link->setCopyId($copyId);
		if ($score !== null) {
			$file = $this->files->find($score['fileId']);
			$link->setFileId($file->getId());
			$link->setFileEtag($file->getEtag());
		}
		foreach (['measure_from', 'measure_to'] as $field) {
			$value = $values[$field] ?? null;
			if ($value !== null && $value !== '' && preg_match('/^[0-9A-Za-z.]{1,16}$/', (string)$value) !== 1) {
				throw new InvalidInputException('Measure numbers may only contain digits, letters and dots.');
			}
			$link->setValue($field, $value === '' ? null : ($value === null ? null : (string)$value));
		}
		$link->setValue('voice', self::text($values['voice'] ?? null, 100));
		$link->setValue('note', self::text($values['note'] ?? null, 2000));
		foreach (['verse' => [1, 99], 'tempo' => [20, 300]] as $field => [$min, $max]) {
			$value = $values[$field] ?? null;
			if ($value !== null && $value !== '') {
				$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
				if ($int === false) {
					throw new InvalidInputException('"%s": value not allowed.', [$field === 'verse' ? 'Verse' : 'Tempo']);
				}
				$link->setValue($field, $int);
			}
		}
		$taskId = $values['task_id'] ?? null;
		if ($taskId !== null && $taskId !== '') {
			$taskId = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($taskId === false) {
				throw new InvalidInputException('"%s": value not allowed.', ['Task']);
			}
		}
		$link->setValue('task_id', $taskId === '' || $shared ? null : $taskId);
		if ($shared && $score !== null) {
			// The measures must exist: choir members and the public get only them.
			$content = $this->scores->content($score['fileId'], $uid)['content'];
			MusicXmlExcerpt::cut($this->scores->scoreText($content)['xml'], (string)$link->getMeasureFrom(), (string)$link->getMeasureTo());
		}
		$link->setCircle($circle);
		$link->setCreatedBy($uid);
		$link->setCreated($this->time->getTime());
		$link->setExpires($expires);
		$link = $this->links->insert($link);
		$operation = $this->audit->newOperation();
		$this->audit->record($operation, $uid, 'link', $link->getId(), 'created', 'copy_id', null, $copyId);
		if ($shared) {
			$this->audit->record($operation, $uid, 'link', $link->getId(), 'created', 'circle', null, $circle);
		}
		return $link;
	}

	/**
	 * What the dialog shows before a link for choir members or the public is
	 * made: whether the module is on, whether the rights allow it (if not,
	 * why and what to do), and whether the recipients can play the excerpt
	 * and make practice audio files.
	 *
	 * `reason` explains why the link is not possible, or else why it plays
	 * nothing; English source strings, translated by the controller.
	 *
	 * @return array{members: CircleOption, public: CircleOption}
	 */
	public function circleOptions(int $copyId, string $uid): array {
		$this->roles->requireRole($uid, self::CREATORS);
		[$version, $score] = $this->load($copyId, $uid);
		$today = $this->rights->today($uid);
		return [
			'members' => $this->circleOption(ExcerptLink::CIRCLE_MEMBERS, $version, $score !== null, $today),
			'public' => $this->circleOption(ExcerptLink::CIRCLE_PUBLIC, $version, $score !== null, $today),
		];
	}

	/**
	 * @return CircleOption
	 */
	private function circleOption(string $circle, WorkVersion $version, bool $hasScore, string $today): array {
		if (!$this->modules->isEnabled(self::CIRCLE_MODULES[$circle] ?? '')) {
			return ['available' => false, 'allowed' => false, 'reason' => self::SWITCHED_OFF, 'playback' => false, 'practiceAudio' => false];
		}
		$members = $circle === ExcerptLink::CIRCLE_MEMBERS;
		$denial = $this->shareDenial($circle, $version, $today) ?? ($hasScore ? null : self::NO_SCORE);
		$playDenial = $members ? $this->policy->memberPlayDenial($version, $today) : $this->policy->publicPlayDenial($version, $today);
		return [
			'available' => true,
			'allowed' => $denial === null,
			'reason' => $denial ?? $playDenial,
			'playback' => $denial === null && $playDenial === null,
			'practiceAudio' => $members && $denial === null && $playDenial === null && $this->modules->isEnabled(Modules::MEMBER_PRACTICE_AUDIO)
				&& $this->policy->memberPracticeAudioDenial($version, $today) === null,
		];
	}

	/**
	 * Why a version may not be shared with a circle beyond the archive roles,
	 * with the next step; null when it may.
	 */
	private function shareDenial(string $circle, WorkVersion $version, string $day): ?string {
		return $circle === ExcerptLink::CIRCLE_PUBLIC
			? $this->policy->publicViewDenial($version, $day)
			: $this->policy->memberViewDenial($version, $day);
	}

	/**
	 * The version of a copy and its score, after checking that the account
	 * may view it.
	 *
	 * @return array{0: WorkVersion, 1: array{fileId: int, fileName: string, canView: bool, canPlay: bool, reason: ?string, canExportAudio: bool, exportReason: ?string}|null}
	 */
	private function load(int $copyId, string $uid): array {
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		$denial = $this->policy->viewDenial($version, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		return [$version, $this->scores->describe($version, $this->files->findByCopy($copyId), $uid)];
	}

	/**
	 * Opens a link: checks expiry, revocation, the user's role and the rights
	 * policy; counts the call without recording who made it.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	public function resolve(string $token, string $uid): array {
		$this->roles->requireAccess($uid);
		try {
			$link = $this->links->findByToken($token);
		} catch (DoesNotExistException) {
			throw new UnknownLinkException('This link does not exist.');
		}
		try {
			$copy = $this->copies->find($link->getCopyId());
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new InvalidInputException('This link does not exist.');
		}
		if ($link->getRevoked() !== null) {
			throw new InvalidInputException('This link has been revoked.');
		}
		if ($link->getExpires() !== null && $link->getExpires() < $this->rights->today($uid)) {
			throw new InvalidInputException('This link has expired.');
		}
		$denial = $this->policy->viewDenial($version, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$this->links->countHit($link->getId());

		$changed = false;
		$fileId = $link->getFileId();
		if ($fileId !== null) {
			try {
				$changed = $this->files->find($fileId)->getEtag() !== $link->getFileEtag();
			} catch (DoesNotExistException) {
				$changed = true;
			}
		}
		$createdBy = $link->getCreatedBy();
		$data = $link->jsonSerialize();
		unset($data['created_by']);
		return $data + [
			'shelfmark' => $copy->getShelfmark(),
			'createdByName' => $createdBy === null ? null : $this->userManager->getDisplayName($createdBy),
			'changed' => $changed,
		];
	}

	public function revoke(int $linkId, string $uid): void {
		try {
			$link = $this->links->find($linkId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($link->getCreatedBy() !== $uid && !$this->roles->hasAnyRole($uid, [Roles::LIBRARIAN])) {
			throw new AccessDeniedException('Only the creator or the catalog librarian can revoke this link.');
		}
		if ($link->getRevoked() === null) {
			$link->setRevoked($this->time->getTime());
			$this->links->update($link);
			$this->audit->record($this->audit->newOperation(), $uid, 'link', $linkId, 'revoked');
		}
	}

	/**
	 * Active links (not revoked, not expired), optionally of one copy.
	 *
	 * @return list<ExcerptLink>
	 */
	public function active(?int $copyId, string $uid): array {
		$today = $this->rights->today($uid);
		return array_values(array_filter($this->links->findByCopy($copyId),
			fn (ExcerptLink $l): bool => $l->getRevoked() === null && ($l->getExpires() === null || $l->getExpires() >= $today)));
	}

	private static function text(string|int|float|bool|null $value, int $max): ?string {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if (!is_string($value) || mb_strlen($value) > $max) {
			throw new InvalidInputException('The text is too long.');
		}
		return $value;
	}
}
