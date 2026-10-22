<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Whole pieces and the scan in links for choir members and public links:
 *
 * - `notarch_links.scan_file_id`: the PDF scan (`notarch_files.id`, role
 *   `score_pdf`) that a link for choir members or a public link offers
 *   besides the notes; null for no scan. A link without measures shows the
 *   whole piece; that needs no column (`measure_from` and `measure_to` are
 *   nullable since milestone 0.3).
 *
 * Nullable without a default, so that the column can be added to a filled
 * table on every database, Oracle included; the name stays within the 30
 * characters Nextcloud checks for Oracle.
 */
class Version000830Date20261028100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$links = $schema->getTable('notarch_links');
		if ($links->hasColumn('scan_file_id')) {
			return null;
		}
		$links->addColumn('scan_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		return $schema;
	}
}
