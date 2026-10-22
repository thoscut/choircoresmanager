<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * The manifest of the print station's target folder: the files the app
 * wrote there.
 *
 * @template-extends QBMapper<MirrorFile>
 */
class MirrorFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notarch_mirror_files', MirrorFile::class);
	}

	/**
	 * @return array<string, MirrorFile> keyed by file name
	 */
	public function findAllByName(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
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
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
