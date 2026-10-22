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
 * @template-extends QBMapper<Person>
 */
class PersonMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_persons', Person::class);
	}

	public function find(int $id): Person {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Person> sorted by name
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name')->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @return array<int, Person> keyed by id
	 */
	public function findAllById(): array {
		$result = [];
		foreach ($this->findAll() as $person) {
			$result[$person->getId()] = $person;
		}
		return $result;
	}
}
