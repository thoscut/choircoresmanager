<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCP\IL10N;

/**
 * The tables of the statistics and of the annual report as one CSV file for
 * people (spreadsheet programs): each table under its heading, separated by
 * an empty line, texts in the language of the user. Cells that a spreadsheet
 * program would read as a formula are neutralized (CatalogCsv::writeForPeople).
 *
 * Like the statistics themselves, the file contains no personal data of choir
 * members, no account names and no internal preservation copies by name.
 *
 * @psalm-import-type Overview from StatisticsService
 * @psalm-import-type Report from StatisticsService
 * @psalm-import-type ReportLicense from StatisticsService
 */
final class StatisticsCsv {
	/** Labels of the release states, as in the UI */
	private const RELEASE_LABELS = [
		'ok' => 'In order',
		'remove' => 'Remove',
		'add' => 'Add',
		'not_decided' => 'No valid version chosen',
		'other_version_valid' => 'Another version is valid',
		'blocked' => 'Not released',
	];

	/** Labels of the repertoire classes, as in the UI */
	private const REPERTOIRE_LABELS = [
		'current' => 'Current repertoire',
		'dormant' => 'Dormant',
		'earlier' => 'Earlier',
		'none' => 'Not documented',
	];

	/**
	 * @param Overview $data
	 */
	public static function overview(array $data, IL10N $l): string {
		$stock = $data['stock'];
		$rights = $data['rights'];
		$repertoire = $data['repertoire'];
		$maintenance = $data['maintenance'];
		$rows = [[$l->t('As of %s', [$data['today']])]];

		self::table($rows, $l->t('Stock'), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Works'), $stock['works']],
			[$l->t('Versions'), $stock['versions']],
			[$l->t('Copies'), $stock['copies']],
			[$l->t('Active copies'), $stock['active']],
			[$l->t('Active copies with a file'), $stock['activeWithFile']],
			[$l->t('Active copies with MusicXML'), $stock['activeWithMusicxml']],
			[$l->t('Internal preservation copies'), $stock['preservationCopies']],
		]);
		self::table($rows, $l->t('Copies per binder'),
			[$l->t('Binder'), $l->t('Active'), $l->t('Retired'), $l->t('Missing'), $l->t('Lent'), $l->t('Other status'), $l->t('Total')],
			array_map(fn (array $b): array => [$b['binder'], $b['active'], $b['retired'], $b['missing'], $b['lent'], $b['other'], $b['total']],
				$stock['binders']));
		self::table($rows, $l->t('New copies per year'),
			[$l->t('Year'), $l->t('New accessions'), $l->t('From the catalog import'), $l->t('Other'), $l->t('Total')],
			array_map(fn (array $a): array => [$a['year'], $a['accession'], $a['import'], $a['other'], $a['total']], $stock['additions']));

