<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use Doctrine\DBAL\Schema\Table;
use OCA\Notenarchiv\Service\CatalogCsv;
use OCA\Notenarchiv\Service\Fields;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Tests\Unit\Support\MigratedSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The field registry is the single source of truth for validation, the edit
 * forms, import and export. These tests keep it consistent with the database
 * schema created by the migrations.
 *
 * Each test collects all inconsistencies and reports them together.
 */
#[CoversClass(Fields::class)]
final class FieldsTest extends TestCase {
	use MigratedSchema;

	private const TABLES = [
		Fields::WORK => 'notarch_works',
		Fields::VERSION => 'notarch_versions',
		Fields::COPY => 'notarch_copies',
	];

	/**
	 * Columns that are deliberately not in the registry: keys, relations and
	 * bookkeeping maintained by the app itself (merged_into: merge workflow).
	 */
	private const INTERNAL_COLUMNS = [
		Fields::WORK => ['id', 'merged_into', 'created', 'modified'],
		Fields::VERSION => ['id', 'work_id', 'created', 'modified'],
		Fields::COPY => ['id', 'version_id', 'sort_order', 'created', 'modified'],
	];

	private const KNOWN_TYPES = [
		Fields::TYPE_TEXT, Fields::TYPE_LONGTEXT, Fields::TYPE_NUMBER,
		Fields::TYPE_DATE, Fields::TYPE_CHOICE, Fields::TYPE_BOOLEAN,
	];

	/** @var array<string, Table> */
	private array $tables;

	#[\Override]
	protected function setUp(): void {
		$this->tables = $this->migratedTables();
	}

	public function testRegistryHasExactlyTheThreeLevels(): void {
		self::assertSame(Fields::LEVELS, array_keys(Fields::all()));
		self::assertSame(Fields::LEVELS, array_keys(self::TABLES));
	}

