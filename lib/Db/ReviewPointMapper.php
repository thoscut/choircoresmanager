<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ReviewPoint>
 */
class ReviewPointMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_review_points', ReviewPoint::class);
	}

	public function find(int $id): ReviewPoint {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<ReviewPoint> in the order they were created
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string>|null $statuses null = all
	 * @return list<ReviewPoint> in the order they were created
	 */
	public function findByCopy(int $copyId, ?array $statuses = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		if ($statuses !== null) {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $statuses
	 * @return list<ReviewPoint> oldest first
	 */
	public function findByStatus(array $statuses, int $limit = 2000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Number of points of a report and role that wait for a decision.
	 */
	public function countOpen(int $reportId, string $role): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ReviewPoint::OPEN)));
		$result = $qb->executeQuery();
		$count = Row::int($result->fetchOne());
		$result->closeCursor();
		return $count;
	}

	/**
	 * Unresolved points per copy: `open` counts the statuses open and
	 * question (a person has to act), `orders` the correction orders.
	 *
	 * @return array<int, array{open: int, orders: int}> copy id => counts
	 */
	public function countsPerCopy(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('copy_id', 'status')
			->selectAlias($qb->func()->count('id'), 'points')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(ReviewPoint::UNRESOLVED, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('copy_id', 'status');
		$result = $qb->executeQuery();
		$counts = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$copyId = Row::int($row['copy_id']);
			$counts[$copyId] ??= ['open' => 0, 'orders' => 0];
			$key = Row::string($row['status']) === ReviewPoint::CORRECT ? 'orders' : 'open';
			$counts[$copyId][$key] += Row::int($row['points']);
		}
		$result->closeCursor();
		return $counts;
	}
}
