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
 * Issues and returns of choir folders. Rows are never deleted; after the
 * retention period their personal data are removed (FolderBookService).
 *
 * @template-extends QBMapper<FolderLoan>
 */
class FolderLoanMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_folder_loans', FolderLoan::class);
	}

	public function find(int $id): FolderLoan {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<FolderLoan> newest first
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('issued_on', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * The loan of a folder that is out at the moment, if any.
	 */
	public function findOpen(int $folderId): ?FolderLoan {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('open_folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
		$loans = $this->findEntities($qb);
		return $loans[0] ?? null;
	}

	/**
	 * Returned loans whose personal data are still there and whose return
	 * lies on or before the given day.
	 *
	 * @return list<FolderLoan>
	 */
	public function findToAnonymize(string $returnedUntil): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('returned_on'))
			->andWhere($qb->expr()->lte('returned_on', $qb->createNamedParameter($returnedUntil)))
			->andWhere($qb->expr()->isNull('anonymized'))
			->orderBy('id');
		return $this->findEntities($qb);
	}
}
