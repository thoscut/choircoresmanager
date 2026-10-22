<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add gema_ids column to chs_scores table.
 *
 * This column stores GEMA IDs as a JSON-encoded string, similar to medley_contents.
 *
 * @psalm-suppress UnusedClass - Migration class discovered by NextCloud framework
 */
class Version000003000Date20251201154248 extends SimpleMigrationStep {

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('chs_scores')) {
			$table = $schema->getTable('chs_scores');
			if (!$table->hasColumn('gema_ids')) {
				$table->addColumn('gema_ids', Types::STRING, [
					'notnull' => false,
					'length' => 4000,
				]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
