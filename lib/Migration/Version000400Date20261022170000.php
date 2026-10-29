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
 * Milestone 0.4: events (concerts and song lists) with their items. Performed
 * concerts are the source of the derived repertoire figures.
 */
class Version000400Date20261022170000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('notarch_events')) {
			$t = $schema->createTable('notarch_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'concert']);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('event_date', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('period', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('is_public', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('board_agreed', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('imported', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('source', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['kind', 'event_date'], 'notarch_event_date');
		}

		if (!$schema->hasTable('notarch_event_items')) {
			$t = $schema->createTable('notarch_event_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('event_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('copy_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('work_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('title_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('composer_text', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('participation', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('assignment', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'certain']);
			$t->addColumn('candidates', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('state', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('counts', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('sung', Types::BOOLEAN, ['notnull' => false]);
			$t->addColumn('struck', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('encore', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('duration', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['event_id', 'position'], 'notarch_item_event');
			$t->addIndex(['work_id'], 'notarch_item_work');
			$t->addIndex(['assignment'], 'notarch_item_assign');
		}

		return $schema;
	}
}
