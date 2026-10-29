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
 * @template-extends QBMapper<Merge>
 */
class MergeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_merges', Merge::class);
	}

	public function find(int $id): Merge {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Merge> newest first
	 */
	public function findLatest(bool $includeUndone, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		if (!$includeUndone) {
			$qb->where($qb->expr()->isNull('undone'));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Merges that are not undone and touch the given work (as target) or copy
	 * (as moved copy), newest first.
	 *
	 * @return list<Merge>
	 */
	public function findOpenFor(int $workId, int $copyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('undone'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('kind', $qb->createNamedParameter(Merge::WORK)),
					$qb->expr()->eq('target_id', $qb->createNamedParameter($workId, IQueryBuilder::PARAM_INT)),
				),
				$qb->expr()->andX(
					$qb->expr()->eq('kind', $qb->createNamedParameter(Merge::COPY)),
					$qb->expr()->eq('source_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
