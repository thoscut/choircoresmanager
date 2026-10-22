<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add repertoire management columns to osm_scores.
 *
 * Columns added:
 * - signature: catalogue number from the Werkverzeichnis (e.g. "5-22")
 * - lyricist: author of the lyrics
 * - voicing: choir voicing (e.g. "TTBB")
 * - language: language of the lyrics
 * - inventory_status: one of active, retired, missing, lent (default active)
 * - last_performed_imported: date of the last performance as documented in an imported catalogue
 * - catalogue_extra: JSON object with the catalogue columns that are not mapped to a field
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000008000Date20261021000000 extends SimpleMigrationStep {

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('osm_scores')) {
			return null;
		}

		$table = $schema->getTable('osm_scores');
		$changed = false;

		if (!$table->hasColumn('signature')) {
			$table->addColumn('signature', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addIndex(['signature'], 'idx_osm_scores_signature');
			$changed = true;
		}
		if (!$table->hasColumn('lyricist')) {
			$table->addColumn('lyricist', Types::STRING, [
				'notnull' => false,
				'length' => 300,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('voicing')) {
			$table->addColumn('voicing', Types::STRING, [
				'notnull' => false,
				'length' => 50,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('language')) {
			$table->addColumn('language', Types::STRING, [
				'notnull' => false,
				'length' => 100,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('inventory_status')) {
			$table->addColumn('inventory_status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'active',
			]);
			$table->addIndex(['inventory_status'], 'idx_osm_scores_inv_status');
			$changed = true;
		}
		if (!$table->hasColumn('last_performed_imported')) {
			$table->addColumn('last_performed_imported', Types::DATE_IMMUTABLE, [
				'notnull' => false,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('catalogue_extra')) {
			// TEXT rather than JSON: some backends reorder the keys of JSON columns,
			// but the column order of the catalogue has to survive an export
			$table->addColumn('catalogue_extra', Types::TEXT, [
				'notnull' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
