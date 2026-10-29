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
 * Milestone 0.8, revisions of a version (module "version_states"):
 *
 * - `notarch_states`: one row per revision of the MusicXML of a version. It
 *   points to the file (`notarch_files.id`, with the name at the time) and
 *   keeps its checksum, the measures and voices changed, a note, who and
 *   when. The app copies no file (guideline 4); a row is never changed
 *   afterwards except for `valid_since`/`valid_set_by`, the mark of the
 *   current revision that the catalog librarian sets. `number` counts per
 *   version (1, 2, 3 …); the unique index keeps it so also when two requests
 *   come at the same moment.
 * - `notarch_event_items.state_id`: the revision sung in a concert, set when
 *   the concert is confirmed as sung.
 *
 * Names stay within the limits Nextcloud checks for Oracle (tables 27,
 * columns and indexes 30 characters), like the other tables of the app.
 */
class Version000820Date20261027100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('notarch_states')) {
			$t = $schema->createTable('notarch_states');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// the copy whose file it is
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// notarch_files.id of the MusicXML file and its name at the time
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			// SHA-256 of the file content; null when the file could not be read
			$t->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			// 1, 2, 3 … per version
			$t->addColumn('number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// first, correction or sync
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'sync']);
			// measures and voices changed, as text ("12, 14–15"; "Tenor II, Bass I")
			$t->addColumn('measures', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('voices', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			// JSON: the changes of a correction in the app
			$t->addColumn('changes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// set for the current revision of the version only
			$t->addColumn('valid_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_set_by', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['version_id', 'number'], 'notarch_state_number');
			$t->addIndex(['file_id'], 'notarch_state_file');
			$changed = true;
		}

		$items = $schema->getTable('notarch_event_items');
		if (!$items->hasColumn('state_id')) {
			$items->addColumn('state_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
