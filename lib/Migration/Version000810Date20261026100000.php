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
 * Milestone 0.8, archive: no new table, a few columns in existing ones.
 *
 * - Versions: the special cases of the protection term, scientific edition
 *   (§ 70 UrhG) and posthumous work (§ 71 UrhG), and the year in which the
 *   edition or the work first appeared.
 * - Files: the rights notice of a MusicXML file (`<identification><rights>`)
 *   as read by the file sync, unchecked information, and when it was read
 *   (null: not read yet, or the file changed since).
 *
 * Boolean columns are nullable (Nextcloud cannot store false in a NOT NULL
 * boolean column on Oracle); names stay within the 30 characters Nextcloud
 * checks for Oracle.
 */
class Version000810Date20261026100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$versions = $schema->getTable('notarch_versions');
		if (!$versions->hasColumn('scientific_edition')) {
			$versions->addColumn('scientific_edition', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		if (!$versions->hasColumn('posthumous_work')) {
			$versions->addColumn('posthumous_work', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		if (!$versions->hasColumn('first_published_year')) {
			$versions->addColumn('first_published_year', Types::INTEGER, ['notnull' => false]);
			$changed = true;
		}

		$files = $schema->getTable('notarch_files');
		if (!$files->hasColumn('rights_notice')) {
			$files->addColumn('rights_notice', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$changed = true;
		}
		if (!$files->hasColumn('rights_read_at')) {
			$files->addColumn('rights_read_at', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
