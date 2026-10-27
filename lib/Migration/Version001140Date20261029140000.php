<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `notarch_mirror_files.mirror` (milestone 0.12): which target folder a
 * written file belongs to, the print station (`print`, all files so far) or
 * the hand-over to the choir system (`handover`). A file name is unique per
 * target folder.
 */
class Version001140Date20261029140000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$files = $schema->getTable('notarch_mirror_files');
		if ($files->hasColumn('mirror')) {
			return null;
		}
		$files->addColumn('mirror', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'print']);
		if ($files->hasIndex('notarch_mirr_name')) {
			$files->dropIndex('notarch_mirr_name');
		}
		$files->addUniqueIndex(['mirror', 'name'], 'notarch_mirr_mirror_name');
		return $schema;
	}
}
