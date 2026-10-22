<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Db;

use Doctrine\DBAL\Schema\Table;
use OCA\Notenarchiv\Tests\Unit\Support\MigratedSchema;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every entity must match the table its mapper uses, as created by the
 * migrations: each column has a property and vice versa, the declared types
 * fit, and a nullable column never meets a property that cannot hold null
 * (loading such a row would fail with a TypeError).
 *
 * Mappers and entities are discovered in lib/Db, so new ones are covered
 * automatically.
 */
#[CoversNothing]
final class EntitySchemaTest extends TestCase {
	use MigratedSchema;

	private const COLUMN_TYPES = [
		'integer' => ['integer', 'bigint', 'smallint'],
		'string' => ['string', 'text'],
		'boolean' => ['boolean'],
	];

	/** @var array<string, Table> */
	private array $tables;

	#[\Override]
	protected function setUp(): void {
		$this->tables = $this->migratedTables();
	}

	public function testEveryMapperUsesATableFromTheMigrations(): void {
		$problems = [];
		foreach ($this->mappedEntities() as $tableName => $entityClass) {
			if (!isset($this->tables[$tableName])) {
				$problems[] = $entityClass . ': table ' . $tableName . ' is not created by the migrations';
			}
		}
		self::assertSame([], $problems);
		self::assertNotEmpty($this->mappedEntities());
	}

	public function testEveryColumnHasAnEntityProperty(): void {
		$problems = [];
		foreach ($this->mappedEntities() as $tableName => $entityClass) {
			$entity = new $entityClass();
			$types = $entity->getFieldTypes();
			foreach (($this->tables[$tableName] ?? null)?->getColumns() ?? [] as $column) {
				$property = $entity->columnToProperty($column->getName());
				if (!array_key_exists($property, $types)) {
					$problems[] = $tableName . '.' . $column->getName() . ' has no typed property $' . $property . ' in ' . $entityClass;
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testEveryEntityPropertyHasAColumn(): void {
		$problems = [];
		foreach ($this->mappedEntities() as $tableName => $entityClass) {
			$entity = new $entityClass();
			foreach (array_keys($entity->getFieldTypes()) as $property) {
				$column = $entity->propertyToColumn($property);
				if (!($this->tables[$tableName] ?? null)?->hasColumn($column)) {
					$problems[] = $entityClass . '::$' . $property . ' has no column ' . $tableName . '.' . $column;
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testDeclaredTypesFitTheColumns(): void {
		$problems = [];
		foreach ($this->mappedEntities() as $tableName => $entityClass) {
			$table = $this->tables[$tableName] ?? null;
			$entity = new $entityClass();
			foreach ($entity->getFieldTypes() as $property => $type) {
				$column = $entity->propertyToColumn($property);
				if ($table === null || !$table->hasColumn($column)) {
					continue;
				}
				$columnType = self::columnType($table->getColumn($column));
				if (!in_array($columnType, self::COLUMN_TYPES[$type] ?? [], true)) {
					$problems[] = sprintf('%s::$%s is declared as %s, column %s.%s is %s', $entityClass, $property, $type, $tableName, $column, $columnType);
				}
			}
		}
		self::assertSame([], $problems);
	}

	public function testNullableColumnsHaveNullableProperties(): void {
		$problems = [];
		foreach ($this->mappedEntities() as $tableName => $entityClass) {
			$table = $this->tables[$tableName] ?? null;
			$entity = new $entityClass();
			foreach (array_keys($entity->getFieldTypes()) as $property) {
				$column = $entity->propertyToColumn($property);
				if ($property === 'id' || $table === null || !$table->hasColumn($column)) {
					continue;
				}
				$reflection = new \ReflectionProperty($entityClass, $property);
				$type = $reflection->getType();
				if (!$table->getColumn($column)->getNotnull() && $type !== null && !$type->allowsNull()) {
					$problems[] = sprintf('%s.%s is nullable, but %s::$%s is %s', $tableName, $column, $entityClass, $property, (string)$type);
				}
				if ($type !== null && !$type->allowsNull() && !$reflection->hasDefaultValue()) {
					$problems[] = sprintf('%s::$%s (%s) has no default value', $entityClass, $property, (string)$type);
				}
			}
		}
		self::assertSame([], $problems);
	}

	/**
	 * @return array<string, class-string<Entity>> table name => entity class
	 */
	private function mappedEntities(): array {
		$result = [];
		foreach (glob(__DIR__ . '/../../../lib/Db/*Mapper.php') ?: [] as $file) {
			$class = 'OCA\\Notenarchiv\\Db\\' . basename($file, '.php');
			if (!is_subclass_of($class, QBMapper::class)) {
				continue;
			}
			$arguments = [];
			foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				$type = $parameter->getType();
				self::assertInstanceOf(\ReflectionNamedType::class, $type, $class . ': untyped constructor parameter');
				self::assertFalse($type->isBuiltin(), $class . ': scalar constructor parameter $' . $parameter->getName());
				/** @var class-string $dependency */
				$dependency = $type->getName();
				$arguments[] = $this->createStub($dependency);
			}
			/** @var QBMapper<Entity> $mapper */
			$mapper = new $class(...$arguments);
			/** @var class-string<Entity> $entityClass */
			$entityClass = (new \ReflectionProperty(QBMapper::class, 'entityClass'))->getValue($mapper);
			$result[$mapper->getTableName()] = $entityClass;
		}
		ksort($result);
		return $result;
	}
}
