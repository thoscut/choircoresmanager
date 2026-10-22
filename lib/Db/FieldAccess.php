<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Access entity values by column name (snake_case), as used by the field
 * registry, and serialize entities with column names as keys.
 *
 * The entities of this app only hold strings, integers, booleans and null.
 *
 * @psalm-require-extends Entity
 */
trait FieldAccess {
	/**
	 * @return array<string, string|int|bool|null> column => value
	 */
	public function jsonSerialize(): array {
		$data = [];
		foreach (array_keys($this->getFieldTypes()) as $property) {
			$column = $this->propertyToColumn($property);
			$data[$column] = $this->getValue($column);
		}
		return $data;
	}

	public function getValue(string $column): string|int|bool|null {
		$getter = 'get' . ucfirst($this->columnToProperty($column));
		return self::plainValue($this->$getter(), $column);
	}

	public function setValue(string $column, mixed $value): void {
		$setter = 'set' . ucfirst($this->columnToProperty($column));
		$this->$setter($value);
	}

	private static function plainValue(mixed $value, string $column): string|int|bool|null {
		if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
			return $value;
		}
		throw new \UnexpectedValueException('Column ' . $column . ' does not hold a plain value');
	}
}
