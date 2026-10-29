<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use Closure;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Service\Roles;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Data minimisation for existing data (milestone 0.11), no schema change:
 *
 * - audit entries of choir members (practice audio) lose account and name,
 *   only the role `member` stays, as for new entries;
 * - persons without a known death keep only the year of birth, also in the
 *   free text of their life dates and notes.
 *
 * Both steps can run twice.
 */
class Version001120Date20261029120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private PersonMapper $persons,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('notarch_audit')
			->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('user_name', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('roles', $qb->createNamedParameter(Roles::MEMBER)));
		$qb->executeStatement();

		foreach ($this->persons->findAll() as $person) {
			if ($person->keepBirthYearOnly()) {
				$this->persons->update($person);
			}
		}
	}
}
