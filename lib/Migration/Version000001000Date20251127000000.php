<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial migration that creates all tables for Choir Scores.
 *
 * Tables created:
 * - chs_scores: Main scores table
 * - chs_tags: Tags for categorization
 * - chs_scores_tags: Linking table between scores and tags
 * - chs_comments: Comments on scores
 * - chs_folder_collections: Folder collections for organizing scores
 * - chs_scores_fc: Linking table between scores/score books and folder collections
 * - chs_score_books: Score books containing multiple scores
 * - chs_score_book_scores: Linking table between score books and scores
 * - chs_scorebooks_tags: Linking table between score books and tags
 *
 * @psalm-suppress UnusedClass - Migration class discovered by NextCloud framework
 */
class Version000001000Date20251127000000 extends SimpleMigrationStep {

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		// Create chs_scores table
		if (!$schema->hasTable('chs_scores')) {
			$table = $schema->createTable('chs_scores');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 300,
			]);
			$table->addColumn('title_short', Types::STRING, [
				'length' => 150,
				'notnull' => false,
			]);
			$table->addColumn('composer', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('arranger', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('publisher', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('year', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('difficulty', Types::FLOAT, [
				'notnull' => false,
			]);
			$table->addColumn('medley_contents', Types::STRING, [
				'notnull' => false,
				'length' => 4000,
			]);
			$table->addColumn('defects', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('physical_copies_status', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}

		// Create chs_tags table
		if (!$schema->hasTable('chs_tags')) {
			$table = $schema->createTable('chs_tags');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->setPrimaryKey(['id']);
		}

		// Create chs_scores_tags linking table
		if (!$schema->hasTable('chs_scores_tags')) {
			$table = $schema->createTable('chs_scores_tags');
			$table->addColumn('score_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('tag_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['score_id', 'tag_id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_scores'),
				['score_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_scores_tags_sc'
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_tags'),
				['tag_id'],
				['id'],
				['onDelete' => 'RESTRICT'],
				'fk_chs_scores_tags_tag'
			);
			$table->addIndex(['tag_id'], 'idx_chs_scores_tags_tid');
		}

		// Create chs_comments table
		if (!$schema->hasTable('chs_comments')) {
			$table = $schema->createTable('chs_comments');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('creation_date', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('score_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_scores'),
				['score_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_comments_score'
			);
			$table->addIndex(['score_id'], 'idx_chs_comments_sc_id');
		}

		// Create chs_score_books table
		if (!$schema->hasTable('chs_score_books')) {
			$table = $schema->createTable('chs_score_books');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 300,
			]);
			$table->addColumn('title_short', Types::STRING, [
				'length' => 150,
				'notnull' => false,
			]);
			$table->addColumn('composer', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('arranger', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('editor', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('publisher', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('year', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('difficulty', Types::FLOAT, [
				'notnull' => false,
			]);
			$table->addColumn('defects', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->addColumn('physical_copies_status', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}

		// Create chs_score_book_scores linking table
		if (!$schema->hasTable('chs_score_book_scores')) {
			$table = $schema->createTable('chs_score_book_scores');
			$table->addColumn('score_book_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('score_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('index', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['score_book_id', 'score_id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_score_books'),
				['score_book_id'],
				['id'],
				['onDelete' => 'RESTRICT'],
				'fk_chs_sbs_sb'
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_scores'),
				['score_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_sbs_sc'
			);
			// Index must be unique per score_book_id
			$table->addUniqueIndex(['score_book_id', 'index'], 'idx_chs_sbs_uniq_idx');
			// Score can only be in ONE score book total
			$table->addUniqueIndex(['score_id'], 'idx_chs_sbs_uniq_score');
		}

		// Create chs_scorebooks_tags linking table
		if (!$schema->hasTable('chs_scorebooks_tags')) {
			$table = $schema->createTable('chs_scorebooks_tags');
			$table->addColumn('score_book_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('tag_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['score_book_id', 'tag_id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_score_books'),
				['score_book_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_sbt_sb'
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_tags'),
				['tag_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_sbt_t'
			);
		}

		// Create chs_folder_collections table
		if (!$schema->hasTable('chs_folder_collections')) {
			$table = $schema->createTable('chs_folder_collections');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('collection_type', Types::STRING, [
				'notnull' => true,
				'length' => 50,
			]);
			$table->setPrimaryKey(['id']);
		}

		// Create chs_scores_fc linking table
		// Uses surrogate id key since score_id is nullable (XOR with score_book_id)
		if (!$schema->hasTable('chs_scores_fc')) {
			$table = $schema->createTable('chs_scores_fc');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('score_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('score_book_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('folder_collection_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('index', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_scores'),
				['score_id'],
				['id'],
				['onDelete' => 'RESTRICT'],
				'fk_chs_scores_fc_sc'
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_score_books'),
				['score_book_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_sfc_sb'
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('chs_folder_collections'),
				['folder_collection_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'fk_chs_scores_fc_fc'
			);
			$table->addIndex(['folder_collection_id'], 'idx_chs_scores_fc_fc_id');
			// Unique constraint for score per folder_collection (when score_id is not NULL)
			$table->addUniqueIndex(['folder_collection_id', 'score_id'], 'idx_chs_scores_fc_uniq_sc');
			// Unique constraint for score_book per folder_collection (when score_book_id is not NULL)
			$table->addUniqueIndex(['folder_collection_id', 'score_book_id'], 'idx_chs_sfc_uniq_sb');
			// Index must be unique per folder_collection_id (when not NULL)
			$table->addUniqueIndex(['folder_collection_id', 'index'], 'idx_chs_scores_fc_uniq');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
