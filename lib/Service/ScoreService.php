<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OrchestraScoresManager\Db\CommentMapper;
use OCA\OrchestraScoresManager\Db\Enum\PublicationStatus;
use OCA\OrchestraScoresManager\Db\Enum\RightsStatus;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreBookScoreLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreFolderCollectionLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Db\ScoreTagLinkMapper;
use OCA\OrchestraScoresManager\Db\TagMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IL10N;
use Throwable;

class ScoreService {
	/** Maximum length of the rights confirmation, which is meant for initials or a short name */
	public const RIGHTS_CONFIRMED_BY_MAX_LENGTH = 32;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly CommentMapper $commentMapper,
		private readonly ScoreMapper $scoreMapper,
		private readonly TagMapper $tagMapper,
		private readonly ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper,
		private readonly ScoreFolderCollectionLinkMapper $scoreFolderCollectionLinkMapper,
		private readonly ScoreTagLinkMapper $scoreTagLinkMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly ScorePolicy $scorePolicy,
		private IL10N $l,
		private readonly MusicXmlService $musicXmlService,
	) {
	}

	/**
	 * Get a score by its ID.
	 *
	 * @param int $id
	 * @return Score
	 * @throws \Exception
	 */
	public function getScoreById(int $id): Score {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);

		try {
			return $this->scoreMapper->find($id);
		} catch (MultipleObjectsReturnedException|DoesNotExistException|Exception $e) {
			throw new \Exception($this->l->t('Score not found'));
		}
	}

	/**
	 * Delete a score by its ID if authorized.
	 *
	 * @param int $id
	 * @throws \Exception
	 */
	public function deleteScoreById(int $id): void {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_DELETE);

		try {
			$score = $this->scoreMapper->find($id);
		} catch (MultipleObjectsReturnedException|DoesNotExistException|Exception $e) {
			throw new \InvalidArgumentException($this->l->t('Score not found'));
		}

		// Check if used in any folder collections
		$linkedCollections = $this->scoreFolderCollectionLinkMapper->findVersionsForScore($id);
		if (!empty($linkedCollections)) {
			throw new \InvalidArgumentException($this->l->t('Cannot delete score because it is part of a folder collection'));
		}

		//Delete score from score books (cascade should handle this, but let's be sure)
		$this->scoreBookScoreLinkMapper->removeScoreFromAllBooks($id);

		// Delete tag links first (cascade should handle this, but let's be sure)
		$this->scoreTagLinkMapper->deleteAllTagsForScore($id);

		// Delete comments first (cascade should handle this, but let's be sure)
		$this->commentMapper->deleteByScoreId($id);

		$this->scoreMapper->delete($score);

		// Remove the attached MusicXML file, if any
		$this->musicXmlService->removeFile($id);
	}

	/**
	 * Get all scores.
	 *
	 * @return Score[]
	 */
	public function getAllScores(): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);
		return $this->scoreMapper->findAll();
	}

	/**
	 * Get scores by their IDs.
	 *
	 * @param int[] $ids
	 * @return Score[]
	 * @throws Exception
	 */
	public function getScoresByIds(array $ids): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);
		return $this->scoreMapper->findMultiple($ids);
	}

	/**
	 * Create a new score if authorized.
	 * Default-deny: if authorization fails, an exception is thrown.
	 *
	 * @param Score $score
	 * @param int[]|null $tagIds
	 * @param array{scoreBookId?: int|null, index?: int|null}|null $scoreBookInfo Array with 'scoreBookId' and 'index' keys
	 * @return Score
	 * @throws Exception
	 * @throws Throwable
	 */
	public function createScore(Score $score, ?array $tagIds = null, ?array $scoreBookInfo = null): Score {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_CREATE);
		$this->normalizeAndValidate($score);

		$created = $this->scoreMapper->insert($score);
		if ($tagIds !== null) {
			$validIds = array_map('intval', $tagIds);
			foreach ($validIds as $tid) {
				try {
					$this->tagMapper->find($tid);
				} catch (DoesNotExistException|MultipleObjectsReturnedException|\RuntimeException $e) {
					throw new \Exception($this->l->t('Tag not found: %s', [$tid]));
				}
			}
			$this->scoreTagLinkMapper->setTagsForScore($created->getId(), $validIds);
		}

		// Handle score book info
		if ($scoreBookInfo !== null && isset($scoreBookInfo['scoreBookId'])) {
			$this->updateScoreBookInfoInternal($created->getId(), $scoreBookInfo);
		}

		// Reload to get all transient properties
		return $this->scoreMapper->find($created->getId());
	}

	/**
	 * Update an existing score if authorized.
	 *
	 * @param Score $score
	 * @param int[]|null $tagIds
	 * @param array{scoreBookId?: int|null, index?: int|null}|null $scoreBookInfo Array with optional 'scoreBookId' and 'index' keys
	 * @return Score
	 * @throws Exception
	 * @throws Throwable
	 */
	public function updateScore(Score $score, ?array $tagIds = null, ?array $scoreBookInfo = null): Score {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);
		$this->resetDependentFields($score);
		$this->normalizeAndValidate($score);

		if ($tagIds !== null) {
			$validIds = array_map('intval', $tagIds);
			foreach ($validIds as $tid) {
				try {
					$this->tagMapper->find($tid);
				} catch (DoesNotExistException|MultipleObjectsReturnedException|\RuntimeException $e) {
					throw new Exception($this->l->t('Tag not found: %s', [$tid]));
				}
			}
			$this->scoreTagLinkMapper->setTagsForScore($score->getId(), $validIds);
		}

		// Handle score book info update
		if ($scoreBookInfo !== null) {
			$this->updateScoreBookInfoInternal($score->getId(), $scoreBookInfo);
		}

		// This check here is needed in case only transient properties (like tags) were updated
		// Unfortunately, NC's QBMapper has a bug in handling for this
		// See also https://github.com/nextcloud/server/pull/56337
		$updatedProperties = $score->getUpdatedFields();
		unset($updatedProperties['id']);
		if (\count($updatedProperties) > 0) {
			$this->scoreMapper->update($score);
		}

		// Reload to get all transient properties
		return $this->scoreMapper->find($score->getId());
	}

	/**
	 * Internal method to update score book info for a score.
	 *
	 * @param int $scoreId
	 * @param array{scoreBookId?: int|null, index?: int|null} $scoreBookInfo Array with 'scoreBookId' and/or 'index' keys
	 * @throws \InvalidArgumentException
	 * @throws Exception
	 */
	private function updateScoreBookInfoInternal(int $scoreId, array $scoreBookInfo): void {
		$scoreBookId = $scoreBookInfo['scoreBookId'] ?? null;
		$index = $scoreBookInfo['index'] ?? null;

		// Get current book info
		$currentBookInfo = $this->scoreBookScoreLinkMapper->findScoreBookForScore($scoreId);

		// If scoreBookId is explicitly null, remove from book
		if (array_key_exists('scoreBookId', $scoreBookInfo) && $scoreBookId === null) {
			if ($currentBookInfo !== null) {
				$this->scoreBookScoreLinkMapper->removeScoreFromAllBooks($scoreId);
			}
			return;
		}

		// If no scoreBookId provided and only index, update index in current book
		if (!array_key_exists('scoreBookId', $scoreBookInfo) && $index !== null && $currentBookInfo !== null) {
			// Check if new index is occupied
			if ($this->scoreBookScoreLinkMapper->isIndexOccupied($currentBookInfo['score_book_id'], $index)) {
				throw new \InvalidArgumentException($this->l->t('Index %d is already occupied in this score book', [$index]));
			}
			$this->scoreBookScoreLinkMapper->updateScoreIndex($currentBookInfo['score_book_id'], $scoreId, $index);
			return;
		}

		// If scoreBookId is provided
		if ($scoreBookId !== null) {
			// If score is already in a different book, error
			if ($currentBookInfo !== null && $currentBookInfo['score_book_id'] !== $scoreBookId) {
				throw new \InvalidArgumentException($this->l->t('Score is already part of another score book. Remove it first.'));
			}

			// If score is already in this book, just update index if provided
			if ($currentBookInfo !== null && $currentBookInfo['score_book_id'] === $scoreBookId) {
				if ($index !== null && $index !== $currentBookInfo['index']) {
					if ($this->scoreBookScoreLinkMapper->isIndexOccupied($scoreBookId, $index)) {
						throw new \InvalidArgumentException($this->l->t('Index %d is already occupied in this score book', [$index]));
					}
					$this->scoreBookScoreLinkMapper->updateScoreIndex($scoreBookId, $scoreId, $index);
				}
				return;
			}

			// Adding to a new book requires an index
			if ($index === null) {
				throw new \InvalidArgumentException($this->l->t('Index is required when adding score to a score book'));
			}

			// Check if index is occupied
			if ($this->scoreBookScoreLinkMapper->isIndexOccupied($scoreBookId, $index)) {
				throw new \InvalidArgumentException($this->l->t('Index %d is already occupied in this score book', [$index]));
			}

			$this->scoreBookScoreLinkMapper->addScoreToScoreBook($scoreBookId, $scoreId, $index);
		}
	}

	/**
	 * Parse a date given in the API as Y-m-d.
	 *
	 * Impossible dates such as 2026-02-30 are rejected instead of being rolled over.
	 *
	 * @param mixed $value Date string in Y-m-d format, null or an empty string
	 * @return DateTimeImmutable|null The date at midnight UTC, or null for an empty value
	 * @throws \InvalidArgumentException if the value is not a valid date
	 */
	public function parseDate(mixed $value): ?DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
			if ($date !== false && $date->format('Y-m-d') === $value) {
				return $date;
			}
		}
		throw new \InvalidArgumentException($this->l->t('Invalid date. Use the format YYYY-MM-DD.'));
	}

	/**
	 * Clear fields whose meaning depends on a status that is being changed.
	 *
	 * A rights confirmation refers to the rights status it confirmed, so it is cleared when
	 * the rights status changes, unless the same update sets a new confirmation. A "blocked
	 * until" date only makes sense for blocked scores, so it is cleared when the publication
	 * status changes to anything else, unless the same update sets it explicitly.
	 *
	 * @param Score $score
	 */
	private function resetDependentFields(Score $score): void {
		$updatedFields = $score->getUpdatedFields();

		if (isset($updatedFields['rightsStatus'])
			&& !isset($updatedFields['rightsConfirmedAt'])
			&& !isset($updatedFields['rightsConfirmedBy'])) {
			$score->setRightsConfirmedAt(null);
			$score->setRightsConfirmedBy(null);
		}

		if (isset($updatedFields['publicationStatus'])
			&& !isset($updatedFields['publicationBlockedUntil'])
			&& $score->getPublicationStatus() !== PublicationStatus::BLOCKED->value) {
			$score->setPublicationBlockedUntil(null);
		}
	}

	/**
	 * Normalize optional text fields and ensure rights and publication fields have allowed values.
	 *
	 * @param Score $score
	 * @throws \InvalidArgumentException
	 */
	private function normalizeAndValidate(Score $score): void {
		if (RightsStatus::tryFrom($score->getRightsStatus()) === null) {
			throw new \InvalidArgumentException($this->l->t('Invalid rights status. Must be "public_domain", "licensed", "protected" or "open".'));
		}
		if (PublicationStatus::tryFrom($score->getPublicationStatus()) === null) {
			throw new \InvalidArgumentException($this->l->t('Invalid publication status. Must be "approved", "to_clarify", "blocked" or "internal_preservation_copy".'));
		}

		$confirmedBy = $score->getRightsConfirmedBy();
		if ($confirmedBy !== null) {
			$trimmed = trim($confirmedBy);
			if (mb_strlen($trimmed) > self::RIGHTS_CONFIRMED_BY_MAX_LENGTH) {
				throw new \InvalidArgumentException($this->l->t('Use at most %d characters for the person who confirmed the rights, e.g. their initials.', [self::RIGHTS_CONFIRMED_BY_MAX_LENGTH]));
			}
			if ($trimmed !== $confirmedBy) {
				$score->setRightsConfirmedBy($trimmed === '' ? null : $trimmed);
			}
		}

		if ($score->getPublicationBlockedUntil() !== null
			&& $score->getPublicationStatus() !== PublicationStatus::BLOCKED->value) {
			throw new \InvalidArgumentException($this->l->t('A "blocked until" date can only be set if the publication is blocked.'));
		}
	}
}
