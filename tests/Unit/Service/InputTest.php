<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\Input;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Input::class)]
final class InputTest extends TestCase {
	public function testValuesKeepsPlainValues(): void {
		self::assertSame(['a' => 'x', 'b' => 1, 'c' => 1.5, 'd' => false, 'e' => null, '7' => 'seven'],
			Input::values(['a' => 'x', 'b' => 1, 'c' => 1.5, 'd' => false, 'e' => null, 7 => 'seven']));
	}

	public function testValuesRefusesNestedData(): void {
		try {
			Input::values(['title' => ['de' => 'Ave']]);
			self::fail('nested data must be refused');
		} catch (InvalidInputException $e) {
			self::assertSame('"%s": value not allowed.', $e->getMessage());
			self::assertSame(['title'], $e->getParameters());
		}
	}

	/**
	 * @return iterable<string, array{0: mixed, 1: ?string}>
	 */
	public static function texts(): iterable {
		yield 'text' => ['Ave', 'Ave'];
		yield 'text with blanks is kept' => [' Ave ', ' Ave '];
		yield 'blank' => ['  ', null];
		yield 'null' => [null, null];
		yield 'number' => [12, '12'];
		yield 'zero' => [0, '0'];
		yield 'zero as text' => ['0', '0'];
	}

	#[DataProvider('texts')]
	public function testText(mixed $value, ?string $expected): void {
		self::assertSame($expected, Input::text($value, 'Field'));
	}

	public function testTextRefusesBooleans(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('"%s" must be text.');
		Input::text(true, 'Field');
	}

	/**
	 * @return iterable<string, array{0: mixed, 1: bool}>
	 */
	public static function booleans(): iterable {
		yield 'true' => [true, true];
		yield 'false' => [false, false];
		yield '1' => [1, true];
		yield '0' => [0, false];
		yield '"1"' => ['1', true];
		yield '"0"' => ['0', false];
		yield '"true"' => ['true', true];
		yield '"false"' => ['false', false];
		yield '"yes"' => ['yes', true];
		yield '"no"' => ['no', false];
		yield '"on"' => ['on', true];
		yield '"off"' => ['off', false];
		yield 'empty text (unchecked form field)' => ['', false];
	}

	#[DataProvider('booleans')]
	public function testBool(mixed $value, bool $expected): void {
		self::assertSame($expected, Input::bool($value, 'Field'));
	}

	/**
	 * @return iterable<string, array{0: mixed}>
	 */
	public static function notBooleans(): iterable {
		yield 'null' => [null];
		yield 'other text' => ['maybe'];
		yield 'other number' => [2];
		yield 'float' => [1.0];
		yield 'list' => [[true]];
	}

	#[DataProvider('notBooleans')]
	public function testBoolRefusesOtherValues(mixed $value): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('"%s" must be yes or no.');
		Input::bool($value, 'Field');
	}

	public function testStrings(): void {
		self::assertSame(['binder', 'portal'], Input::strings('binder, portal,', 'Uses'));
		self::assertSame(['binder', '0'], Input::strings(['binder', '0', ''], 'Uses'), '"0" is kept');
		self::assertSame(['5'], Input::strings([5], 'Uses'));
		self::assertSame([], Input::strings(null, 'Uses'));
		self::assertSame([], Input::strings('', 'Uses'));
	}

	public function testStringsRefusesNestedLists(): void {
		$this->expectException(InvalidInputException::class);
		Input::strings([['binder']], 'Uses');
	}

	public function testStringList(): void {
		self::assertSame(['Notenwart', '0'], Input::stringList(['Notenwart', '0'], 'Groups'));
		self::assertSame([], Input::stringList([], 'Groups'));
	}

	/**
	 * @return iterable<string, array{0: mixed}>
	 */
	public static function notStringLists(): iterable {
		yield 'text' => ['Notenwart'];
		yield 'number in list' => [[1]];
		yield 'null in list' => [[null]];
		yield 'nested' => [[['Notenwart']]];
	}

	#[DataProvider('notStringLists')]
	public function testStringListRefusesOtherValues(mixed $value): void {
		$this->expectException(InvalidInputException::class);
		Input::stringList($value, 'Groups');
	}
}
