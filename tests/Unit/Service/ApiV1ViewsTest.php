<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Service\ApiV1\CatalogView;
use OCA\Cantoria\Service\ApiV1\Page;
use OCA\Cantoria\Service\ApiV1\StatisticsView;
use OCA\Cantoria\Service\ApiV1\TaskView;
use OCA\Cantoria\Service\ApiV1\Values;
use OCA\Cantoria\Service\Fields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The building blocks of the API for agents (v1): pages with cursors, the
 * fixed field names, no account names.
 */
#[CoversClass(Page::class)]
#[CoversClass(CatalogView::class)]
#[CoversClass(StatisticsView::class)]
#[CoversClass(TaskView::class)]
#[CoversClass(Values::class)]
final class ApiV1ViewsTest extends TestCase {
	public function testPagesNeverRepeatOrSkipWhenEntriesAreAdded(): void {
		$rows = [['id' => 3], ['id' => 1], ['id' => 2], ['id' => 5]];
		$key = fn (array $row): array => [$row['id']];
		$first = Page::of($rows, $key, null, 2);
		self::assertSame([['id' => 1], ['id' => 2]], $first['items']);
		self::assertSame(4, $first['total']);
		self::assertNotNull($first['next_cursor']);

		// An entry before the cursor and one after it are added between the calls.
		$rows[] = ['id' => 0];
		$rows[] = ['id' => 4];
		$second = Page::of($rows, $key, $first['next_cursor'], 10);
		self::assertSame([['id' => 3], ['id' => 4], ['id' => 5]], $second['items']);
		self::assertNull($second['next_cursor']);
	}

	public function testAFullLastPageHasNoCursor(): void {
		$page = Page::of([['id' => 1], ['id' => 2]], fn (array $row): array => [$row['id']], null, 2);
		self::assertNull($page['next_cursor']);
		self::assertSame([], Page::of([], fn (array $row): array => [], null, 25)['items']);
	}

