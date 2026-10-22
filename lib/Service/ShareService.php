<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCA\OrchestraScoresManager\Db\Enum\PublicationStatus;
use OCA\OrchestraScoresManager\Db\Enum\ShareTargetType;
use OCA\OrchestraScoresManager\Db\Enum\ShareType;
use OCA\OrchestraScoresManager\Db\FolderCollectionMapper;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreBookScoreLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreFolderCollectionLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Db\Share;
use OCA\OrchestraScoresManager\Db\ShareMapper;
use OCA\OrchestraScoresManager\ResponseDefinitions;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Shares of scores and folder collections.
 *
 * Link shares work without login and only ever expose scores that may be shown
 * publicly (filtered in SQL) with a whitelisted set of fields. Group shares are
 * shown to members of the group and include all scores, but files of internal
 * preservation copies stay with the editors.
 *
 * Like PublicScoreService, this service does not reuse the other services or
 * policies, which require a login.
 *
 * @psalm-import-type OrchestraScoresManagerShare from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerShareGroup from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerSharedTarget from ResponseDefinitions
 */
class ShareService {
	public const TOKEN_LENGTH = 32;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly ShareMapper $shareMapper,
		private readonly ScoreMapper $scoreMapper,
		private readonly FolderCollectionMapper $folderCollectionMapper,
		private readonly ScoreFolderCollectionLinkMapper $linkMapper,
		private readonly ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper,
		private readonly PermissionService $permissionService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ISecureRandom $secureRandom,
		private readonly ITimeFactory $timeFactory,
		private readonly MusicXmlService $musicXmlService,
		private readonly PdfService $pdfService,
		private IL10N $l,
	) {
	}

	/**
	 * Get all shares of a score or folder collection. Editors only.
	 *
	 * @return list<OrchestraScoresManagerShare>
	 * @throws PermissionDeniedException
	 * @throws \InvalidArgumentException if the target type is invalid
	 * @throws Exception
	 */
	public function getSharesForTarget(string $targetType, int $targetId): array {
		$this->assertCanEdit();
		$this->parseTargetType($targetType);
		return array_map(fn (Share $share) => $this->serializeShare($share), $this->shareMapper->findForTarget($targetType, $targetId));
	}

	/**
	 * Get all shares with the titles of the shared items. Editors only.
	 *
	 * @return list<OrchestraScoresManagerShare>
	 * @throws PermissionDeniedException
	 * @throws Exception
	 */
	public function getAllShares(): array {
		$this->assertCanEdit();
		return array_map(fn (Share $share) => $this->serializeShare($share), $this->shareMapper->findAll());
	}

	/**
	 * Create a link or group share. Editors only.
	 *
	 * @return OrchestraScoresManagerShare
	 * @throws PermissionDeniedException
	 * @throws \InvalidArgumentException if the input is invalid or the target does not exist
	 * @throws Exception
	 */
	public function createShare(string $shareType, string $targetType, int $targetId, ?string $groupId = null, ?string $label = null): array {
		$this->assertCanEdit();
		$type = ShareType::tryFrom($shareType);
		if ($type === null) {
			throw new \InvalidArgumentException($this->l->t('Invalid share type'));
		}
		$target = $this->parseTargetType($targetType);
		$this->getTargetTitle($target, $targetId);

		$share = new Share();
		$share->setShareType($type->value);
		$share->setTargetType($target->value);
		$share->setTargetId($targetId);
		$share->setCreatedBy($this->userSession->getUser()?->getUID() ?? '');
		$share->setCreatedAt($this->timeFactory->getTime());

		$label = $label !== null ? mb_substr(trim($label), 0, 255) : null;
		$share->setLabel($label === '' ? null : $label);

		if ($type === ShareType::LINK) {
			$share->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		} else {
			if ($groupId === null || !$this->groupManager->groupExists($groupId)) {
				throw new \InvalidArgumentException($this->l->t('Group not found'));
			}
			foreach ($this->shareMapper->findForTarget($target->value, $targetId) as $existing) {
				if ($existing->getShareType() === ShareType::GROUP->value && $existing->getGroupId() === $groupId) {
					throw new \InvalidArgumentException($this->l->t('Already shared with this group'));
				}
			}
			$share->setGroupId($groupId);
		}

		return $this->serializeShare($this->shareMapper->insert($share));
	}

	/**
	 * Delete a share. Editors only.
	 *
	 * @throws PermissionDeniedException
	 * @throws \InvalidArgumentException if the share does not exist
	 * @throws Exception
	 */
	public function deleteShare(int $id): void {
		$this->assertCanEdit();
		try {
			$share = $this->shareMapper->find($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new \InvalidArgumentException($this->l->t('Share not found'));
		}
		$this->shareMapper->delete($share);
	}

	/**
	 * List the groups a share can be created for. Editors only.
	 *
	 * @return list<OrchestraScoresManagerShareGroup>
	 * @throws PermissionDeniedException
	 */
	public function getGroups(): array {
		$this->assertCanEdit();
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
		}
		usort($groups, fn (array $a, array $b): int => strcasecmp((string)$a['displayName'], (string)$b['displayName']));
		return $groups;
	}

	/**
	 * Get everything shared with the groups of the current user, with all scores.
	 *
	 * @return list<OrchestraScoresManagerSharedTarget>
	 * @throws PermissionDeniedException if nobody is logged in
	 * @throws Exception
	 */
	public function getSharedWithCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new PermissionDeniedException($this->l->t('Insufficient permissions'));
		}
		$groupIds = array_map('strval', $this->groupManager->getUserGroupIds($user));

		$targets = [];
		$seen = [];
		foreach ($this->shareMapper->findGroupSharesForGroups($groupIds) as $share) {
			$key = $share->getTargetType() . ':' . $share->getTargetId();
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			try {
				$targets[] = $this->resolveShare($share, false);
			} catch (\InvalidArgumentException) {
				// The target was deleted in the meantime
			}
		}
		return $targets;
	}

	/**
	 * Get a link share for anonymous visitors, with public scores only.
	 *
	 * @return OrchestraScoresManagerSharedTarget
	 * @throws \InvalidArgumentException if the token is unknown or the target does not exist
	 */
	public function getLinkShare(string $token): array {
		return $this->resolveShare($this->findLinkShare($token), true);
	}

	/**
	 * Read a file of a score included in a link share.
	 *
	 * @param string $kind 'musicxml' or 'pdf'
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the token is unknown or the score is not part of the share or not public
	 * @throws Exceptions\NotFoundException if the score has no readable, valid file of this kind
	 */
	public function getLinkShareFile(string $token, int $scoreId, string $kind): array {
		$share = $this->findLinkShare($token);
		if (!in_array($scoreId, $this->getScoreIdsForShare($share), true)) {
			throw new \InvalidArgumentException('Score not found');
		}
		try {
			$scores = $this->scoreMapper->findMultipleWithoutTransients([$scoreId], true);
		} catch (Exception) {
			throw new \InvalidArgumentException('Score not found');
		}
		$score = $scores[0] ?? null;
		if ($score === null) {
			throw new \InvalidArgumentException('Score not found');
		}
		return match ($kind) {
			'musicxml' => $this->musicXmlService->readFile($score),
			'pdf' => $this->pdfService->readFile($score),
			default => throw new \InvalidArgumentException('Unknown file type'),
		};
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function findLinkShare(string $token): Share {
		if (strlen($token) !== self::TOKEN_LENGTH || !ctype_alnum($token)) {
			throw new \InvalidArgumentException('Share not found');
		}
		try {
			return $this->shareMapper->findLinkShareByToken($token);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			throw new \InvalidArgumentException('Share not found');
		}
	}

	/**
	 * Resolve a share to its title and scores.
	 *
	 * @param bool $publicOnly Only include scores that may be shown publicly (for link shares)
	 * @return OrchestraScoresManagerSharedTarget
	 * @throws \InvalidArgumentException if the target does not exist
	 */
	private function resolveShare(Share $share, bool $publicOnly): array {
		$target = $this->parseTargetType($share->getTargetType());
		[$title, $description] = $this->getTargetTitle($target, $share->getTargetId());

		$indexes = $this->getScoreIndexesForShare($share);
		try {
			$scores = $this->scoreMapper->findMultipleWithoutTransients(array_keys($indexes), $publicOnly);
		} catch (Exception) {
			$scores = [];
		}

		// The notes of internal preservation copies are never displayed or played back, not even to editors
		$items = array_map(fn (Score $score) => [
			'id' => $score->getId(),
			'title' => $score->getTitle(),
			'composer' => $score->getComposer(),
			'arranger' => $score->getArranger(),
			'year' => $score->getYear(),
			'duration' => $score->getDuration(),
			'index' => $indexes[$score->getId()] ?? null,
			'hasMusicXml' => $score->getMusicxmlFile() !== null && self::isViewable($score),
			'hasPdf' => $score->getPdfFile() !== null && self::isViewable($score),
		], $scores);

		usort($items, function (array $a, array $b): int {
			if ($a['index'] !== null && $b['index'] !== null) {
				return strnatcmp((string)$a['index'], (string)$b['index']);
			}
			return strcasecmp((string)$a['title'], (string)$b['title']);
		});

		return [
			'shareId' => $share->getId(),
			'targetType' => $target->value,
			'targetId' => $share->getTargetId(),
			'title' => $share->getLabel() ?? $title,
			'description' => $description,
			'scores' => $items,
		];
	}

	/**
	 * Whether the notes of a score may be displayed or played back
	 */
	private static function isViewable(Score $score): bool {
		return $score->getPublicationStatus() !== PublicationStatus::INTERNAL_PRESERVATION_COPY->value;
	}

	/**
	 * @return list<int>
	 */
	private function getScoreIdsForShare(Share $share): array {
		return array_keys($this->getScoreIndexesForShare($share));
	}

	/**
	 * Map of score IDs included in a share to their index in the folder collection, if any.
	 *
	 * For folder collections, the active version is used, including scores of score books.
	 *
	 * @return array<int, string|null>
	 */
	private function getScoreIndexesForShare(Share $share): array {
		if ($share->getTargetType() === ShareTargetType::SCORE->value) {
			return [$share->getTargetId() => null];
		}

		try {
			$folderCollection = $this->folderCollectionMapper->find($share->getTargetId());
			$versionId = $folderCollection->getActiveVersionId();
			if ($versionId === null) {
				return [];
			}

			$indexes = [];
			foreach ($this->linkMapper->findScoresForVersion($versionId) as $info) {
				$indexes[$info['id']] = $info['index'] !== null ? (string)$info['index'] : null;
			}
			foreach ($this->linkMapper->findScoreBooksForVersion($versionId) as $bookInfo) {
				foreach ($this->scoreBookScoreLinkMapper->findScoresForScoreBook($bookInfo['id']) as $scoreInfo) {
					$indexes[$scoreInfo['score_id']] = $bookInfo['index'] !== null
						? $bookInfo['index'] . '.' . $scoreInfo['index']
						: null;
				}
			}
			return $indexes;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return [];
		}
	}

	/**
	 * @return array{0: string, 1: string|null} Title and description of the target
	 * @throws \InvalidArgumentException if the target does not exist
	 */
	private function getTargetTitle(ShareTargetType $target, int $targetId): array {
		try {
			if ($target === ShareTargetType::SCORE) {
				$scores = $this->scoreMapper->findMultipleWithoutTransients([$targetId], false);
				if ($scores === []) {
					throw new DoesNotExistException('Score not found');
				}
				return [$scores[0]->getTitle(), $scores[0]->getComposer()];
			}
			$folderCollection = $this->folderCollectionMapper->find($targetId);
			return [$folderCollection->getTitle(), $folderCollection->getDescription()];
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			throw new \InvalidArgumentException($target === ShareTargetType::SCORE
				? $this->l->t('Score not found')
				: $this->l->t('Folder collection not found'));
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function parseTargetType(string $targetType): ShareTargetType {
		$target = ShareTargetType::tryFrom($targetType);
		if ($target === null) {
			throw new \InvalidArgumentException($this->l->t('Invalid share target'));
		}
		return $target;
	}

	/**
	 * @throws PermissionDeniedException
	 */
	private function assertCanEdit(): void {
		if (!$this->permissionService->canCurrentUserEdit()) {
			throw new PermissionDeniedException($this->l->t('Insufficient permissions'));
		}
	}

	/**
	 * @return OrchestraScoresManagerShare
	 */
	private function serializeShare(Share $share): array {
		$groupId = $share->getGroupId();
		$target = ShareTargetType::from($share->getTargetType());
		try {
			$targetTitle = $this->getTargetTitle($target, $share->getTargetId())[0];
		} catch (\InvalidArgumentException) {
			$targetTitle = null;
		}
		return [
			'id' => $share->getId(),
			'shareType' => ShareType::from($share->getShareType())->value,
			'targetType' => $target->value,
			'targetId' => $share->getTargetId(),
			'targetTitle' => $targetTitle,
			'token' => $share->getToken(),
			'groupId' => $groupId,
			'groupName' => $groupId !== null ? ($this->groupManager->get($groupId)?->getDisplayName() ?? $groupId) : null,
			'label' => $share->getLabel(),
			'createdBy' => $share->getCreatedBy(),
			'createdAt' => $share->getCreatedAt(),
		];
	}
}
