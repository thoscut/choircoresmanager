<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The audit log is append-only. There is deliberately no method to change or
 * delete entries.
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
	 * @return list<AuditEntry> newest first
	 */
	public function findLatest(int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