	public function testCursorsAreOpaqueAndChecked(): void {
		$cursor = Page::encode(['2026-05-01', 7]);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor, 'safe in a URL without encoding');
		self::assertSame(['2026-05-01', 7], Page::decode($cursor));
		foreach (['', '!', str_repeat('a', 600), Page::encode([]), rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '='),
			rtrim(strtr(base64_encode('[[1]]'), '+/', '-_'), '='), rtrim(strtr(base64_encode('[1.5]'), '+/', '-_'), '=')] as $invalid) {
			try {
				Page::decode($invalid);
				self::fail('accepted: ' . $invalid);
			} catch (InvalidInputException $e) {
				self::assertSame('This cursor is not valid. Request the first page again without a cursor.', $e->getMessage());
			}
		}
	}

	public function testNoLevelShowsAccountNames(): void {
		$version = new WorkVersion();
		$version->setConfirmedBy('anna');
		$version->setConfirmedByName('Anna Archiv');
		$version->setValidSetBy('Chorleitung Carla');
		$version->setRule1Checked('2026-01-01 Anna Archiv');
		$version->setKeySignature('D major');
		$values = CatalogView::fieldValues(Fields::VERSION, $version);
		self::assertSame('D major', $values['key_signature']);
		foreach (CatalogView::PERSONAL_FIELDS[Fields::VERSION] as $field) {
			self::assertArrayNotHasKey($field, $values);
		}
		self::assertStringNotContainsString('Anna', (string)json_encode($values));
		self::assertStringNotContainsString('Carla', (string)json_encode($values));
		self::assertSame(count(Fields::all()[Fields::VERSION]) - count(CatalogView::PERSONAL_FIELDS[Fields::VERSION]), count($values));
	}

	public function testAProposalWithAStoredValueThatIsNoJson(): void {
		$proposal = new Proposal();
		$proposal->setId(1);
		$proposal->setNewValue('not json');
		$proposal->setCreatedBy('someone');
		$proposal->setCreated(0);
		$data = TaskView::proposal($proposal, 'agent');
		self::assertSame('not json', $data['value']);
		self::assertFalse($data['own']);
		self::assertSame('', $data['created_at']);
	}

	public function testValues(): void {
		self::assertSame('2025-10-22T12:00:00Z', Values::time(1761134400));
		self::assertNull(Values::time(null));
		self::assertSame(12, Values::intOrNull('12'));
		self::assertNull(Values::intOrNull('12a'));
		self::assertSame('7', Values::stringOrNull(7));
		self::assertNull(Values::stringOrNull(false));
		self::assertSame(['a', 'b'], Values::strings(['a', 1, 'b', null]));
	}

	public function testStatisticsHaveSnakeCaseNamesOnly(): void {
		$year = ['year' => 2025, 'concerts' => 1, 'planned' => 0, 'pieces' => 4, 'works' => 3];
		$overview = [
			'period' => ['from' => 2016, 'to' => 2025], 'today' => '2025-10-22', 'range' => ['first' => 1990, 'last' => 2025],
			'stock' => ['works' => 2, 'versions' => 2, 'copies' => 3, 'status' => ['active' => 3], 'binders' => [], 'active' => 3,
				'activeWithFile' => 2, 'activeWithMusicxml' => 1, 'preservationVersions' => 1, 'preservationCopies' => 1, 'additions' => []],
			'rights' => [
				'status' => [['status' => 'public_domain', 'confirmed' => 1, 'unconfirmed' => 1, 'total' => 2]],
				'validVersions' => ['works' => 2, 'withValid' => 1, 'withoutValid' => 1, 'withoutValidSeveral' => 0, 'currentWithoutValid' => 1],
				'licenses' => ['total' => 0, 'active' => 0, 'ending' => 0, 'ended' => 0, 'future' => 0, 'endingDays' => 30],
				'portal' => ['ok' => 1], 'printStation' => ['add' => 1], 'publication' => ['none' => 2],
			],
			'repertoire' => ['years' => [$year], 'undated' => 0,
				'topWorks' => [['workId' => 1, 'title' => 'T', 'composer' => null, 'performances' => 2, 'last' => '2025-05-01']],
				'topComposers' => [['composer' => 'C', 'performances' => 2, 'works' => 1]], 'classes' => ['current' => 1],
				'sources' => ['performances' => 1, 'import' => 1], 'thresholds' => ['current' => 3, 'dormant' => 10],
				'longNotSung' => [], 'longNotSungTotal' => 0],
			'maintenance' => [
				'tasks' => [['addressee' => 'conductor', 'open' => 1, 'answered' => 0, 'days30' => 1, 'days90' => 0, 'days365' => 0, 'older' => 0]],
				'proposals' => ['open' => 2, 'olderThan30Days' => 1],
				'checksums' => ['files' => 0, 'hashed' => 0, 'pending' => 0, 'mismatches' => 0],
				'protection' => ['years' => [], 'total' => 0, 'rows' => [], 'omitted' => 0],
			],
		];
		$statistics = StatisticsView::of($overview);
		self::assertSame(1, $statistics['stock']['active_with_musicxml']);
		self::assertSame(['work_id' => 1, 'title' => 'T', 'composer' => null, 'performances' => 2, 'last' => '2025-05-01'],
			$statistics['repertoire']['top_works'][0]);
		self::assertSame(['open' => 2, 'older_than_30_days' => 1], $statistics['maintenance']['proposals']);
		$keys = [];
		array_walk_recursive($statistics, function (mixed $value, int|string $key) use (&$keys): void {
			$keys[] = $key;
		});
		$names = (string)json_encode(array_keys($statistics)) . json_encode($keys) . json_encode(array_keys($statistics['stock']))
			. json_encode(array_keys($statistics['rights'])) . json_encode(array_keys($statistics['repertoire']));
		self::assertDoesNotMatchRegularExpression('/"[a-z]+[A-Z]/', $names, 'no camelCase names');
	}
}
