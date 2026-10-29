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
 * @template-extends QBMapper<ExcerptLink>
 */
class ExcerptLinkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_links', ExcerptLink::class);
	}

	public function find(int $id): ExcerptLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * The link of a token from an address; the table holds its hash.
	 */
	public function findByToken(string $token): ExcerptLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter(ExcerptLink::hashToken($token))));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<ExcerptLink> newest first; null = all links
	 */
	public function findByCopy(?int $copyId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC');
		if ($copyId !== null) {
			$qb->where($qb->expr()->eq('copy_id', $qb->createNamedParameter($copyId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Links of one circle that are not revoked, newest first; expired ones
	 * are left to the caller (the day depends on the time zone).
	 *
	 * @return list<ExcerptLink>
	 */
	public function findUnrevokedByCircle(string $circle): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('circle', $qb->createNamedParameter($circle)))
			->andWhere($qb->expr()->isNull('revoked'))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Counts a call without recording who made it.
	 */
	public function countHit(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('hits', $qb->createFunction('hits + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
