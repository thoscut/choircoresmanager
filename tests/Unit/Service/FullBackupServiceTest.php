<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\FullBackupService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Format check of the full backup (FullBackupService::parse()). Export and
 * restore themselves need a database and are covered by the integration test
 * tests/Integration/backup_test.php.
 */
#[CoversClass(FullBackupService::class)]
final class FullBackupServiceTest extends TestCase {
	/**
	 * @param array<string, mixed> $overrides
	 */
	private static function backup(array $overrides = []): string {
		return json_encode($overrides + [
			'format' => FullBackupService::FORMAT,
			'formatVersion' => FullBackupService::FORMAT_VERSION,
			'app' => 'notenarchiv',
			'appVersion' => '0.1.11',
			'created' => '2026-10-22T12:00:00+00:00',
			'tables' => [
				'notarch_works' => [['id' => 1, 'title' => 'Ave verum corpus', 'merged_into' => null]],
				'notarch_versions' => [['id' => 2, 'work_id' => 1, 'preservation_copy' => false, 'duration' => 180]],
				'notarch_audit' => [],
			],
		], JSON_THROW_ON_ERROR);
	}

	public function testParsesAValidBackup(): void {
		$backup = FullBackupService::parse(self::backup());
		self::assertSame(FullBackupService::FORMAT_VERSION, $backup['formatVersion']);
		self::assertSame('2026-10-22T12:00:00+00:00', $backup['created']);
		self::assertSame('0.1.11', $backup['appVersion']);
		self::assertSame([
			'notarch_works' => [['id' => 1, 'title' => 'Ave verum corpus', 'merged_into' => null]],
			'notarch_versions' => [['id' => 2, 'work_id' => 1, 'preservation_copy' => false, 'duration' => 180]],
			'notarch_audit' => [],
		], $backup['tables']);
	}

	public function testUnknownTablesAreDropped(): void {
		$content = self::backup(['tables' => ['oc_users' => [['uid' => 'admin']], 'notarch_works' => []]]);
		self::assertSame(['notarch_works' => []], FullBackupService::parse($content)['tables']);
	}

	public function testMissingDescriptionsAreReplaced(): void {
		$data = json_decode(self::backup(), true, 512, JSON_THROW_ON_ERROR);
		unset($data['created'], $data['appVersion']);
		$backup = FullBackupService::parse(json_encode($data, JSON_THROW_ON_ERROR));
		self::assertSame(['?', '?'], [$backup['created'], $backup['appVersion']]);
	}

	public function testEmptyTablesObjectIsAccepted(): void {
		self::assertSame([], FullBackupService::parse(self::backup(['tables' => new \stdClass()]))['tables']);
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function notABackup(): iterable {
		yield 'not JSON' => ['Mappe;Nr;Signatur'];
		yield 'empty' => [''];
		yield 'JSON list' => ['[1, 2, 3]'];
		yield 'JSON scalar' => ['"notenarchiv-backup"'];
		yield 'other format' => [self::backup(['format' => 'something-else'])];
		yield 'version missing' => [json_encode(['format' => FullBackupService::FORMAT, 'tables' => []], JSON_THROW_ON_ERROR)];
		yield 'version as text' => [self::backup(['formatVersion' => '1'])];
		yield 'tables missing' => [json_encode(['format' => FullBackupService::FORMAT, 'formatVersion' => 1], JSON_THROW_ON_ERROR)];
		yield 'tables as list' => [self::backup(['tables' => [[]]])];
		yield 'rows as object' => [self::backup(['tables' => ['notarch_works' => ['a' => ['id' => 1]]]])];
		yield 'row as list' => [self::backup(['tables' => ['notarch_works' => [[1, 'Ave']]]])];
		yield 'row as scalar' => [self::backup(['tables' => ['notarch_works' => ['Ave']]])];
		yield 'empty row' => [self::backup(['tables' => ['notarch_works' => [new \stdClass()]]])];
		yield 'nested value' => [self::backup(['tables' => ['notarch_works' => [['id' => 1, 'title' => ['de' => 'Ave']]]]])];
	}

	#[DataProvider('notABackup')]
	public function testRefusesWhatIsNoBackup(string $content): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not a backup of the music archive.');
		FullBackupService::parse($content);
	}

	public function testRefusesABackupOfANewerFormat(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The backup was made with a newer version of the app.');
		FullBackupService::parse(self::backup(['formatVersion' => FullBackupService::FORMAT_VERSION + 1]));
	}

	public function testRefusesDeeplyNestedJson(): void {
		$deep = str_repeat('[', 100) . str_repeat(']', 100);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is not a backup of the music archive.');
		FullBackupService::parse(self::backup(['tables' => ['notarch_works' => [['id' => 1, 'title' => 'x']]], 'extra' => json_decode($deep)]));
	}

