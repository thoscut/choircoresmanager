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
 * @template-extends QBMapper<Contribution>
 */
class ContributionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_contributions', Contribution::class);
	}

	public function find(int $id): Contribution {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Contribution>
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Contribution>
	 */
	public function findByPerson(int $personId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->orderBy('version_id')->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, list<Contribution>> keyed by version id
	 */
	public function findAllByVersion(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		$result = [];
		foreach ($this->findEntities($qb) as $contribution) {
			$result[$contribution->getVersionId()][] = $contribution;
		}
		return $result;
	}

	/**
	 * @return array<int, int> person id => number of contributions
	 */
	public function countPerPerson(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('person_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->groupBy('person_id');
		$result = $qb->executeQuery();
		$counts = [];
		foreach ($result->fetchAllAssociative() as $row) {
			$counts[Row::int($row['person_id'])] = Row::int($row['cnt']);
		}
		$result->closeCursor();
		return $counts;
	}
}
