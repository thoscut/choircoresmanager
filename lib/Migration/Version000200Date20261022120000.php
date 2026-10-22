<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Migration;

use Closure;
use OCA\Notenarchiv\Db\Row;
use OCA\Notenarchiv\Service\CatalogCsv;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Milestone 0.2: rights confirmation, the three separate releases (singers'
 * portal, publication, MusicXML review), rule-1 check of preservation copies,
 * licenses with single uses, and the proposals inbox.
 */
class Version000200Date20261022120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$t = $schema->getTable('notarch_versions');
		$columns = [
			'confirmed_by' => [Types::STRING, ['notnull' => false, 'length' => 64]],
			'confirmed_by_name' => [Types::STRING, ['notnull' => false, 'length' => 255]],
			'confirmed_at' => [Types::STRING, ['notnull' => false, 'length' => 10]],
			'confirmed_reason' => [Types::TEXT, ['notnull' => false]],
			'confirmed_evidence' => [Types::TEXT, ['notnull' => false]],
			'portal_removed_at' => [Types::STRING, ['notnull' => false, 'length' => 10]],
			'portal_removed_reason' => [Types::STRING, ['notnull' => false, 'length' => 1000]],
			'publication_status' => [Types::STRING, ['notnull' => false, 'length' => 32]],
			'review_status' => [Types::STRING, ['notnull' => false, 'length' => 32]],
			'rule1_status' => [Types::STRING, ['notnull' => false, 'length' => 32]],
			'rule1_count' => [Types::INTEGER, ['notnull' => false]],
			'rule1_checked' => [Types::STRING, ['notnull' => false, 'length' => 255]],
			'valid_reason' => [Types::STRING, ['notnull' => false, 'length' => 1000]],
			'key_signature' => [Types::STRING, ['notnull' => false, 'length' => 40]],
			'scoring' => [Types::STRING, ['notnull' => false, 'length' => 200]],
			'language' => [Types::STRING, ['notnull' => false, 'length' => 100]],
			'duration' => [Types::INTEGER, ['notnull' => false]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if (!$t->hasColumn($name)) {
				$t->addColumn($name, $type, $columnOptions);
			}
		}

		if (!$schema->hasTable('notarch_licenses')) {
			$t = $schema->createTable('notarch_licenses');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('holder', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('uses', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('quantity', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('valid_from', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('valid_until', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id'], 'notarch_lic_ver');
		}

		if (!$schema->hasTable('notarch_proposals')) {
			$t = $schema->createTable('notarch_proposals');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('object_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('new_value', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('decided', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('decision_reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status'], 'notarch_prop_status');
			$t->addIndex(['object_type', 'object_id'], 'notarch_prop_obj');
		}

		return $schema;
	}

	/**
	 * Derives the publication status of existing versions from the legacy
	 * publication note once.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'publication')->from('notarch_versions')->where($select->expr()->isNull('publication_status'));
		$result = $select->executeQuery();
		$update = $this->db->getQueryBuilder();
		$update->update('notarch_versions')
			->set('publication_status', $update->createParameter('status'))
			->where($update->expr()->eq('id', $update->createParameter('id')));
		foreach ($result->fetchAllAssociative() as $row) {
			$status = CatalogCsv::legacyPublicationStatus(Row::stringOrNull($row['publication']));
			if ($status !== null) {
				$update->setParameter('status', $status)->setParameter('id', Row::int($row['id']))->executeStatement();
			}
		}
		$result->closeCursor();
	}
}
