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
 * Milestone 0.6: reviewing transcriptions. Reports of a transcription
 * (format bericht/1) with the received text as evidence, and the review
 * points made from them, each with a location in the score, the
 * responsible role and a status instead of deletion.
 *
 * Names stay within the limits Nextcloud checks for Oracle (tables 27,
 * columns and indexes 30 characters), like the other tables of the app.
 */
class Version000700Date20261024120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('notarch_review_reports')) {
			$t = $schema->createTable('notarch_review_reports');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// notarch_files.id of the report file (file sync), null when it came through the API
			$t->addColumn('report_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'api']);
			// SHA-256 of the received text
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('round', Types::STRING, ['notnull' => false, 'length' => 40]);
			$t->addColumn('report_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('basename', Types::STRING, ['notnull' => true, 'length' => 255]);
			// notarch_files.id of the MusicXML the report is about and its SHA-256
			$t->addColumn('musicxml_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('musicxml_sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('method', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('origin', Types::TEXT, ['notnull' => false]);
			$t->addColumn('review', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reasoning', Types::TEXT, ['notnull' => false]);
			$t->addColumn('rights_class', Types::STRING, ['notnull' => false, 'length' => 16]);
			// JSON: the known fields shown with the version (template, extent, persons, …)
			$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
			// the received text, unchanged (evidence)
			$t->addColumn('raw', Types::TEXT, ['notnull' => false]);
			$t->addColumn('superseded_by', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['copy_id', 'sha256'], 'notarch_rrep_copy');
			$t->addIndex(['version_id'], 'notarch_rrep_version');
			$t->addIndex(['report_file_id'], 'notarch_rrep_file');
		}

		if (!$schema->hasTable('notarch_review_points')) {
			$t = $schema->createTable('notarch_review_points');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('report_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// notarch_files.id of the MusicXML and its SHA-256 when the point was created
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('measures', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('page', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('text', Types::TEXT, ['notnull' => false]);
			// identifies the same point in a later report of the copy
			$t->addColumn('point_key', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('correction', Types::TEXT, ['notnull' => false]);
			$t->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('decided_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('decided', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('decision_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('resolved_report_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id', 'status'], 'notarch_rpt_version');
			$t->addIndex(['copy_id', 'status'], 'notarch_rpt_copy');
			$t->addIndex(['report_id'], 'notarch_rpt_report');
			$t->addIndex(['status'], 'notarch_rpt_status');
		}

		return $schema;
	}
}