	public function testEveryColumnHasAFieldOrIsDeliberatelyInternal(): void {
		$problems = [];
		foreach (self::TABLES as $level => $tableName) {
			foreach ($this->table($tableName)->getColumns() as $column) {
				$name = $column->getName();
				if (Fields::info($level, $name) === null && !in_array($name, self::INTERNAL_COLUMNS[$level], true)) {
					$problems[] = $tableName . '.' . $name . ' has no field in the registry and is not listed as internal';
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testInternalColumnsExistAndAreNotFields(): void {
		$problems = [];
		foreach (self::INTERNAL_COLUMNS as $level => $columns) {
			foreach ($columns as $name) {
				if (!$this->table(self::TABLES[$level])->hasColumn($name)) {
					$problems[] = self::TABLES[$level] . '.' . $name . ' is listed as internal but does not exist';
				}
				if (Fields::info($level, $name) !== null) {
					$problems[] = $level . '.' . $name . ' is listed as internal but is a registry field';
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testEveryFieldHasAColumn(): void {
		$problems = [];
		foreach (Fields::all() as $level => $fields) {
			foreach (array_keys($fields) as $field) {
				if (!$this->table(self::TABLES[$level])->hasColumn($field)) {
					$problems[] = $level . '.' . $field . ' has no column in ' . self::TABLES[$level];
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testMaxLengthMatchesTheColumnLength(): void {
		$problems = [];
		foreach ($this->fieldsWithColumns() as [$level, $field, $info, $column]) {
			$type = self::columnType($column);
			$max = $info['max'] ?? null;
			if ($type === 'string' && $max !== $column->getLength()) {
				$problems[] = sprintf('%s.%s: max is %s, column length is %d', $level, $field, var_export($max, true), (int)$column->getLength());
			}
			if ($type !== 'string' && $max !== null) {
				$problems[] = sprintf('%s.%s: max %d set, but the column is %s without a length', $level, $field, $max, $type);
			}
		}
		self::assertSame([], $problems);
	}

	public function testFieldTypeFitsTheColumnType(): void {
		$expected = [
			Fields::TYPE_TEXT => ['string'],
			Fields::TYPE_LONGTEXT => ['text'],
			Fields::TYPE_NUMBER => ['integer', 'bigint', 'smallint'],
			Fields::TYPE_DATE => ['string'],
			Fields::TYPE_CHOICE => ['string'],
			Fields::TYPE_BOOLEAN => ['boolean'],
		];
		$problems = [];
		foreach ($this->fieldsWithColumns() as [$level, $field, $info, $column]) {
			$type = self::columnType($column);
			if (!in_array($type, $expected[$info['type']] ?? [], true)) {
				$problems[] = sprintf('%s.%s: field type %s does not fit column type %s', $level, $field, $info['type'], $type);
			}
			if ($info['type'] === Fields::TYPE_DATE && $column->getLength() !== 10) {
				$problems[] = sprintf('%s.%s: a date (YYYY-MM-DD) needs a column of length 10', $level, $field);
			}
			if ($info['type'] === Fields::TYPE_CHOICE) {
				foreach (array_keys($info['options'] ?? []) as $option) {
					if (strlen($option) > (int)$column->getLength()) {
						$problems[] = sprintf('%s.%s: option "%s" is longer than the column', $level, $field, $option);
					}
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testNotNullFieldsMatchTheColumns(): void {
		$problems = [];
		foreach ($this->fieldsWithColumns() as [$level, $field, , $column]) {
			if (Fields::isNotNull($level, $field) !== $column->getNotnull()) {
				$problems[] = sprintf('%s.%s: Fields::NOT_NULL says %s, the column is %s', $level, $field,
					Fields::isNotNull($level, $field) ? 'NOT NULL' : 'nullable', $column->getNotnull() ? 'NOT NULL' : 'nullable');
			}
		}
		self::assertSame([], $problems);
	}

	public function testRegistryEntriesAreWellFormed(): void {
		$problems = [];
		foreach (Fields::all() as $level => $fields) {
			foreach ($fields as $field => $info) {
				$id = $level . '.' . $field;
				if (!in_array($info['type'], self::KNOWN_TYPES, true)) {
					$problems[] = $id . ': unknown type ' . $info['type'];
				}
				if (trim($info['label']) === '') {
					$problems[] = $id . ': empty label';
				}
				if (array_diff($info['write'], Roles::ALL) !== []) {
					$problems[] = $id . ': unknown write role ' . implode(', ', array_diff($info['write'], Roles::ALL));
				}
				if (($info['type'] === Fields::TYPE_CHOICE) !== isset($info['options'])) {
					$problems[] = $id . ': options must be given exactly for choice fields';
				}
				if (preg_match('/^[a-z][a-z0-9_]*$/', $field) !== 1) {
					$problems[] = $id . ': field names are snake_case column names';
				}
			}
		}
		self::assertSame([], $problems);
	}

	/**
	 * A typo in one of the internal lists would silently drop a length check
	 * or the protection of a decision field.
	 */
	public function testInternalListsReferToExistingFields(): void {
		$class = new \ReflectionClass(Fields::class);
		$lists = [
			'MAX_LENGTH' => $class->getConstant('MAX_LENGTH'),
			'DECISIONS' => $class->getConstant('DECISIONS'),
			'NOT_NULL' => Fields::NOT_NULL,
		];
		$problems = [];
		foreach ($lists as $name => $list) {
			self::assertIsArray($list, $name);
			foreach ($list as $level => $entries) {
				$fields = is_array($entries) && array_is_list($entries) ? $entries : array_keys((array)$entries);
				foreach ($fields as $field) {
					if (Fields::info((string)$level, (string)$field) === null) {
						$problems[] = $name . ' refers to ' . $level . '.' . $field . ', which is not a field';
					}
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testDecisionFieldsAreMarked(): void {
		foreach (['rights_confirmed', 'portal_since', 'valid_since', 'valid_set_by'] as $field) {
			self::assertTrue(Fields::info(Fields::VERSION, $field)['decision'] ?? false, $field);
		}
		self::assertArrayNotHasKey('decision', Fields::info(Fields::VERSION, 'rights_status') ?? []);
	}

	public function testEveryLegacyColumnMapsToAnExistingField(): void {
		$problems = [];
		foreach (CatalogCsv::LEGACY_COLUMNS as $header => [$level, $field]) {
			if (Fields::info($level, $field) === null) {
				$problems[] = 'CSV column "' . $header . '" maps to ' . $level . '.' . $field . ', which is not a field';
			}
		}
		self::assertSame([], $problems);
	}

	public function testLegacyColumnsMapToDistinctFields(): void {
		$targets = array_map(fn (array $target): string => implode('.', $target), array_values(CatalogCsv::LEGACY_COLUMNS));
		self::assertSame(array_values(array_unique($targets)), $targets);
	}

	public function testLegacyRightsStatusValuesAreChoiceKeys(): void {
		foreach (CatalogCsv::LEGACY_RIGHTS_STATUS as $legacy => $key) {
			self::assertArrayHasKey($key, Fields::RIGHTS_STATUS, $legacy);
		}
	}

	public function testInfoOfUnknownFieldIsNull(): void {
		self::assertNull(Fields::info(Fields::WORK, 'no_such_field'));
		self::assertNull(Fields::info('no_such_level', 'title'));
	}

	private function table(string $name): Table {
		self::assertArrayHasKey($name, $this->tables, 'table ' . $name . ' is not created by the migrations');
		return $this->tables[$name];
	}

	/**
	 * @return list<array{0: string, 1: string, 2: array{type: string, label: string, write: list<string>, options?: array<string, string>, max?: int, decision?: bool}, 3: \Doctrine\DBAL\Schema\Column}>
	 */
	private function fieldsWithColumns(): array {
		$result = [];
		foreach (Fields::all() as $level => $fields) {
			$table = $this->table(self::TABLES[$level]);
			foreach ($fields as $field => $info) {
				if ($table->hasColumn($field)) {
					$result[] = [$level, $field, $info, $table->getColumn($field)];
				}
			}
		}
		return $result;
	}
}
