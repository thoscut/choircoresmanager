<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add rights and publication information and a MusicXML file reference to scores.
 *
 * Columns added to osm_scores:
 * - rights_status: rights status ('public_domain', 'licensed', 'protected', 'open')
 * - rights_note: free text explaining the rights status
 * - rights_confirmed_at: date on which a human confirmed the rights status (null if unconfirmed)
 * - rights_confirmed_by: short text, e.g. initials, of the person who confirmed the rights status
 * - publication_status: publication status ('approved', 'to_clarify', 'blocked', 'internal_preservation_copy')
 * - publication_note: free text explaining the publication status
 * - publication_blocked_until: date until which a blocked score stays blocked (null if open-ended)
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
				'default' => 'open',
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

		if (!$table->hasColumn('rights_confirmed_at')) {
			$table->addColumn('rights_confirmed_at', Types::DATE_IMMUTABLE, [
				'notnull' => false,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('rights_confirmed_by')) {
			$table->addColumn('rights_confirmed_by', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('publication_status')) {
			$table->addColumn('publication_status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'to_clarify',
			]);
			$table->addIndex(['publication_status'], 'idx_osm_scores_publication');
			$changed = true;
		}

		if (!$table->hasColumn('publication_note')) {
			$table->addColumn('publication_note', Types::TEXT, [
				'notnull' => false,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('publication_blocked_until')) {
			$table->addColumn('publication_blocked_until', Types::DATE_IMMUTABLE, [
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
