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
 * @template-extends QBMapper<Event>
 */
class EventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_events', Event::class);
	}

	public function find(int $id): Event {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Event> newest first
	 */
	public function findByKind(?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('event_date', 'DESC')
			->addOrderBy('id', 'DESC');
		if ($kind !== null) {
			$qb->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, Event> keyed by id
	 */
	public function findAllById(): array {
		$result = [];
		foreach ($this->findByKind() as $event) {
			$result[$event->getId()] = $event;
		}
		return $result;
	}

	/**
	 * Events created by an import with the given source tag, for repeated
	 * imports.
	 *
	 * @return list<Event>
	 */
	public function findImported(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('imported', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		return $this->findEntities($qb);
	}
}
