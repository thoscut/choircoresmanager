<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * The manifests of the target folders (print station, hand-over to the
 * choir system): the files the app wrote there, per mirror.
 *
 * @template-extends QBMapper<MirrorFile>
 */
class MirrorFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cantoria_mirror_files', MirrorFile::class);
	}

	/**
	 * @return array<string, MirrorFile> keyed by file name
	 */
	public function findAllByName(string $mirror): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('mirror', $qb->createNamedParameter($mirror)))
			->orderBy('id');
		$result = [];
		foreach ($this->findEntities($qb) as $file) {
			$result[$file->getName()] = $file;
		}
		return $result;
	}

	/**
	 * Forgets every file, e.g. when the target folder changes (the files stay
	 * in the old folder).
	 */
	public function deleteAll(string $mirror): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('mirror', $qb->createNamedParameter($mirror)))
			->executeStatement();
	}
}
