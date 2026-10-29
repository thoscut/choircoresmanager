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
 * Milestone 0.11, rights:
 *
 * - `cantoria_versions.melody_origin` and `text_origin`: `traditional` when
 *   the melody or the text is traditional and public domain (with evidence),
 *   `text_origin` also `none` for a version without text; null when the
 *   persons recorded say who wrote it. The protection proposal needs a
 *   composer and, for a version with text, a lyricist, unless the part is
 *   traditional.
 * - `cantoria_licenses.share_notice`: the notice a license asks for on shared
 *   pages ("By kind permission of …"), written by the catalog librarian.
 *
 * Nullable without a default, so that the columns can be added to filled
 * tables on every database.
 */
class Version001110Date20261029110000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		$versions = $schema->getTable('cantoria_versions');
		foreach (['melody_origin', 'text_origin'] as $column) {
			if (!$versions->hasColumn($column)) {
				$versions->addColumn($column, Types::STRING, ['notnull' => false, 'length' => 16]);
				$changed = true;
			}
		}
		$licenses = $schema->getTable('cantoria_licenses');
		if (!$licenses->hasColumn('share_notice')) {
			$licenses->addColumn('share_notice', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
