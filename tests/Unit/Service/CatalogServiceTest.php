<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Service\CatalogService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the catalog service: repertoire figures and the work a
 * merged work points to.
 */
#[CoversClass(CatalogService::class)]
final class CatalogServiceTest extends TestCase {
	/**
	 * @param array<string, mixed> $values
	 */
	private static function work(int $id, array $values = []): Work {
		$work = Work::fromParams($values);
		$work->setId($id);
		return $work;
	}

	public function testFiguresFromDocumentedPerformancesWin(): void {
		$work = self::work(7, ['repertoireImport' => 'früher', 'performancesImport' => '3', 'lastPerformedImport' => '1999']);
		$derived = [7 => ['performances' => 4, 'first' => '2019-05-01', 'last' => '2025-12-06', 'class' => 'current', 'songLists' => ['2025/26']]];
		self::assertSame([
			'repertoire_class' => 'current',
			'repertoire_source' => 'performances',
			'performances' => 4,
			'last_performed_on' => '2025-12-06',
			'song_lists' => ['2025/26'],
		], CatalogService::repertoireFigures($work, $derived));
	}

	public function testImportedFiguresWithoutDocumentedPerformances(): void {
		$work = self::work(7, ['repertoireImport' => 'ruhend', 'performancesImport' => '12', 'lastPerformedImport' => '2011-03-01']);
		$derived = [7 => ['performances' => 0, 'first' => null, 'last' => null, 'class' => 'none', 'songLists' => ['2024']]];
		self::assertSame([
			'repertoire_class' => 'dormant',
			'repertoire_source' => 'import',
			'performances' => 12,
			'last_performed_on' => '2011-03-01',
			'song_lists' => ['2024'],
		], CatalogService::repertoireFigures($work, $derived));
	}

	public function testLegacyRepertoireValues(): void {
		$cases = ['aktuell' => 'current', 'ruhend' => 'dormant', 'früher' => 'earlier', 'nicht belegt' => 'none', 'irgendwas' => null];
		foreach ($cases as $legacy => $class) {
			self::assertSame($class, CatalogService::repertoireFigures(self::work(1, ['repertoireImport' => $legacy]), [])['repertoire_class'], $legacy);
		}
	}

	public function testNonNumericCountIsDropped(): void {
		self::assertNull(CatalogService::repertoireFigures(self::work(1, ['performancesImport' => 'viele']), [])['performances']);
	}

	public function testWithoutWork(): void {
		self::assertSame([
			'repertoire_class' => null,
			'repertoire_source' => 'import',
			'performances' => null,
			'last_performed_on' => null,
			'song_lists' => [],
		], CatalogService::repertoireFigures(null, []));
	}

	public function testListedWorkFollowsMerges(): void {
		$a = self::work(1, ['mergedInto' => 2]);
		$b = self::work(2, ['mergedInto' => 3]);
		$c = self::work(3);
		$works = [1 => $a, 2 => $b, 3 => $c];
		self::assertSame($c, CatalogService::listedWork($a, $works));
		self::assertSame($c, CatalogService::listedWork($c, $works));
		self::assertNull(CatalogService::listedWork(null, $works));
	}

	public function testListedWorkStopsAtUnknownTargetsAndCycles(): void {
		$orphan = self::work(1, ['mergedInto' => 99]);
		self::assertSame($orphan, CatalogService::listedWork($orphan, [1 => $orphan]));

		$a = self::work(1, ['mergedInto' => 2]);
		$b = self::work(2, ['mergedInto' => 1]);
		self::assertContains(CatalogService::listedWork($a, [1 => $a, 2 => $b]), [$a, $b], 'a cycle ends after a bounded number of steps');
	}
}
