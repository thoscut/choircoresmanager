<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add a link to a PDF file to scores.
 *
 * Columns added to chs_scores:
 * - pdf_file: name of the linked PDF file at the time it was linked, for display (null if none)
 * - pdf_file_id: Nextcloud file id of the linked PDF file (null if none)
 * - pdf_linked_by: user id of the person who linked the file; the file is read with this user's access
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000009000Date20261022000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$table = $schema->getTable('chs_scores');
		$changed = false;

		if (!$table->hasColumn('pdf_file')) {
			$table->addColumn('pdf_file', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('pdf_file_id')) {
			$table->addColumn('pdf_file_id', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('pdf_linked_by')) {
			$table->addColumn('pdf_linked_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