	/**
	 * @return iterable<string, array{0: FullBackupService::KIND_*, 1: ?int, 2: bool, 3: string|int|float|bool|null, 4: array{0: string|int|bool|null, 1: string|int}|null}>
	 */
	public static function columnValues(): iterable {
		$int = IQueryBuilder::PARAM_INT;
		$bool = IQueryBuilder::PARAM_BOOL;
		$str = IQueryBuilder::PARAM_STR;
		yield 'integer' => [FullBackupService::KIND_INTEGER, null, true, 42, [42, $int]];
		yield 'integer as digits (MySQL export)' => [FullBackupService::KIND_INTEGER, null, true, '-7', [-7, $int]];
		yield 'integer from a boolean' => [FullBackupService::KIND_INTEGER, null, false, true, [1, $int]];
		yield 'integer: text refused' => [FullBackupService::KIND_INTEGER, null, true, '7; DROP TABLE x', null];
		yield 'integer: fraction refused' => [FullBackupService::KIND_INTEGER, null, true, 1.5, null];
		yield 'integer: too many digits refused' => [FullBackupService::KIND_INTEGER, null, true, '1234567890123456789012', null];
		yield 'boolean true' => [FullBackupService::KIND_BOOLEAN, null, false, true, [true, $bool]];
		yield 'boolean false (PostgreSQL export)' => [FullBackupService::KIND_BOOLEAN, null, false, false, [false, $bool]];
		yield 'boolean as 0 (MySQL export)' => [FullBackupService::KIND_BOOLEAN, null, false, 0, [false, $bool]];
		yield 'boolean as "t"' => [FullBackupService::KIND_BOOLEAN, null, false, 't', [true, $bool]];
		yield 'boolean: other text refused' => [FullBackupService::KIND_BOOLEAN, null, false, 'yes please', null];
		yield 'boolean: 2 refused' => [FullBackupService::KIND_BOOLEAN, null, false, 2, null];
		yield 'text' => [FullBackupService::KIND_TEXT, 20, false, 'Ave', ['Ave', $str]];
		yield 'text from a number' => [FullBackupService::KIND_TEXT, 20, false, 12, ['12', $str]];
		yield 'text at its length (characters)' => [FullBackupService::KIND_TEXT, 3, false, 'Äöü', ['Äöü', $str]];
		yield 'text: too long refused' => [FullBackupService::KIND_TEXT, 3, false, 'Ave!', null];
		yield 'text without limit' => [FullBackupService::KIND_TEXT, null, false, str_repeat('x', 100000), [str_repeat('x', 100000), $str]];
		yield 'text: boolean refused' => [FullBackupService::KIND_TEXT, null, false, true, null];
		yield 'null where allowed' => [FullBackupService::KIND_TEXT, 20, false, null, [null, $str]];
		yield 'null: not null column refused' => [FullBackupService::KIND_INTEGER, null, true, null, null];
	}

	/**
	 * @param FullBackupService::KIND_* $kind
	 * @param array{0: string|int|bool|null, 1: string|int}|null $expected
	 */
	#[DataProvider('columnValues')]
	public function testColumnValue(string $kind, ?int $length, bool $notNull, string|int|float|bool|null $value, ?array $expected): void {
		self::assertSame($expected, FullBackupService::columnValue($kind, $length, $notNull, $value));
	}

	public function testOnlyFixedLengthColumnsHaveALengthLimit(): void {
		self::assertSame(500, FullBackupService::columnLength(new Column('title', Type::getType(Types::STRING), ['length' => 500])));
		// MySQL and MariaDB report long text columns with the length 0.
		self::assertNull(FullBackupService::columnLength(new Column('notes', Type::getType(Types::TEXT), ['length' => 0])));
		self::assertNull(FullBackupService::columnLength(new Column('notes', Type::getType(Types::TEXT), ['length' => 65535])));
		self::assertNull(FullBackupService::columnLength(new Column('id', Type::getType(Types::BIGINT))));
	}

	public function testTablesAreInRestoreOrder(): void {
		$tables = FullBackupService::TABLES;
		$position = array_flip($tables);
		// Referenced tables come first.
		self::assertLessThan($position['notarch_versions'], $position['notarch_works']);
		self::assertLessThan($position['notarch_copies'], $position['notarch_versions']);
		self::assertLessThan($position['notarch_files'], $position['notarch_copies']);
		self::assertLessThan($position['notarch_event_items'], $position['notarch_events']);
		self::assertLessThan($position['notarch_contributions'], $position['notarch_persons']);
		self::assertLessThan($position['notarch_folder_loans'], $position['notarch_folders']);
		// Revisions point to files; programme items to the revision sung (milestone 0.8)
		self::assertLessThan($position['notarch_states'], $position['notarch_files']);
		self::assertLessThan($position['notarch_event_items'], $position['notarch_states']);
		self::assertNotContains('notarch_mirror_files', $tables, 'the manifest of the print station belongs to this instance');
		self::assertSame(array_values(array_unique($tables)), $tables);
	}
}
