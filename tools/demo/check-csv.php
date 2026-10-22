<?php

declare(strict_types=1);

/**
 * Checks the import files of the demo data with the app's own classes,
 * without a Nextcloud server: parsing, columns, values allowed by the field
 * registry, the round trip parse → write (byte for byte) and what the person
 * and performance imports will make of the lists.
 *
 * Usage (PHP 8.2+ with mbstring):
 *   php tools/demo/check-csv.php <app root> <import folder>
 *   php tools/demo/check-csv.php . build/demo/Notenarchiv-Demo/_Import
 *
 * <app root> is the folder with lib/ (and vendor/ from `composer install`,
 * which provides the public Nextcloud API the classes refer to).
 * Exit code 0 when every check passed.
 */

use OCA\Notenarchiv\Service\CatalogCsv;
use OCA\Notenarchiv\Service\Fields;
use OCA\Notenarchiv\Service\FieldValue;
use OCA\Notenarchiv\Service\LifeDates;
use OCA\Notenarchiv\Service\PerformanceImportService;
use OCA\Notenarchiv\Service\PersonImportService;

if ($argc < 3) {
	fwrite(STDERR, "usage: php check-csv.php <app root> <import folder>\n");
	exit(2);
}
[, $appRoot, $dir] = $argv;
if (is_file($appRoot . '/vendor/autoload.php')) {
	require $appRoot . '/vendor/autoload.php';
}
// Prepended, so that the app classes always come from <app root>/lib.
spl_autoload_register(function (string $class) use ($appRoot): void {
	$prefix = 'OCA\\Notenarchiv\\';
	if (str_starts_with($class, $prefix)) {
		$file = $appRoot . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
}, true, true);

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($detail !== '' ? ' — ' . $detail : '') . "\n";
	if (!$ok) {
		$failures++;
	}
};

/**
 * Converts a cell like ImportService::convert() and checks it against the
 * field registry; returns null when valid, otherwise the reason.
 */
