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
 * Base tables: works, versions, copies, files, audit log.
 *
 * Text columns are nullable (empty = NULL) because Oracle treats empty strings
 * as NULL. Boolean columns must be nullable for the same reason.
 */
class Version000100Date20261022000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('cantoria_works')) {
			$t = $schema->createTable('cantoria_works');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('work_no', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('composer', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('repertoire_import', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('performances_import', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_performed_import', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('song_lists_import', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('cantoria_versions')) {
			$t = $schema->createTable('cantoria_versions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('work_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('arranger_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('relation', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_assessment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_status_import', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rights_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('rights_confirmed', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('portal_since', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('publisher', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('license', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('proof_of_purchase', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('preservation_copy', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('musicxml_file', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('musicxml_valid', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('musicxml_date', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('musicxml_origin', Types::TEXT, ['notnull' => false]);
			$t->addColumn('musicxml_review', Types::TEXT, ['notnull' => false]);
			$t->addColumn('publication', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('publication_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('valid_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_set_by', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['work_id'], 'cantoria_ver_work');
		}

		if (!$schema->hasTable('cantoria_copies')) {
			$t = $schema->createTable('cantoria_copies');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('binder', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('shelfmark', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'active']);
			$t->addColumn('status_reason', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('status_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('originals_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('copies_count', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('last_counted', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('title_source', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('pages', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('versions_count', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('pdf_files', Types::TEXT, ['notnull' => false]);
			$t->addColumn('scan_formats', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('scan_files', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['shelfmark'], 'cantoria_copy_shelf');
			$t->addIndex(['version_id'], 'cantoria_copy_ver');
		}

		if (!$schema->hasTable('cantoria_files')) {
			$t = $schema->createTable('cantoria_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$t->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('mtime', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('listed', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('musicxml_valid', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('found', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['file_id'], 'cantoria_file_fileid');
			$t->addIndex(['copy_id'], 'cantoria_file_copy');
		}

		if (!$schema->hasTable('cantoria_audit')) {
			$t = $schema->createTable('cantoria_audit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('operation', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('time', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('user_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('roles', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('object_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('field', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('old_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('new_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['object_type', 'object_id'], 'cantoria_audit_obj');
			$t->addIndex(['operation'], 'cantoria_audit_op');
		}

		return $schema;
	}
}
