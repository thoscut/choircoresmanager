<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Milestone 0.7, sharing: the public repertoire page. A work can be shown
 * there (chosen by the catalog librarian, only with a version confirmed as
 * public domain) and gets optional links to its pages on CPDL and IMSLP.
 *
 * Links for choir members and public links need no new column: they are
 * excerpt links with the circle `members` or `public` (column `circle` of
 * notarch_links, since 0.3).
 *
 * Booleans are nullable with a default, as Nextcloud requires; names stay
 * within the limits Nextcloud checks for Oracle (columns 30 characters).
 */
class Version000710Date20261025100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('notarch_works')) {
			return null;
		}
		$table = $schema->getTable('notarch_works');
		$changed = false;
		if (!$table->hasColumn('public_repertoire')) {
			$table->addColumn('public_repertoire', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		foreach (['cpdl_url', 'imslp_url'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::STRING, ['notnull' => false, 'length' => 500]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
