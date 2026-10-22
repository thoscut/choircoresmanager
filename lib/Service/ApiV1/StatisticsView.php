<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service\ApiV1;

/**
 * The statistics in the API for agents (v1): the figures of
 * StatisticsService::overview() that answer questions about stock, rights,
 * repertoire and open work, with fixed snake_case names. Lists that name
 * copies (works not sung for a long time, protection terms) stay in the
 * app.
 *
 * @psalm-import-type Overview from \OCA\Notenarchiv\Service\StatisticsService
 * @psalm-import-type NotenarchivStatistics from \OCA\Notenarchiv\ResponseDefinitions
 */
final class StatisticsView {
	/**
	 * @param Overview $overview
	 * @return NotenarchivStatistics
	 */
	public static function of(array $overview): array {
		$stock = $overview['stock'];
		$rights = $overview['rights'];
		$valid = $rights['validVersions'];
		$licenses = $rights['licenses'];
		$repertoire = $overview['repertoire'];
		$maintenance = $overview['maintenance'];
		return [
			'period' => $overview['period'],
			'today' => $overview['today'],
			'stock' => [
				'works' => $stock['works'],
				'versions' => $stock['versions'],
				'copies' => $stock['copies'],
				'active' => $stock['active'],
				'active_with_file' => $stock['activeWithFile'],
				'active_with_musicxml' => $stock['activeWithMusicxml'],
				'preservation_versions' => $stock['preservationVersions'],
				'preservation_copies' => $stock['preservationCopies'],
				'status' => $stock['status'],
			],
			'rights' => [
				'status' => $rights['status'],
				'valid_versions' => ['works' => $valid['works'], 'with_valid' => $valid['withValid'], 'without_valid' => $valid['withoutValid'],
					'current_without_valid' => $valid['currentWithoutValid']],
				'licenses' => ['total' => $licenses['total'], 'active' => $licenses['active'], 'ending' => $licenses['ending'],
					'ended' => $licenses['ended'], 'future' => $licenses['future']],
				'portal' => $rights['portal'],
				'print_station' => $rights['printStation'],
				'publication' => $rights['publication'],
			],
			'repertoire' => [
				'classes' => $repertoire['classes'],
				'years' => $repertoire['years'],
				'top_works' => array_map(fn (array $work): array => ['work_id' => $work['workId'], 'title' => $work['title'],
					'composer' => $work['composer'], 'performances' => $work['performances'], 'last' => $work['last']], $repertoire['topWorks']),
				'top_composers' => $repertoire['topComposers'],
			],
			'maintenance' => [
				'tasks' => array_map(fn (array $row): array => ['addressee' => $row['addressee'], 'open' => $row['open'],
					'answered' => $row['answered']], $maintenance['tasks']),
				'proposals' => ['open' => $maintenance['proposals']['open'], 'older_than_30_days' => $maintenance['proposals']['olderThan30Days']],
			],
		];
	}
}
