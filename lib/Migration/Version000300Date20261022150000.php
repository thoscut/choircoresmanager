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
 * Milestone 0.3: tasks (queries to the conductor, checks, error reports) with
 * a location in the score, and internal excerpt links.
 */
class Version000300Date20261022150000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('cantoria_tasks')) {
			$t = $schema->createTable('cantoria_tasks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'question']);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('page', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('addressee', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('origin', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('question', Types::TEXT, ['notnull' => false]);
			$t->addColumn('answer', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('due', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('answered_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('answered_by_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('answered', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('closed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('closed', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('closing_note', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status', 'addressee'], 'cantoria_task_status');
			$t->addIndex(['copy_id'], 'cantoria_task_copy');
		}

		if (!$schema->hasTable('cantoria_links')) {
			$t = $schema->createTable('cantoria_links');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('file_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('measure_from', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('measure_to', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('voice', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('verse', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('tempo', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('circle', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'internal']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('revoked', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('hits', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token'], 'cantoria_link_token');
			$t->addIndex(['copy_id'], 'cantoria_link_copy');
		}

		return $schema;
	}
}
