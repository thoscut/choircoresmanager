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
 * @template-extends QBMapper<Work>
 */
class WorkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_works', Work::class);
	}

	public function find(int $id): Work {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return array<int, Work> keyed by id
	 */
	public function findAllById(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$result = [];
		foreach ($this->findEntities($qb) as $work) {
			$result[$work->getId()] = $work;
		}
		return $result;
	}

	/**
	 * Highest number of the work numbers "W-0001", "W-0002", …
	 */
	public function highestWorkNumber(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('work_no')->from($this->getTableName())
			->where($qb->expr()->like('work_no', $qb->createNamedParameter('W-%')));
		$result = $qb->executeQuery();
		$highest = 0;
		foreach (array_map(Row::stringOrNull(...), $result->fetchFirstColumn()) as $value) {
			if ($value !== null && preg_match('/^W-(\d+)$/', $value, $m) === 1) {
				$highest = max($highest, (int)$m[1]);
			}
		}
		$result->closeCursor();
		return $highest;
	}
}
