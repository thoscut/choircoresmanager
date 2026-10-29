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
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_tasks', Task::class);
	}

	public function find(int $id): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param list<string> $statuses
	 * @param list<string>|null $addressees null = all
	 * @return list<Task>
	 */
	public function findByStatus(array $statuses, ?array $addressees = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id');
		if ($addressees !== null) {
			$qb->andWhere($qb->expr()->in('addressee', $qb->createNamedParameter($addressees, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Task> newest first
	 */
	public function findByCopy(int $copyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
