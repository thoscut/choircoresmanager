<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Support;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * Runs all migrations of the app, in order, against an empty in-memory schema
 * and returns the resulting tables. Lets tests compare the code (field
 * registry, entities) with the database schema without a database.
 *
 * @psalm-require-extends TestCase
 */
trait MigratedSchema {
	/**
	 * @return array<string, Table> table name (without the instance prefix) => table
	 */
	protected function migratedTables(): array {
		/** @var array<string, Table> $tables */
		$tables = [];

		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			function (string $name) use (&$tables): bool {
				return isset($tables[$name]);
			});
		$schema->method('createTable')->willReturnCallback(
			function (string $name) use (&$tables): Table {
				if (isset($tables[$name])) {
					throw new \LogicException('Table ' . $name . ' is created twice');
				}
				return $tables[$name] = new Table($name);
			});
		$schema->method('getTable')->willReturnCallback(
			function (string $name) use (&$tables): Table {
				return $tables[$name] ?? throw new \LogicException('Table ' . $name . ' does not exist yet');
			});
		$schema->method('dropTable')->willReturnCallback(
			function (string $name) use (&$tables, &$schema): ISchemaWrapper {
				unset($tables[$name]);
				return $schema;
			});
		$schema->method('getTableNamesWithoutPrefix')->willReturnCallback(
			function () use (&$tables): array {
				return array_keys($tables);
			});

		$output = $this->createStub(IOutput::class);
		foreach (self::migrationClasses() as $class) {
			$this->instantiateMigration($class)->changeSchema($output, fn (): ISchemaWrapper => $schema, []);
		}
		return $tables;
	}

	/**
	 * Doctrine type name of a column, e.g. "string", "text", "bigint".
	 */
	protected static function columnType(Column $column): string {
		return Type::getTypeRegistry()->lookupName($column->getType());
	}

	/**
	 * @return list<class-string<SimpleMigrationStep>> in the order Nextcloud applies them
	 */
	private static function migrationClasses(): array {
		$files = glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: [];
		sort($files, SORT_STRING);
		$classes = [];
		foreach ($files as $file) {
			$class = 'OCA\\Notenarchiv\\Migration\\' . basename($file, '.php');
			if (!is_subclass_of($class, SimpleMigrationStep::class)) {
				throw new \LogicException($class . ' is not a migration step');
			}
			$classes[] = $class;
		}
		if ($classes === []) {
			throw new \LogicException('No migrations found');
		}
		return $classes;
	}

	/**
	 * Creates a migration with test doubles for its constructor dependencies.
	 *
	 * @param class-string<SimpleMigrationStep> $class
	 */
	private function instantiateMigration(string $class): SimpleMigrationStep {
		$arguments = [];
		foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
				throw new \LogicException('Cannot create ' . $class . ': unsupported constructor parameter $' . $parameter->getName());
			}
			/** @var class-string $dependency */
			$dependency = $type->getName();
			$arguments[] = $this->createStub($dependency);
		}
		return new $class(...$arguments);
	}
}
