<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<EventItem>
 */
class EventItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_event_items', EventItem::class);
	}

	public function find(int $id): EventItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<EventItem> in programme order
	 */
	public function findByEvent(int $eventId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
			->orderBy('position')
			->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<EventItem>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('event_id')->addOrderBy('position');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<EventItem>
	 */
	public function findByAssignment(string $assignment): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('assignment', $qb->createNamedParameter($assignment)))
			->orderBy('event_id')->addOrderBy('position');
		return $this->findEntities($qb);
	}

	public function deleteByEvent(int $eventId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
