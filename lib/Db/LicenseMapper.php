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
 * @template-extends QBMapper<License>
 */
class LicenseMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_licenses', License::class);
	}

	public function find(int $id): License {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<License>
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, list<License>> keyed by version id
	 */
	public function findAllByVersion(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		$result = [];
		foreach ($this->findEntities($qb) as $license) {
			$result[$license->getVersionId()][] = $license;
		}
		return $result;
	}
}
