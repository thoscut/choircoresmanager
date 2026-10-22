<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add folder collection versioning support - Step 1.
 *
 * This migration:
 * 1. Creates the chs_folder_collection_versions table
 * 2. Adds folder_collection_version_id column to chs_scores_fc
 * 3. Adds active_version_id column to chs_folder_collections
 *
 * @psalm-suppress UnusedClass - Migration class discovered by NextCloud framework
 */
class Version000002000Date20251129000000 extends SimpleMigrationStep {

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Create chs_folder_collection_versions table
		if (!$schema->hasTable('chs_fc_versions')) {
			$table = $schema->createTable('chs_fc_versions');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('folder_collection_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('valid_from', Types::DATE_IMMUTABLE, [
				'notnull' => true,
			]);
			$table->addColumn('valid_to', Types::DATE_IMMUTABLE, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_folder_collections'),
				['folder_collection_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_fcv_fc'
			);
			$table->addIndex(['folder_collection_id'], 'idx_chs_fcv_fc_id');
		}

		// Add folder_collection_version_id to chs_scores_fc (nullable for now, will be populated in postSchemaChange)
		$scoresFcTable = $schema->getTable('chs_scores_fc');
		if (!$scoresFcTable->hasColumn('folder_collection_version_id')) {
			$scoresFcTable->addColumn('folder_collection_version_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
		}

		// Add active_version_id to chs_folder_collections (nullable)
		$folderCollectionsTable = $schema->getTable('chs_folder_collections');
		if (!$folderCollectionsTable->hasColumn('active_version_id')) {
			$folderCollectionsTable->addColumn('active_version_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
