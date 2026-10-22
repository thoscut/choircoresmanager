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
 * The audit log is append-only. There is deliberately no method to delete
 * entries, and only one to change them: the retention period of the folder
 * book (FolderBookService) replaces the personal data of a folder loan in
 * that loan's own entries.
 *
 * @template-extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_audit', AuditEntry::class);
	}

	/**
	 * @param list<array{0: string, 1: int}> $objects pairs of object type and id
	 * @return list<AuditEntry> newest first
	 */
	public function findForObjects(array $objects, int $limit = 200): array {
		if ($objects === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$conditions = [];
		foreach ($objects as [$type, $id]) {
			$conditions[] = $qb->expr()->andX(
				$qb->expr()->eq('object_type', $qb->createNamedParameter($type)),
				$qb->expr()->eq('object_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			);
		}
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(...$conditions))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @return list<AuditEntry> oldest first, for the export
	 */
	public function findAllInOrder(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $excludeTypes object types to leave out (e.g. the folder book for roles that may not read it)
	 * @return list<AuditEntry> newest first
	 */
	public function findLatest(int $limit = 200, array $excludeTypes = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		if ($excludeTypes !== []) {
			$qb->where($qb->expr()->notIn('object_type', $qb->createNamedParameter($excludeTypes, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Replaces the old and new values of some fields in the entries of one
	 * object with a placeholder (values that were empty stay empty). Used
	 * only for the personal data of a folder loan after its retention period.
	 *
	 * @param list<string> $fields
	 */
	public function anonymizeFields(string $objectType, int $objectId, array $fields, string $placeholder): void {
		foreach (['old_value', 'new_value'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set($column, $qb->createNamedParameter($placeholder))
				->where($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
				->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('field', $qb->createNamedParameter($fields, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNotNull($column));
			$qb->executeStatement();
		}
	}
}
