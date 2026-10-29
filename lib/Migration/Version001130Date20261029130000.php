<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `notarch_versions.society_work_no` (milestone 0.11): the number of the work
 * at the collecting society (GEMA), for the song order. Nullable without a
 * default.
 */
class Version001130Date20261029130000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$versions = $schema->getTable('notarch_versions');
		if ($versions->hasColumn('society_work_no')) {
			return null;
		}
		$versions->addColumn('society_work_no', Types::STRING, ['notnull' => false, 'length' => 32]);
		return $schema;
	}
}
