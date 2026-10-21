<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add rights information and MusicXML file reference to scores.
 *
 * Columns added to osm_scores:
 * - rights_status: publication rights status ('free', 'unclear', 'restricted')
 * - rights_note: free text explaining the rights status
 * - musicxml_file: original file name of the uploaded MusicXML file (null if none)
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000007000Date20261021000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$table = $schema->getTable('osm_scores');
		$changed = false;

		if (!$table->hasColumn('rights_status')) {
			$table->addColumn('rights_status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'unclear',
			]);
			$table->addIndex(['rights_status'], 'idx_osm_scores_rights');
			$changed = true;
		}

		if (!$table->hasColumn('rights_note')) {
			$table->addColumn('rights_note', Types::TEXT, [
				'notnull' => false,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('musicxml_file')) {
			$table->addColumn('musicxml_file', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
