<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

/**
 * Sorting of result rows by keys computed while the rows are built, so that
 * the sort order is typed and independent of the row structure.
 */
final class Sort {
	/**
	 * Orders items by their key tuples. Tuples are compared element by
	 * element: integers by value, strings in natural order (shelf mark
	 * "5-2" before "5-10"). Items with equal keys keep their order.
	 *
	 * @template T
	 * @param list<T> $items
	 * @param list<list<int|string>> $keys one tuple per item, same order as $items
	 * @return list<T>
	 */
	public static function byKeys(array $items, array $keys): array {
		$order = array_keys($items);
		usort($order, static function (int $a, int $b) use ($keys): int {
			$left = $keys[$a] ?? [];
			$right = $keys[$b] ?? [];
			foreach ($left as $i => $value) {
				$other = $right[$i] ?? '';
				$result = is_int($value) && is_int($other) ? $value <=> $other : strnatcmp((string)$value, (string)$other);
				if ($result !== 0) {
					return $result;
				}
			}
			return $a <=> $b;
		});
		$sorted = [];
		foreach ($order as $index) {
			$sorted[] = $items[$index];
		}
		return $sorted;
	}
}
