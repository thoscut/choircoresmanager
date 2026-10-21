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
 * - repertoire_status: one of active, dormant, archived (null = not set)
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
		if (!$table->hasColumn('repertoire_status')) {
			$table->addColumn('repertoire_status', Types::STRING, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addIndex(['repertoire_status'], 'idx_osm_scores_rep_status');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
