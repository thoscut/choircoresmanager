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
 * Milestone 0.5: register of persons with their contributions per version
 * (basis of the protection term proposal), merges of works and copies that
 * can be undone, manual file links and checksum checks of the archive files.
 *
 * Text columns are nullable and boolean columns are nullable without empty
 * string defaults, because Oracle treats empty strings as NULL.
 */
class Version000500Date20261022190000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('cantoria_persons')) {
			$t = $schema->createTable('cantoria_persons');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('gnd', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('wikidata', Types::STRING, ['notnull' => false, 'length' => 20]);
			$t->addColumn('born', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('born_precision', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('died', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('died_precision', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('life_dates_text', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('identification', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('checked_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('checked_at', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['name'], 'cantoria_person_name');
		}

		if (!$schema->hasTable('cantoria_contributions')) {
			$t = $schema->createTable('cantoria_contributions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'unspecified']);
			$t->addColumn('text_for_composition', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id'], 'cantoria_contrib_ver');
			$t->addIndex(['person_id'], 'cantoria_contrib_person');
		}

		if (!$schema->hasTable('cantoria_merges')) {
			$t = $schema->createTable('cantoria_merges');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('source_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('moved_ids', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('undone', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('undone_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['kind', 'source_id'], 'cantoria_merge_source');
			$t->addIndex(['kind', 'target_id'], 'cantoria_merge_target');
		}

		$t = $schema->getTable('cantoria_works');
		if (!$t->hasColumn('merged_into')) {
			$t->addColumn('merged_into', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}

		$t = $schema->getTable('cantoria_files');
		$columns = [
			'linked_manually' => [Types::BOOLEAN, ['notnull' => false, 'default' => false]],
			'sha256_at' => [Types::BIGINT, ['notnull' => false]],
			'sha256_mismatch' => [Types::BOOLEAN, ['notnull' => false, 'default' => false]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if (!$t->hasColumn($name)) {
				$t->addColumn($name, $type, $columnOptions);
			}
		}

		return $schema;
	}
}
