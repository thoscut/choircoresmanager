<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create the shares table.
 *
 * A share makes a score or a folder collection visible either via a secret
 * link (share_type 'link', anonymous, freely available scores only) or to the
 * members of a group (share_type 'group').
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000010000Date20261022000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('osm_shares')) {
			return null;
		}

		$table = $schema->createTable('osm_shares');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('share_type', Types::STRING, [
			'notnull' => true,
			'length' => 16,
		]);
		$table->addColumn('token', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('group_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('target_type', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('target_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('label', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('created_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'idx_osm_shares_token');
		$table->addIndex(['target_type', 'target_id'], 'idx_osm_shares_target');
		$table->addIndex(['group_id'], 'idx_osm_shares_group');

		return $schema;
	}
}
