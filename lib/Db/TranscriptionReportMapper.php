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
 * @template-extends QBMapper<TranscriptionReport>
 */
class TranscriptionReportMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_review_reports', TranscriptionReport::class);
	}

	public function find(int $id): TranscriptionReport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<TranscriptionReport> newest first
	 */
	public function findByCopy(int $copyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<TranscriptionReport> newest first
	 */
	public function findByVersion(int $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * The report with this checksum read for a copy, if any.
	 */
	public function findByChecksum(int $copyId, string $sha256): ?TranscriptionReport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha256)))
			->orderBy('id')
			->setMaxResults(1);
		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * Reports read from a report file of the archive folder.
	 *
	 * @return list<TranscriptionReport> newest first
	 */
	public function findByReportFile(int $archiveFileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('report_file_id', $qb->createNamedParameter($archiveFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
