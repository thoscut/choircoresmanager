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
 * @template-extends QBMapper<VersionState>
 */
class VersionStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_states', VersionState::class);
	}

	public function find(int $id): VersionState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<VersionState> oldest first (by number)
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('number');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, VersionState> by id
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $state) {
				$result[$state->getId()] = $state;
			}
		}
		return $result;
	}

	/**
	 * Every revision, grouped by version and oldest first.
	 *
	 * @return array<int, list<VersionState>> version id => revisions
	 */
	public function findAllByVersion(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('version_id')->addOrderBy('number');
		$result = [];
		foreach ($this->findEntities($qb) as $state) {
			$result[$state->getVersionId()][] = $state;
		}
		return $result;
	}
}
