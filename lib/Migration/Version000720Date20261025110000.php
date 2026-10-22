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
 * Milestone 0.7, folders and the print station:
 * - `notarch_folders`: the choir folders handed out to choir members, with
 *   their contents (copies and counts as JSON), condition and missing
 *   sheets. Never deleted; a status instead.
 * - `notarch_folder_loans`: who has which folder since when, and the
 *   return. `open_folder_id` holds the folder id while the folder is out;
 *   its unique index lets a folder be issued only once at a time, also when
 *   two requests come at the same moment. The names of persons are
 *   anonymised after the retention period; the row stays.
 * - `notarch_mirror_files`: the files the app wrote into the target folder
 *   of the print station, with their checksums. Only these files are ever
 *   replaced or removed. Instance-specific like the settings (file ids),
 *   therefore not part of the complete backup.
 *
 * Names stay within the limits Nextcloud checks for Oracle (tables 27,
 * columns and indexes 30 characters), like the other tables of the app.
 */
class Version000720Date20261025110000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('notarch_folders')) {
			$t = $schema->createTable('notarch_folders');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// number or name of the folder, e.g. "12" or "Tenor 3"
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
			// voice or section, e.g. "Tenor I"
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 64]);
			// JSON: [{"copyId": 12, "count": 1}, …]
			$t->addColumn('contents', Types::TEXT, ['notnull' => false]);
			// condition: good, worn, damaged
			$t->addColumn('wear', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'good']);
			// sheets missing at the moment
			$t->addColumn('missing', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('status_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('notarch_folder_loans')) {
			$t = $schema->createTable('notarch_folder_loans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// the folder id while the folder is out, null after the return
			$t->addColumn('open_folder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// Nextcloud account of the person, or null for a name only
			$t->addColumn('holder_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			// display name of the account or the name given; null once anonymised
			$t->addColumn('holder_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('issued_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('issue_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('returned_on', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('return_wear', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('return_missing', Types::TEXT, ['notnull' => false]);
			$t->addColumn('return_note', Types::TEXT, ['notnull' => false]);
			// when the personal data were anonymised (Unix time)
			$t->addColumn('anonymized', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['folder_id'], 'notarch_floan_folder');
			$t->addUniqueIndex(['open_folder_id'], 'notarch_floan_open');
			$t->addIndex(['returned_on'], 'notarch_floan_returned');
		}

		if (!$schema->hasTable('notarch_mirror_files')) {
			$t = $schema->createTable('notarch_mirror_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// file name in the target folder
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Nextcloud file id of the written file
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// SHA-256 of the content the app wrote
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// notarch_files.id of the scan it was copied from, and its ETag then
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('source_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('written', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['name'], 'notarch_mirr_name');
		}

		return $schema;
	}
}
