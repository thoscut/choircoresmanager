<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Proposal>
 */
class ProposalMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_proposals', Proposal::class);
	}

	public function find(int $id): Proposal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Proposal> oldest first
	 */
	public function findByStatus(string $status, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('id')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Proposal>
	 */
	public function findOpenForObject(string $objectType, int $objectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Proposal::OPEN)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	public function countOpen(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Proposal::OPEN)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
