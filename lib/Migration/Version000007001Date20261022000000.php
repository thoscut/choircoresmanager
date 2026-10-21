<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration splitting the rights status into a copyright status and a publication status.
 *
 * Installations that ran an earlier development version of Version000007000Date20261021000000
 * have a single rights_status column with the values 'free', 'unclear' and 'restricted'.
 * This step adds the publication columns and converts the old values:
 * - 'free' becomes rights 'public_domain' with publication 'free'
 * - 'unclear' becomes rights 'open'
 * - 'restricted' becomes rights 'protected'
 *
 * The column default of rights_status is left alone, because changing it rebuilds the
 * table on SQLite, which fails with the foreign keys pointing to it. The Score entity always
 * writes both status columns when a score is inserted.
 *
 * @psalm-suppress UnusedClass - Migration class discovered by Nextcloud framework
 */
class Version000007001Date20261022000000 extends SimpleMigrationStep {

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$table = $schema->getTable('osm_scores');
		$changed = false;

		if (!$table->hasColumn('publication_status')) {
			$table->addColumn('publication_status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'to_clarify',
			]);
			$table->addIndex(['publication_status'], 'idx_osm_scores_publication');
			$changed = true;
		}

		if (!$table->hasColumn('publication_blocked_until')) {
			$table->addColumn('publication_blocked_until', Types::DATE_IMMUTABLE, [
				'notnull' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$conversions = [
			'free' => ['rights_status' => 'public_domain', 'publication_status' => 'free'],
			'unclear' => ['rights_status' => 'open'],
			'restricted' => ['rights_status' => 'protected'],
		];
		foreach ($conversions as $oldValue => $newValues) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('osm_scores');
			foreach ($newValues as $column => $value) {
				$qb->set($column, $qb->createNamedParameter($value));
			}
			$qb->where($qb->expr()->eq('rights_status', $qb->createNamedParameter($oldValue)));
			$count = $qb->executeStatement();
			if ($count > 0) {
				$output->info("Converted $count scores with rights status '$oldValue'");
			}
		}
	}
}
