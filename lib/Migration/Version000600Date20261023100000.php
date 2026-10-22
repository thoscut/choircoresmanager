<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Concerts in the calendar: an optional start and end time of a concert
 * (local time at the venue, HH:MM) and the time zone they refer to (the
 * Nextcloud time zone of the account that entered the time).
 *
 * The status column already holds the new status "cancelled".
 */
class Version000600Date20261023100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$t = $schema->getTable('notarch_events');
		$columns = [
			'start_time' => [Types::STRING, ['notnull' => false, 'length' => 5]],
			'end_time' => [Types::STRING, ['notnull' => false, 'length' => 5]],
			'time_zone' => [Types::STRING, ['notnull' => false, 'length' => 64]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if (!$t->hasColumn($name)) {
				$t->addColumn($name, $type, $columnOptions);
			}
		}

		return $schema;
	}
}
