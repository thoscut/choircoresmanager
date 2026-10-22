<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\ExcerptLinkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Internal excerpt links: a shelf mark, printed measures, a voice as focus,
 * verse, tempo and a note. Only logged-in holders of an archive role can open
 * them; the rights policy is checked again on every call, so a downgraded
 * version or an expired or revoked link shows nothing.
 *
 * Links for singers or the public are a later stage and need a decision of
 * the board (see the roadmap).
 */
class LinkService {
	public const MAX_DAYS = 183;
	private const CREATORS = [Roles::LIBRARIAN, Roles::CONDUCTOR, Roles::OPERATIONS];
	/** Fields a request may set when creating a link */
	private const FIELDS = ['measure_from', 'measure_to', 'voice', 'verse', 'tempo', 'note', 'task_id', 'expires'];

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
	) {
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function create(int $copyId, array $data, string $uid): ExcerptLink {
		$this->roles->requireRole($uid, self::CREATORS);
		$values = Input::values($data);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		$denial = $this->policy->viewDenial($version, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$score = $this->scores->describe($version, $this->files->findByCopy($copyId), $uid);

		$today = $this->rights->today($uid);
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
		$link->setValue('task_id', $taskId === '' ? null : $taskId);
		$link->setCircle(ExcerptLink::CIRCLE_INTERNAL);
		$link->setCreatedBy($uid);
		$link->setCreated($this->time->getTime());
		$link->setExpires($expires);
		$link = $this->links->insert($link);
		$this->audit->record($this->audit->newOperation(), $uid, 'link', $link->getId(), 'created', 'copy_id', null, $copyId);
		return $link;
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
			throw new InvalidInputException('Not found.');
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
