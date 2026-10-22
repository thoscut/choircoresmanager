<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Db;

use DateTimeImmutable;
use DateTimeZone;
use OCA\ChoirScores\Db\Enum\PublicationStatus;
use OCA\ChoirScores\Db\Enum\RightsStatus;
use OCA\ChoirScores\Service\RepertoireClassifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Score>
 */
class ScoreMapper extends QBMapper {
	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		IDBConnection $db,
		protected readonly ScoreTagLinkMapper $scoreTagLinkMapper,
		protected readonly TagMapper $tagMapper,
		protected readonly ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper,
		protected readonly RepertoireClassifier $repertoireClassifier,
	) {
		parent::__construct($db, 'chs_scores', Score::class);
	}

	/**
	 * Insert a score and remember when it was created.
	 *
	 * @param Entity $entity
	 * @psalm-param Score $entity
	 * @return Entity
	 * @psalm-return Score
	 * @throws Exception
	 */
	public function insert(Entity $entity): Entity {
		$entity->setUpdatedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')));
		return parent::insert($entity);
	}

	/**
	 * Update a score and remember when it was changed.
	 *
	 * @param Entity $entity
	 * @psalm-param Score $entity
	 * @return Entity
	 * @psalm-return Score
	 * @throws Exception
	 * @throws \InvalidArgumentException if the score has no id
	 * @psalm-suppress PossiblyUnusedReturnValue - Keeps the signature of QBMapper::update
	 */
	public function update(Entity $entity): Entity {
		$entity->setUpdatedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')));
		return parent::update($entity);
	}

	/**
	 * Find a score by ID and attach transient properties.
	 *
	 * @param int $id
	 * @return Score
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): Score {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$score = $this->findEntity($qb);
		$this->attachTags($score);
		$this->attachScoreBookInfo($score);
		$this->attachLastPerformed([$score]);
		return $score;
	}

	/**
	 * Get all scores with tags and score book info attached.
	 *
	 * @return array<Score> Scores
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$scores = $this->findEntities($qb);

		$scoreIds = array_map(fn (Score $s) => $s->getId(), $scores);

		// Attach tags to each score
		// We don't use attachTags in a loop to avoid N+1 DB queries
		$scoreTagIdsMap = $this->scoreTagLinkMapper->findTagIdsForScores($scoreIds);

		// get a merged list of all tag IDs
		$allTagIds = [];
		foreach ($scoreTagIdsMap as $tagIds) {
			$allTagIds = array_merge($allTagIds, $tagIds);
		}
		$allTagIds = array_values(array_unique($allTagIds));

		// Next, get the mapping of tag IDs to their name
		$tagIdNameMap = [];
		foreach ($this->tagMapper->findMultiple($allTagIds) as $tag) {
			$tagIdNameMap[$tag->getId()] = $tag->getName();
		}

		// Get score book info for all scores
		$scoreBookInfoMap = $this->scoreBookScoreLinkMapper->findScoreBooksForScores($scoreIds);

		// Finally, attach the tag names and score book info to each score
		foreach ($scores as $score) {
			$tagNames = [];
			$tagIds = $scoreTagIdsMap[$score->getId()] ?? [];
			foreach ($tagIds as $tagId) {
				if (isset($tagIdNameMap[$tagId])) {
					$tagNames[] = $tagIdNameMap[$tagId];
				}
			}
			$score->setTags($tagNames);

			// Attach score book info as object
			$bookInfo = $scoreBookInfoMap[$score->getId()] ?? null;
			if ($bookInfo !== null) {
				$score->setScoreBook([
					'id' => $bookInfo['score_book_id'],
					'index' => $bookInfo['index'],
				]);
			}
		}
		$this->attachLastPerformed($scores);

		return $scores;
	}

	/**
	 * Find scores by multiple IDs.
	 *
	 * @param int[] $ids
	 * @return Score[]
	 * @throws Exception
	 */
	public function findMultiple(array $ids): array {
		$ids = array_map('intval', array_values($ids));
		if (empty($ids)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$scores = $this->findEntities($qb);

		// Attach tags and score book info (similar to findAll)
		$scoreIds = array_map(fn (Score $s) => $s->getId(), $scores);
		$scoreTagIdsMap = $this->scoreTagLinkMapper->findTagIdsForScores($scoreIds);
		$allTagIds = [];
		foreach ($scoreTagIdsMap as $tagIds) {
			$allTagIds = array_merge($allTagIds, $tagIds);
		}
		$allTagIds = array_values(array_unique($allTagIds));
		$tagIdNameMap = [];
		foreach ($this->tagMapper->findMultiple($allTagIds) as $tag) {
			$tagIdNameMap[$tag->getId()] = $tag->getName();
		}
		$scoreBookInfoMap = $this->scoreBookScoreLinkMapper->findScoreBooksForScores($scoreIds);

		foreach ($scores as $score) {
			$tagNames = [];
			$tagIds = $scoreTagIdsMap[$score->getId()] ?? [];
			foreach ($tagIds as $tagId) {
				if (isset($tagIdNameMap[$tagId])) {
					$tagNames[] = $tagIdNameMap[$tagId];
				}
			}
			$score->setTags($tagNames);

			$bookInfo = $scoreBookInfoMap[$score->getId()] ?? null;
			if ($bookInfo !== null) {
				$score->setScoreBook([
					'id' => $bookInfo['score_book_id'],
					'index' => $bookInfo['index'],
				]);
			}
		}
		$this->attachLastPerformed($scores);

		return $scores;
	}

	/**
	 * Attach tags to a single score.
	 *
	 * @param Score $score
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	private function attachTags(Score $score): void {
		$ids = $this->scoreTagLinkMapper->findTagIdsForScore($score->getId());
		$names = [];
		foreach ($ids as $id) {
			$tag = $this->tagMapper->find($id);
			$names[] = $tag->getName();
		}
		$score->setTags($names);
	}

	/**
	 * Attach score book info to a single score.
	 *
	 * @param Score $score
	 * @throws Exception
	 */
	private function attachScoreBookInfo(Score $score): void {
		$bookInfo = $this->scoreBookScoreLinkMapper->findScoreBookForScore($score->getId());
		if ($bookInfo !== null) {
			$score->setScoreBook([
				'id' => $bookInfo['score_book_id'],
				'index' => $bookInfo['index'],
			]);
		}
	}

	/**
	 * Attach the date of the most recent performance and the derived repertoire class to the given scores.
	 *
	 * A performance is a non-draft setlist with a start date in the past that contains the score,
	 * or the last performance documented in an imported catalogue; the later date wins.
	 *
	 * @param Score[] $scores
	 * @throws Exception
	 */
	private function attachLastPerformed(array $scores): void {
		$scoreIds = array_map(fn (Score $s) => $s->getId(), $scores);
		if (empty($scoreIds)) {
			return;
		}

		/** @var array<int, string> $lastPerformedMap score ID => Y-m-d */
		$lastPerformedMap = [];
		$now = new \DateTimeImmutable();
		// chunk to stay below parameter limits of some DB backends
		foreach (array_chunk($scoreIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('se.score_id')
				->selectAlias($qb->func()->max('s.start_date_time'), 'last_performed')
				->from('chs_setlist_entries', 'se')
				->innerJoin('se', 'chs_setlists', 's', $qb->expr()->eq('se.setlist_id', 's.id'))
				->where($qb->expr()->in('se.score_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('s.is_draft', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
				->andWhere($qb->expr()->isNotNull('s.start_date_time'))
				->andWhere($qb->expr()->lte('s.start_date_time', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)))
				->groupBy('se.score_id');
			$result = $qb->executeQuery();
			/** @var array<int, array{score_id: int|string, last_performed: string|null}> $rows */
			$rows = $result->fetchAll();
			$result->closeCursor();
			foreach ($rows as $row) {
				if ($row['last_performed'] === null) {
					continue;
				}
				// datetimes are stored in UTC
				$start = new \DateTimeImmutable($row['last_performed'], new \DateTimeZone('UTC'));
				$lastPerformedMap[(int)$row['score_id']] = $this->repertoireClassifier->toLocalDate($start);
			}
		}

		foreach ($scores as $score) {
			$fromSetlists = $lastPerformedMap[$score->getId()] ?? null;
			$imported = $score->getLastPerformedImported()?->format('Y-m-d');
			// Y-m-d strings compare chronologically
			$lastPerformed = max($fromSetlists ?? '', $imported ?? '');
			$lastPerformed = $lastPerformed === '' ? null : $lastPerformed;
			$score->setLastPerformed($lastPerformed);
			$score->setRepertoireClass($this->repertoireClassifier->classify($lastPerformed));
		}
	}

	/**
	 * Find all scores that may be shown on the public page, without transient properties.
	 *
	 * The filter is applied in SQL so that callers serving anonymous requests
	 * never load scores they must not expose.
	 *
	 * @return array<Score>
	 * @throws Exception
	 */
	public function findPublishable(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->wherePublishable($qb);
		$qb->orderBy('title', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Find a single score by ID if it may be shown on the public page, without transient properties.
	 *
	 * @param int $id
	 * @return Score
	 * @throws MultipleObjectsReturnedException
	 * @throws DoesNotExistException if the score does not exist or must not be shown publicly
	 * @throws Exception
	 */
	public function findPublishableById(int $id): Score {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$this->wherePublishable($qb);
		return $this->findEntity($qb);
	}

	/**
	 * Find scores by IDs without transient properties, optionally only those that may be shown publicly.
	 *
	 * @param list<int> $ids
	 * @param bool $publicOnly Only return public scores, filtered in SQL (see findPublic())
	 * @return array<Score>
	 * @throws Exception
	 */
	public function findMultipleWithoutTransients(array $ids, bool $publicOnly): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		if ($publicOnly) {
			$this->wherePublishable($qb);
		}
		return $this->findEntities($qb);
	}

	/**
	 * Restrict a query to scores that may be shown publicly: approved for publication,
	 * in the public domain or licensed, and with a rights status confirmed by a person.
	 *
	 * @param IQueryBuilder $qb
	 */
	private function wherePublishable(IQueryBuilder $qb): void {
		$qb->andWhere($qb->expr()->eq('publication_status', $qb->createNamedParameter(PublicationStatus::APPROVED->value, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('rights_status', $qb->createNamedParameter(RightsStatus::publishableValues(), IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('rights_confirmed_at'));
	}
}