$invalid = function (string $header, string $level, string $field, string $text): ?string {
	$info = Fields::info($level, $field);
	if ($info === null) {
		return 'unknown field';
	}
	if (trim($text) === '') {
		return null;
	}
	$value = match ($info['type']) {
		Fields::TYPE_NUMBER => preg_match('/^-?\d+$/', trim($text)) === 1 ? (int)trim($text) : $text,
		Fields::TYPE_BOOLEAN => filter_var(trim($text), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $text,
		Fields::TYPE_CHOICE => $header === 'Rechtestatus' ? (CatalogCsv::LEGACY_RIGHTS_STATUS[trim($text)] ?? $text) : trim($text),
		default => $text,
	};
	return FieldValue::check($info, $value);
};

$read = function (string $name) use ($dir, $check): ?string {
	$path = $dir . '/' . $name;
	$content = is_file($path) ? file_get_contents($path) : false;
	$check("$name readable", $content !== false);
	return $content === false ? null : $content;
};

$roundTrip = function (string $name, string $content) use ($check): array {
	$csv = CatalogCsv::parse($content);
	$check("$name: UTF-8 with BOM, CRLF", str_starts_with($content, CatalogCsv::BOM) && !preg_match('/(?<!\r)\n/', $content));
	$check("$name: parse → write gives the same bytes", CatalogCsv::write($csv['header'], $csv['rows']) === $content);
	return $csv;
};

// Catalog (legacy format)
$shelfmarks = [];
if (($content = $read('catalog.csv')) !== null) {
	$csv = $roundTrip('catalog.csv', $content);
	$check('catalog.csv: the 31 legacy columns in their order', $csv['header'] === array_keys(CatalogCsv::LEGACY_COLUMNS),
		count($csv['header']) . ' columns');
	$bad = [];
	$rights = [];
	$publication = [];
	$preservation = 0;
	foreach ($csv['rows'] as $i => $row) {
		if (count($row) !== count($csv['header'])) {
			$bad[] = 'row ' . ($i + 1) . ': ' . count($row) . ' cells';
			continue;
		}
		$cells = array_combine($csv['header'], $row);
		if (!preg_match('/^(\d+)-(\d+)$/', $cells['Signatur'], $m) || $m[1] !== $cells['Mappe'] || $m[2] !== $cells['Nr']) {
			$bad[] = 'row ' . ($i + 1) . ': shelf mark ' . $cells['Signatur'] . ' does not match binder and number';
		}
		if (isset($shelfmarks[$cells['Signatur']])) {
			$bad[] = 'shelf mark ' . $cells['Signatur'] . ' twice';
		}
		$shelfmarks[$cells['Signatur']] = $cells['Titel'];
		foreach (CatalogCsv::LEGACY_COLUMNS as $header => [$level, $field]) {
			$reason = $invalid($header, $level, $field, $cells[$header]);
			if ($reason !== null) {
				$bad[] = $cells['Signatur'] . ' "' . $header . '": ' . sprintf($reason, $header);
			}
		}
		$rights[$cells['Rechtestatus']] = ($rights[$cells['Rechtestatus']] ?? 0) + 1;
		$status = CatalogCsv::legacyPublicationStatus($cells['Veröffentlichung'] === '' ? null : $cells['Veröffentlichung']) ?? '(none)';
		$publication[$status] = ($publication[$status] ?? 0) + 1;
		$preservation += CatalogCsv::isLegacyPreservationCopy($cells['Veröffentlichung']) ? 1 : 0;
	}
	ksort($rights);
	ksort($publication);
	$check('catalog.csv: every cell valid for its field (rights status, lengths, numbers)', $bad === [], implode('; ', $bad));
	$check('catalog.csv: rows and rights status', count($csv['rows']) >= 20, count($csv['rows']) . ' rows, ' . json_encode($rights, JSON_UNESCAPED_UNICODE));
	$check('catalog.csv: legacy publication notes', $preservation === 1, json_encode($publication) . ", $preservation preservation copy");
}

// Supplement (extended columns)
if (($content = $read('catalog-supplement.csv')) !== null) {
	$csv = $roundTrip('catalog-supplement.csv', $content);
	$columns = CatalogCsv::columns(true);
	$unknown = array_values(array_filter($csv['header'], fn (string $h): bool => !isset($columns[$h])));
	$check('catalog-supplement.csv: all columns known to the import', $unknown === [], implode(', ', $unknown));
	$bad = [];
	$statuses = [];
	$order = [];
	foreach ($csv['rows'] as $row) {
		$cells = array_combine($csv['header'], $row);
		$order[] = $cells['Signatur'];
		foreach ($cells as $header => $text) {
			if ($header === 'Signatur' || !isset($columns[$header])) {
				continue;
			}
			[$level, $field] = $columns[$header];
			if (Fields::info($level, $field)['write'] === []) {
				$bad[] = "$header is read-only and would be skipped";
			}
			$reason = $invalid($header, $level, $field, $text);
			if ($reason !== null) {
				$bad[] = $cells['Signatur'] . ' "' . $header . '": ' . sprintf($reason, $header);
			}
		}
		$status = $cells['copy.status'] ?? '';
		if ($status !== '') {
			$statuses[] = $cells['Signatur'] . '=' . $status;
			if (trim($cells['copy.status_reason'] ?? '') === '') {
				$bad[] = $cells['Signatur'] . ': status without reason';
			}
		}
	}
	$check('catalog-supplement.csv: every value allowed', $bad === [], implode('; ', array_unique($bad)));
	$check('catalog-supplement.csv: same shelf marks in the same order as catalog.csv', $order === array_keys($shelfmarks));
	$check('catalog-supplement.csv: copies not active', $statuses !== [], implode(', ', $statuses));
}

// Persons
if (($content = $read('persons.csv')) !== null) {
	$csv = $roundTrip('persons.csv', $content);
	$check('persons.csv: required columns', array_diff(['Name', 'Signaturen'], $csv['header']) === []);
	$names = [];
	$renamed = [];
	$identification = [];
	$differs = [];
	$unknownMarks = [];
	$protected = [];
	foreach ($csv['rows'] as $row) {
		$r = array_map('trim', array_combine($csv['header'], $row));
		$name = PersonImportService::cleanName($r['Name']);
		if ($name !== $r['Name']) {
			$renamed[] = $r['Name'] . ' → ' . $name;
		}
		$names[mb_strtolower($name)] = ($names[mb_strtolower($name)] ?? 0) + 1;
		$text = $r['Lebensdaten recherchiert'] !== '' ? $r['Lebensdaten recherchiert'] : $r['Lebensdaten laut Verzeichnis'];
		$dates = LifeDates::parse($text);
		$dated = in_array($dates['died_precision'], ['exact', 'year', 'living'], true) || in_array($dates['born_precision'], ['exact', 'year'], true);
		$id = !str_contains(mb_strtoupper($r['Status']), 'NICHT ERMITTELBAR') && $dated ? 'probable' : 'uncertain';
		$identification[$id] = ($identification[$id] ?? 0) + 1;
		$died = LifeDates::year($dates['died']);
		if (in_array($dates['died_precision'], ['exact', 'year'], true) && $died !== null) {
			$expected = '31.12.' . ($died + 70);
			if ($r['Schutz bis'] !== $expected) {
				$differs[] = "$name: {$r['Schutz bis']} vs $expected";
			}
			if ($died + 70 >= 2026) {
				$protected[] = $name;
			}
		} elseif ($id === 'probable' && $dates['died_precision'] !== 'living') {
			$differs[] = "$name: no secured year of death ($text)";
		}
		foreach (preg_split('/[\s,;|]+/u', $r['Signaturen']) ?: [] as $mark) {
			if ($mark !== '' && !isset($shelfmarks[$mark])) {
				$unknownMarks[] = $mark;
			}
		}
	}
	ksort($identification);
	$duplicates = array_keys(array_filter($names, fn (int $n): bool => $n > 1));
	$check('persons.csv: role words removed from names', in_array('Tonsatz von Michael Prätorius → Michael Prätorius', $renamed, true), implode('; ', $renamed));
	$check('persons.csv: a person listed twice (found as existing)', $duplicates !== [], implode(', ', $duplicates));
	$check('persons.csv: identification at most probable, at least one uncertain', ($identification['uncertain'] ?? 0) >= 1 && !isset($identification['certain']),
		json_encode($identification));
	$check('persons.csv: "Schutz bis" agrees with the life dates', $differs === [], implode('; ', $differs));
	$check('persons.csv: all shelf marks in the catalog', $unknownMarks === [], implode(', ', $unknownMarks));
	$check('persons.csv: only the fictitious persons are protected', $protected === [], implode(', ', $protected));
	$check('persons.csv: contains Zelter (backup check of the integration test)', isset($names['carl friedrich zelter']));
}

// Performances and song lists
$classify = function (string $marks, string $assignment) use ($shelfmarks): string {
	$list = array_values(array_filter(array_map(
		fn (string $s): string => preg_match('/^\s*0*(\d+)-0*(\d+)\s*$/', $s, $m) ? (int)$m[1] . '-' . (int)$m[2] : '',
		preg_split('/[|,]/', $marks) ?: []), fn (string $s): bool => $s !== ''));
	$text = mb_strtolower(trim($assignment));
	return match (true) {
		$list === [] || str_contains($text, 'nicht im') => 'notInCatalog',
		count($list) > 1 || str_contains($text, 'mehrdeutig') || str_contains($text, 'unsicher') => 'ambiguous',
		!isset($shelfmarks[$list[0]]) => 'unknownShelfmark',
		default => 'certain',
	};
};
if (($content = $read('performances.csv')) !== null) {
	$csv = $roundTrip('performances.csv', $content);
	$check('performances.csv: programme header recognised', array_diff(['Datum', 'Anlass', 'Titel laut Programm', 'Signatur'], $csv['header']) === []);
	$own = PerformanceImportService::mostFrequent(['sheet' => 'CSV', 'header' => $csv['header'], 'rows' => $csv['rows']], 'Mitwirkung');
	$kinds = [];
	$counted = 0;
	$events = [];
	foreach ($csv['rows'] as $row) {
		$r = array_combine($csv['header'], $row);
		$events[$r['Datum'] . "\0" . $r['Anlass'] . "\0" . $r['Ort']] = true;
		$kind = $classify($r['Signatur'], $r['Zuordnung']);
		$kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
		$counted += $kind === 'certain' && PerformanceImportService::countsByRule($r['Mitwirkung'], $r['Stand'], $own) ? 1 : 0;
	}
	ksort($kinds);
	$check('performances.csv: own choir detected', $own === 'Demochor', (string)$own);
	$check('performances.csv: lines by assignment', ($kinds['ambiguous'] ?? 0) >= 1 && ($kinds['notInCatalog'] ?? 0) >= 1,
		count($events) . ' concerts, ' . count($csv['rows']) . ' lines, ' . json_encode($kinds) . ", $counted counted as performance");
}
if (($content = $read('song-lists.csv')) !== null) {
	$csv = $roundTrip('song-lists.csv', $content);
	$check('song-lists.csv: song list header recognised', array_diff(['Liste', 'Titel wie gedruckt', 'Signatur'], $csv['header']) === []);
	$kinds = [];
	$lists = [];
	foreach ($csv['rows'] as $row) {
		$r = array_combine($csv['header'], $row);
		$lists[$r['Liste']] = true;
		$marks = trim($r['Kandidaten']) !== '' && trim($r['Signatur']) === '' ? $r['Kandidaten'] : $r['Signatur'];
		$kind = $classify($marks, $r['Zuordnung']);
		$kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
	}
	ksort($kinds);
	$check('song-lists.csv: lines by assignment', ($kinds['ambiguous'] ?? 0) >= 1, count($lists) . ' lists (' . implode(', ', array_keys($lists)) . '), ' . json_encode($kinds));
}

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures checks failed\n";
exit($failures === 0 ? 0 : 1);
