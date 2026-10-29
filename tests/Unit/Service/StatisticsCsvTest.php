<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Service;

use OCA\Cantoria\Service\CatalogCsv;
use OCA\Cantoria\Service\StatisticsCsv;
use OCA\Cantoria\Service\StatisticsService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The CSV files of the statistics and the annual report: one table after
 * the other, texts translated, cells protected against formula injection.
 *
 * @psalm-import-type Overview from StatisticsService
 * @psalm-import-type Report from StatisticsService
 */
#[CoversClass(StatisticsCsv::class)]
final class StatisticsCsvTest extends TestCase {
	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('n')->willReturnCallback(fn (string $singular, string $plural, int $count, array $parameters = []): string
			=> vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));
		return $l;
	}

	/**
	 * @return Overview
	 */
	private static function overview(): array {
		$zeroYear = ['year' => 2026, 'concerts' => 2, 'planned' => 1, 'pieces' => 17, 'works' => 15];
		return [
			'period' => ['from' => 2026, 'to' => 2026],
			'today' => '2026-10-22',
			'range' => ['first' => 2001, 'last' => 2026],
			'stock' => ['works' => 3, 'versions' => 4, 'copies' => 5, 'status' => ['active' => 4, 'retired' => 1, 'missing' => 0, 'lent' => 0, 'other' => 0],
				'binders' => [['binder' => 1, 'active' => 4, 'retired' => 1, 'missing' => 0, 'lent' => 0, 'other' => 0, 'total' => 5]],
				'active' => 4, 'activeWithFile' => 3, 'activeWithMusicxml' => 1, 'preservationVersions' => 1, 'preservationCopies' => 1,
				'additions' => [['year' => 2026, 'accession' => 1, 'import' => 4, 'other' => 0, 'total' => 5]]],
			'rights' => [
				'status' => [['status' => 'public_domain', 'confirmed' => 1, 'unconfirmed' => 1, 'total' => 2],
					['status' => 'none', 'confirmed' => 0, 'unconfirmed' => 2, 'total' => 2]],
				'validVersions' => ['works' => 3, 'withValid' => 1, 'withoutValid' => 2, 'withoutValidSeveral' => 1, 'currentWithoutValid' => 0],
				'licenses' => ['total' => 1, 'active' => 1, 'ending' => 0, 'ended' => 0, 'future' => 0, 'endingDays' => 30],
				'portal' => array_fill_keys(StatisticsService::RELEASE_STATUSES, 0),
				'printStation' => array_fill_keys(StatisticsService::RELEASE_STATUSES, 0),
				'publication' => array_fill_keys(StatisticsService::PUBLICATION_STATUSES, 1),
			],
			'repertoire' => [
				'years' => [$zeroYear],
				'undated' => 0,
				'topWorks' => [['workId' => 1, 'title' => '=HYPERLINK("http://example.org")', 'composer' => 'Silcher', 'performances' => 4, 'last' => '2026-05-01']],
				'topComposers' => [['composer' => 'Silcher', 'performances' => 4, 'works' => 1]],
				'classes' => ['current' => 1, 'dormant' => 1, 'earlier' => 0, 'none' => 1],
				'sources' => ['performances' => 1, 'import' => 1],
				'thresholds' => ['current' => 3, 'dormant' => 10],
				'longNotSung' => [['workId' => 2, 'title' => 'Tischlied', 'composer' => null, 'class' => 'dormant', 'performances' => 2,
					'last' => '2019-05-01', 'copyId' => 2, 'shelfmark' => '1-2']],
				'longNotSungTotal' => 1,
			],
			'maintenance' => [
				'tasks' => StatisticsService::tasksByRole([], 0),
				'proposals' => ['open' => 2, 'olderThan30Days' => 1],
				'checksums' => ['files' => 10, 'hashed' => 8, 'pending' => 2, 'mismatches' => 1],
				'protection' => ['years' => [['year' => 2026, 'versions' => 1]], 'total' => 1, 'omitted' => 0,
					'rows' => [['versionId' => 1, 'title' => 'Lied', 'composer' => 'X', 'until' => '2026-12-31', 'rightsStatus' => 'protected',
						'confirmed' => false, 'copies' => [['id' => 1, 'shelfmark' => '1-1', 'status' => 'active']]]]],
			],
		];
	}

	/**
	 * @return list<list<string>>
	 */
	private static function rows(string $csv): array {
		self::assertStringStartsWith(CatalogCsv::BOM, $csv);
		return CatalogCsv::parse($csv)['rows'];
	}

	public function testOverviewHasEveryTableUnderItsHeading(): void {
		$csv = StatisticsCsv::overview(self::overview(), $this->l10n());
		self::assertStringContainsString("Music archive: statistics 2026 to 2026\r\nAs of 2026-10-22\r\n", $csv);
		foreach (['Stock', 'Copies per binder', 'New copies per year', 'Rights status of the versions', 'Valid version (chosen by the conductor)',
			'Licenses', 'Releases: versions per state of the lists', 'Publication status', 'Concerts and pieces per year', 'Most performed works',
			'Most performed composers', 'Repertoire classes of the works', 'Not sung for a long time', 'Open tasks by role', 'Inbox and checksums',
			'Protection terms ending per year', 'Protection terms ending in the period'] as $heading) {
			self::assertStringContainsString("\r\n\r\n" . $heading . "\r\n", $csv, $heading);
		}
		self::assertStringContainsString("2026;2;1;17;15\r\n", $csv);
		self::assertStringContainsString("Ending within 30 days;0\r\n", $csv);
		self::assertStringContainsString("1-2;Tischlied;;Dormant;2;2019-05-01\r\n", $csv);
		self::assertStringContainsString("Conductor;0;0;0;0;0;0\r\n", $csv);
		self::assertStringContainsString("2026-12-31;1-1;Lied;X;Protected\r\n", $csv);
		self::assertStringContainsString("No rights status;0;2;2\r\n", $csv);
	}

	public function testCellsThatLookLikeFormulasAreNeutralized(): void {
		$rows = self::rows(StatisticsCsv::overview(self::overview(), $this->l10n()));
		$top = array_values(array_filter($rows, fn (array $row): bool => ($row[0] ?? '') === '1' && count($row) === 5));
		self::assertSame('\'=HYPERLINK("http://example.org")', $top[0][1]);
	}

	public function testReport(): void {
		/** @var Report $report */
		$report = [
			'year' => 2025,
			'today' => '2026-10-22',
			'concerts' => [['id' => 1, 'date' => '2025-12-06', 'title' => 'Advent concert', 'location' => 'Church', 'status' => 'sung',
				'imported' => false, 'pieces' => 12]],
			'totals' => ['year' => 2025, 'concerts' => 1, 'planned' => 0, 'pieces' => 12, 'works' => 11],
			'additions' => ['year' => 2025, 'accession' => 1, 'import' => 0, 'other' => 0, 'total' => 1],
			'accessions' => [['copyId' => 9, 'shelfmark' => '10-75', 'title' => 'Neu', 'composer' => 'Y', 'date' => '2025-03-01']],
			'accessionsOmitted' => 0,
			'licenses' => ['validOn' => '2025-12-31', 'valid' => 3, 'omitted' => 0, 'started' => [], 'ended' => [['licenseId' => 4, 'title' => 'Lied',
				'composer' => 'X', 'holder' => 'Verlag', 'uses' => ['binder', 'portal'], 'validFrom' => null, 'validUntil' => '2025-06-30',
				'shelfmarks' => ['1-1']]]],
			'openRights' => ['open' => 1, 'none' => 2, 'publicDomainUnconfirmed' => 3, 'protectedUnconfirmed' => 4, 'protectionEnded' => 5,
				'conflicts' => 6, 'rightsTasks' => 7],
			'stock' => ['works' => 3, 'versions' => 4, 'copies' => 5, 'active' => 4],
		];
		$csv = StatisticsCsv::report($report, $this->l10n());
		self::assertStringContainsString("Music archive: annual report 2025\r\n", $csv);
		self::assertStringContainsString("2025-12-06;Advent concert;Church;Sung;12\r\n", $csv);
		self::assertStringContainsString("Pieces sung;12\r\n", $csv);
		self::assertStringContainsString("2025-03-01;10-75;Neu;Y\r\n", $csv);
		self::assertStringContainsString("Licenses valid on 2025-12-31: 3\r\n", $csv);
		self::assertStringContainsString("Lied;X;Verlag;Copies for the binders, Singers' portal;;2025-06-30;1-1\r\n", $csv);
		self::assertStringContainsString("Open tasks about rights;7\r\n", $csv);
	}
}
