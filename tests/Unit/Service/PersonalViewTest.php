<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\PersonalView;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The personal view: stored per account on the server, only names of the
 * expected form, an empty view removes the setting.
 */
#[CoversClass(PersonalView::class)]
final class PersonalViewTest extends TestCase {
	/** @var array<string, array<array-key, mixed>> user => stored value */
	private array $stored = [];

	private function service(): PersonalView {
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $uid): array => $this->stored[$uid] ?? []);
		$config->method('setValueArray')->willReturnCallback(function (string $uid, string $app, string $key, array $value): bool {
			self::assertSame(['notenarchiv', 'personal_view'], [$app, $key]);
			$this->stored[$uid] = $value;
			return true;
		});
		$config->method('deleteUserConfig')->willReturnCallback(function (string $uid): void {
			unset($this->stored[$uid]);
		});
		return new PersonalView($config);
	}

	public function testStoresPerAccount(): void {
		$view = $this->service();
		self::assertSame(['navigation' => [], 'cards' => []], $view->get('anna'));
		$saved = $view->set('anna', ['statistics', 'songLists', 'statistics'], ['proposals', 'concerts']);
		self::assertSame(['navigation' => ['statistics', 'songLists'], 'cards' => ['proposals', 'concerts']], $saved);
		self::assertSame($saved, $view->get('anna'));
		self::assertSame(['navigation' => [], 'cards' => []], $view->get('ben'), 'other accounts are not affected');

		$view->set('anna', [], []);
		self::assertArrayNotHasKey('anna', $this->stored, 'an empty view removes the setting');
	}

	/**
	 * @return iterable<string, array{0: array<array-key, mixed>}>
	 */
	public static function invalidNames(): iterable {
		yield 'not text' => [[['nested']]];
		yield 'a number' => [[5]];
		yield 'markup' => [['<script>']];
		yield 'upper case first' => [['Statistics']];
		yield 'too long' => [[str_repeat('a', 41)]];
		yield 'too many' => [array_map(fn (int $i): string => 'view' . $i, range(1, 51))];
	}

	/**
	 * @param array<array-key, mixed> $names
	 */
	#[DataProvider('invalidNames')]
	public function testRefusesOtherValues(array $names): void {
		$this->expectException(InvalidInputException::class);
		$this->service()->set('anna', $names, []);
	}

	public function testIgnoresDamagedStoredValues(): void {
		$this->stored['anna'] = ['navigation' => 'statistics', 'cards' => ['proposals', 7, '<b>', 'tasks']];
		self::assertSame(['navigation' => [], 'cards' => ['proposals', 'tasks']], $this->service()->get('anna'));
	}
}
