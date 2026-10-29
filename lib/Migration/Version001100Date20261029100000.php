<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Migration;

use Closure;
use OCA\Cantoria\Db\ExcerptLink;
use OCA\Cantoria\Db\Row;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/**
 * Link tokens are stored as hashes (milestone 0.11):
 *
 * - `cantoria_links.token_secret`: the token encrypted with the secret of
 *   the instance, so that the creator and the catalog librarian can copy
 *   the link again; nullable.
 * - `cantoria_links.token` of existing links becomes the SHA-256 hash of the
 *   token (64 characters, the length of the column); the token moves into
 *   `token_secret`. Tokens are 32 characters, so a row that already holds a
 *   hash is left alone and the step can run twice.
 */
class Version001100Date20261029100000 extends SimpleMigrationStep {
	/** Length of the tokens in addresses */
	private const TOKEN_LENGTH = 32;

	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$links = $schema->getTable('cantoria_links');
		if ($links->hasColumn('token_secret')) {
			return null;
		}
		$links->addColumn('token_secret', Types::TEXT, ['notnull' => false]);
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'token')->from('cantoria_links');
		$result = $select->executeQuery();
		$update = $this->db->getQueryBuilder();
		$update->update('cantoria_links')
			->set('token', $update->createParameter('hash'))
			->set('token_secret', $update->createParameter('secret'))
			->where($update->expr()->eq('id', $update->createParameter('id')));
		foreach ($result->fetchAllAssociative() as $row) {
			$token = Row::stringOrNull($row['token']);
			if ($token === null || strlen($token) !== self::TOKEN_LENGTH) {
				continue;
			}
			$update->setParameter('hash', ExcerptLink::hashToken($token))
				->setParameter('secret', $this->crypto->encrypt($token))
				->setParameter('id', Row::int($row['id']), IQueryBuilder::PARAM_INT)
				->executeStatement();
		}
		$result->closeCursor();
	}
}