		self::table($rows, $l->t('Rights status of the versions'), [$l->t('Rights status'), $l->t('Confirmed'), $l->t('Not confirmed'), $l->t('Total')],
			array_map(fn (array $r): array => [self::rightsLabel($r['status'], $l), $r['confirmed'], $r['unconfirmed'], $r['total']],
				$rights['status']));
		$valid = $rights['validVersions'];
		self::table($rows, $l->t('Valid version (chosen by the conductor)'), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Works'), $valid['works']],
			[$l->t('With a valid version'), $valid['withValid']],
			[$l->t('Without a valid version'), $valid['withoutValid']],
			[$l->t('Without a valid version, with several versions'), $valid['withoutValidSeveral']],
			[$l->t('Current repertoire without a valid version'), $valid['currentWithoutValid']],
		]);
		$licenses = $rights['licenses'];
		self::table($rows, $l->t('Licenses'), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Valid'), $licenses['active']],
			[$l->n('Ending within %n day', 'Ending within %n days', $licenses['endingDays']), $licenses['ending']],
			[$l->t('Ended'), $licenses['ended']],
			[$l->t('Not valid yet'), $licenses['future']],
			[$l->t('Total'), $licenses['total']],
		]);
		self::table($rows, $l->t('Releases: versions per state of the lists'), [$l->t('State'), $l->t('Singers\' portal'), $l->t('Print station')],
			array_map(fn (string $status): array => [$l->t(self::RELEASE_LABELS[$status]), $rights['portal'][$status] ?? 0,
				$rights['printStation'][$status] ?? 0], StatisticsService::RELEASE_STATUSES));
		self::table($rows, $l->t('Publication status'), [$l->t('Publication status'), $l->t('Versions')],
			array_map(fn (string $status): array => [self::publicationLabel($status, $l), $rights['publication'][$status] ?? 0],
				StatisticsService::PUBLICATION_STATUSES));

		self::table($rows, $l->t('Concerts and pieces per year'),
			[$l->t('Year'), $l->t('Concerts sung'), $l->t('Planned concerts'), $l->t('Pieces sung'), $l->t('Different works')],
			array_map(fn (array $y): array => [$y['year'], $y['concerts'], $y['planned'], $y['pieces'], $y['works']], $repertoire['years']));
		self::table($rows, $l->t('Most performed works'),
			[$l->t('Rank'), $l->t('Title'), $l->t('Composer'), $l->t('Performances'), $l->t('Last performed')],
			array_map(fn (int $i, array $w): array => [$i + 1, $w['title'] ?? '', $w['composer'] ?? '', $w['performances'], $w['last']],
				array_keys($repertoire['topWorks']), $repertoire['topWorks']));
		self::table($rows, $l->t('Most performed composers'),
			[$l->t('Rank'), $l->t('Composer'), $l->t('Performances'), $l->t('Works')],
			array_map(fn (int $i, array $c): array => [$i + 1, $c['composer'], $c['performances'], $c['works']],
				array_keys($repertoire['topComposers']), $repertoire['topComposers']));
		self::table($rows, $l->t('Repertoire classes of the works'), [$l->t('Repertoire'), $l->t('Works')],
			array_map(fn (string $class): array => [$l->t(self::REPERTOIRE_LABELS[$class]), $repertoire['classes'][$class] ?? 0],
				StatisticsService::REPERTOIRE_CLASSES));
		self::table($rows, $l->t('Not sung for a long time'),
			[$l->t('Shelf mark'), $l->t('Title'), $l->t('Composer'), $l->t('Repertoire'), $l->t('Performances'), $l->t('Last performed')],
			array_map(fn (array $w): array => [$w['shelfmark'], $w['title'] ?? '', $w['composer'] ?? '',
				$l->t(self::REPERTOIRE_LABELS[$w['class']] ?? 'Not documented'), $w['performances'] ?? '', $w['last'] ?? ''],
				$repertoire['longNotSung']));

		self::table($rows, $l->t('Open tasks by role'),
			[$l->t('Role'), $l->t('Open'), $l->t('Answered'), $l->t('Up to 30 days old'), $l->t('31 to 90 days old'),
				$l->t('91 to 365 days old'), $l->t('Older')],
			array_map(fn (array $t): array => [$l->t(Roles::LABELS[$t['addressee']] ?? $t['addressee']), $t['open'], $t['answered'],
				$t['days30'], $t['days90'], $t['days365'], $t['older']], $maintenance['tasks']));
		$checksums = $maintenance['checksums'];
		self::table($rows, $l->t('Inbox and checksums'), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Open proposals'), $maintenance['proposals']['open']],
			[$l->t('Open proposals older than 30 days'), $maintenance['proposals']['olderThan30Days']],
			[$l->t('Files in the archive folder'), $checksums['files']],
			[$l->t('Files with a checksum'), $checksums['hashed']],
			[$l->t('Files without a checksum yet'), $checksums['pending']],
			[$l->t('Files changed without modification'), $checksums['mismatches']],
		]);
		$protection = $maintenance['protection'];
		self::table($rows, $l->t('Protection terms ending per year'), [$l->t('Year'), $l->t('Versions')],
			array_map(fn (array $y): array => [$y['year'], $y['versions']], $protection['years']));
		self::table($rows, $l->t('Protection terms ending in the period'),
			[$l->t('Protected until'), $l->t('Shelf marks'), $l->t('Title'), $l->t('Composer'), $l->t('Rights status')],
			array_map(fn (array $p): array => [$p['until'], implode(', ', array_column($p['copies'], 'shelfmark')), $p['title'] ?? '',
				$p['composer'] ?? '', self::rightsLabel($p['rightsStatus'] ?? 'none', $l)], $protection['rows']));

		return CatalogCsv::writeForPeople(
			[$l->t('Music archive: statistics %1$s to %2$s', [$data['period']['from'], $data['period']['to']])], $rows);
	}

	/**
	 * @param Report $data
	 */
	public static function report(array $data, IL10N $l): string {
		$rows = [[$l->t('As of %s', [$data['today']])]];
		$totals = $data['totals'];
		self::table($rows, $l->t('Concerts'), [$l->t('Date'), $l->t('Title'), $l->t('Location'), $l->t('Status'), $l->t('Pieces')],
			array_map(fn (array $c): array => [$c['date'] ?? '', $c['title'] ?? '', $c['location'] ?? '',
				$c['status'] === 'sung' ? $l->t('Sung') : $l->t('Planned'), $c['pieces']], $data['concerts']));
		self::table($rows, $l->t('The year in figures'), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Concerts sung'), $totals['concerts']],
			[$l->t('Planned concerts'), $totals['planned']],
			[$l->t('Pieces sung'), $totals['pieces']],
			[$l->t('Different works'), $totals['works']],
			[$l->t('New accessions'), $data['additions']['accession']],
			[$l->t('From the catalog import'), $data['additions']['import']],
			[$l->t('Other new copies'), $data['additions']['other']],
		]);
		self::table($rows, $l->t('New accessions'), [$l->t('Date'), $l->t('Shelf mark'), $l->t('Title'), $l->t('Composer')],
			array_map(fn (array $a): array => [$a['date'], $a['shelfmark'], $a['title'] ?? '', $a['composer'] ?? ''], $data['accessions']));

		$licenses = $data['licenses'];
		$licenseHeader = [$l->t('Title'), $l->t('Composer'), $l->t('License holder'), $l->t('Uses'), $l->t('Valid from'),
			$l->t('Valid until'), $l->t('Shelf marks')];
		$rows[] = [];
		$rows[] = [$l->t('Licenses valid on %1$s: %2$s', [$licenses['validOn'], $licenses['valid']])];
		self::table($rows, $l->t('Licenses that started in %s', [$data['year']]), $licenseHeader,
			array_map(fn (array $x): array => self::licenseRow($x, $l), $licenses['started']));
		self::table($rows, $l->t('Licenses that end in %s', [$data['year']]), $licenseHeader,
			array_map(fn (array $x): array => self::licenseRow($x, $l), $licenses['ended']));

		$open = $data['openRights'];
		self::table($rows, $l->t('Open rights questions (as of %s)', [$data['today']]), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Rights status open'), $open['open']],
			[$l->t('No rights status'), $open['none']],
			[$l->t('Public domain, not confirmed'), $open['publicDomainUnconfirmed']],
			[$l->t('Protected, not confirmed'), $open['protectedUnconfirmed']],
			[$l->t('Marked protected, but public domain by the life dates'), $open['protectionEnded']],
			[$l->t('Marked public domain, but the persons do not allow it'), $open['conflicts']],
			[$l->t('Open tasks about rights'), $open['rightsTasks']],
		]);
		$stock = $data['stock'];
		self::table($rows, $l->t('Stock (as of %s)', [$data['today']]), [$l->t('Figure'), $l->t('Number')], [
			[$l->t('Works'), $stock['works']],
			[$l->t('Versions'), $stock['versions']],
			[$l->t('Copies'), $stock['copies']],
			[$l->t('Active copies'), $stock['active']],
		]);

		return CatalogCsv::writeForPeople([$l->t('Music archive: annual report %s', [$data['year']])], $rows);
	}

	/**
	 * Appends a table: an empty line, the heading, the column headings and
	 * the rows (numbers become text).
	 *
	 * @param list<list<string>> $rows
	 * @param list<string> $header
	 * @param list<list<string|int>> $body
	 */
	private static function table(array &$rows, string $title, array $header, array $body): void {
		$rows[] = [];
		$rows[] = [$title];
		$rows[] = $header;
		foreach ($body as $row) {
			$rows[] = array_map(fn (string|int $cell): string => (string)$cell, $row);
		}
	}

	/**
	 * @param ReportLicense $license
	 * @return list<string|int>
	 */
	private static function licenseRow(array $license, IL10N $l): array {
		$uses = array_map(fn (string $use): string => $l->t(Fields::LICENSE_USES[$use] ?? $use), $license['uses']);
		return [$license['title'] ?? '', $license['composer'] ?? '', $license['holder'] ?? '', implode(', ', $uses),
			$license['validFrom'] ?? '', $license['validUntil'] ?? '', implode(', ', $license['shelfmarks'])];
	}

	private static function rightsLabel(string $status, IL10N $l): string {
		$label = Fields::RIGHTS_STATUS[$status] ?? null;
		return $label === null ? $l->t('No rights status') : $l->t($label);
	}

	private static function publicationLabel(string $status, IL10N $l): string {
		$label = Fields::PUBLICATION_STATUS[$status] ?? null;
		return $label === null ? $l->t('Not set') : $l->t($label);
	}
}
