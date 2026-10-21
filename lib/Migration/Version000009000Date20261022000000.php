<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add a PDF file reference to scores.
 *
 * Columns added to osm_scores:
 * - pdf_file: original file name of the uploaded PDF file (null if none)
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000009000Date20261022000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$table = $schema->getTable('osm_scores');
		if ($table->hasColumn('pdf_file')) {
			return null;
		}

		$table->addColumn('pdf_file', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		return $schema;
	}
}
