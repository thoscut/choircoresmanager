<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ReviewPoint;
use OCA\Cantoria\Exception\InvalidInputException;

/**
 * Reads the report of a transcription (format `bericht/1`, see docs/api.md,
 * "Reviewing transcriptions and corrections") and turns it into the review
 * points it names. Pure functions, no server needed: the demo check script
 * and the unit tests use them directly.
 *
 * The report is untrusted input. It is limited in size and nesting, every
 * known field is type-checked and limited in length, unknown fields are
 * ignored (and named in a warning). A wrong type of a field the app uses is
 * refused; of a field the app only checks, it gives a warning.
 *
 * @psalm-import-type Warning from ImportService
 * @psalm-type Place = array{measures: ?string, measure_from: ?string, measure_to: ?string}
 * @psalm-type Uncertain = array{measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string, text: string, page: ?int, verse: ?int}
 * @psalm-type Omitted = array{measures: ?string, measure_from: ?string, measure_to: ?string, text: string}
 * @psalm-type Question = array{measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string, text: string, addressee: ?string}
 * @psalm-type Person = array{rolle: ?string, name: ?string, lebensdaten: ?string, beleg: ?string}
 * @psalm-type Done = int|array{measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string}
 * @psalm-type ParsedReport = array{signatur: string, basisname: string, titel: ?string, weg: ?string, runde: ?string, datum: ?string,
 *     vorlage: array{typ: ?string, beschreibung: ?string, seiten: ?int, schreiber_oder_verlag: ?string},
 *     umfang: array{takte: ?int, auftakt: ?bool, stimmen: ?int, systeme: ?int, strophen: ?int},
 *     unsicher: list<Uncertain>, ausgelassen: list<Omitted>, material: list<string>, rueckfragen: list<Question>,
 *     rechte: array{einstufung: ?string, beteiligte: list<Person>, ausgabe: ?string, rights: ?string},
 *     wv: array{herkunft: ?string, pruefstand: ?string, begruendung: ?string, felder: array<string, string>},
 *     erledigt: list<Done>}
 * @psalm-type ReportPoint = array{category: string, measures: ?string, measure_from: ?string, measure_to: ?string, voice: ?string,
 *     verse: ?int, page: ?int, text: string, role: string}
 */
final class TranscriptionReportParser {
	public const FORMAT = 'bericht/1';
	/** Largest report accepted (bytes) */
	public const MAX_BYTES = 1024 * 1024;
	/** Deepest nesting of arrays and objects accepted; a report needs four levels */
	public const MAX_DEPTH = 16;
	/** Most entries of a list in the report */
	public const MAX_ENTRIES = 500;

	/** Ways of a transcription */
	public const METHODS = ['neuaufbau', 'patch', 'quelldatei', 'abgebrochen'];
	/** Rights classifications and the rights status they propose */
	public const RIGHTS_CLASSES = CatalogCsv::LEGACY_RIGHTS_STATUS;

	/** Fields the format requires besides format, signatur and basisname; a missing one only gives a warning */
	private const EXPECTED = ['weg', 'vorlage', 'umfang', 'gegenprobe', 'geprueft', 'vortragszeichen', 'unsicher', 'rechte', 'wv', 'lernen', 'scores'];
	/** Every field the app knows (the last three are the proposed extension) */
	private const KNOWN = ['format', 'signatur', 'basisname', 'titel', 'weg', 'vorlage', 'umfang', 'gegenprobe', 'geprueft',
		'vortragszeichen', 'unsicher', 'material', 'rechte', 'wv', 'rueckfragen', 'lernen', 'scores', 'pruefpunkte', 'generator',
		'aufwand', 'runde', 'datum', 'erledigt'];
	/** Voices that mean "no single voice" */
	private const NO_VOICE = ['', '-', '–', 'alle', 'all', 'tutti', 'alle stimmen', 'all voices', 'gesamt'];
	/** Printed measure numbers as the tasks accept them */
	private const MEASURE = '/^[0-9A-Za-z.]{1,16}$/';
	/** A reference to measures in free text: "T. 23", "Takt 5", "T. 12–14" */
	private const MEASURE_REFERENCE = '(?:T\.|Tkt\.|Takte?\b)\s*(\d{1,4}[a-zA-Z]?(?:\s*[–—-]\s*\d{1,4}[a-zA-Z]?)?)';
	/** Addressees of a question in the proposed extension */
	private const ADDRESSEES = [
		'chorleitung' => Roles::CONDUCTOR, 'chorleiter' => Roles::CONDUCTOR, 'chorleiterin' => Roles::CONDUCTOR, 'conductor' => Roles::CONDUCTOR,
		'archiv' => Roles::LIBRARIAN, 'archivverwaltung' => Roles::LIBRARIAN, 'notenwart' => Roles::LIBRARIAN, 'librarian' => Roles::LIBRARIAN,
	];

	/**
	 * Checks a report and returns its known fields, normalized.
	 *
	 * @return array{report: ParsedReport, warnings: list<Warning>}
	 * @throws InvalidInputException
	 */
	public static function parse(string $raw): array {
		if (strlen($raw) > self::MAX_BYTES) {
			throw new InvalidInputException('The report is too large (at most %s MB).', [intdiv(self::MAX_BYTES, 1024 * 1024)]);
		}
		if (str_starts_with($raw, "\xEF\xBB\xBF")) {
			$raw = substr($raw, 3);
		}
		try {
			$data = self::root(json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
		} catch (\JsonException $e) {
			if ($e->getCode() === JSON_ERROR_DEPTH) {
				throw new InvalidInputException('The report is nested too deeply.');
			}
			throw new InvalidInputException('The report is not valid JSON.');
		}

		$warnings = [];
		$unknown = array_values(array_diff(array_map('strval', array_keys($data)), self::KNOWN));
		if ($unknown !== []) {
			$warnings[] = ['Unknown fields in the report were ignored: %s', [implode(', ', array_slice($unknown, 0, 20))]];
		}
		foreach (self::EXPECTED as $field) {
			if (!array_key_exists($field, $data)) {
				$warnings[] = ['"%s" is missing in the report.', [$field]];
			}
		}

		$format = self::text($data, 'format', 'format', 20, true);
		if ($format !== self::FORMAT) {
			throw new InvalidInputException('The report has an unknown format (expected bericht/1).');
		}
		$signatur = self::text($data, 'signatur', 'signatur', 20, true, true) ?? '';
		$shelfmark = self::canonicalShelfmark($signatur);
		if ($shelfmark === null) {
			throw new InvalidInputException('The shelf mark in the report is not valid.');
		}
		$basisname = self::basename($data);
		$prefix = preg_match('/^(\d+-\d+)(?!\d)/', $basisname, $match) === 1 ? self::canonicalShelfmark($match[1]) : null;
		if ($prefix !== null && $prefix !== $shelfmark) {
			throw new InvalidInputException('The base name in the report belongs to another shelf mark.');
		}

		$weg = self::text($data, 'weg', 'weg', 32);
		if ($weg !== null && !in_array($weg, self::METHODS, true)) {
			$warnings[] = ['Unknown way of transcription "%s" was ignored.', [$weg]];
			$weg = null;
		}
		$datum = self::text($data, 'datum', 'datum', 10, false, true);
		if ($datum !== null && !FieldValue::isDate($datum)) {
			$warnings[] = ['"%s" in the report is not a date (YYYY-MM-DD) and was ignored.', ['datum']];
			$datum = null;
		}

		$vortragszeichen = self::object($data, 'vortragszeichen', 'vortragszeichen');
		$report = [
			'signatur' => $shelfmark,
			'basisname' => $basisname,
			'titel' => self::text($data, 'titel', 'titel', 500),
			'weg' => $weg,
			'runde' => self::text($data, 'runde', 'runde', 40, false, true),
			'datum' => $datum,
			'vorlage' => self::template($data, $warnings),
			'umfang' => self::extent($data, $warnings),
			'unsicher' => self::uncertain($data),
			'ausgelassen' => self::omitted($vortragszeichen ?? []),
			'material' => self::textList($data, 'material', 'material', 2000),
			'rueckfragen' => self::questions($data),
			'rechte' => self::rights($data, $warnings),
			'wv' => self::catalog($data),
			'erledigt' => self::done($data),
		];
		self::checkOther($data, $warnings);
		return ['report' => $report, 'warnings' => $warnings];
	}

	/**
	 * The review points a report names, in its order: uncertain readings,
	 * omissions, findings about the material, questions.
	 *
	 * @param ParsedReport $report
	 * @return list<ReportPoint>
	 */
	public static function points(array $report): array {
		$points = [];
		foreach ($report['unsicher'] as $entry) {
			$points[] = ['category' => ReviewPoint::UNCERTAIN, 'measures' => $entry['measures'], 'measure_from' => $entry['measure_from'],
				'measure_to' => $entry['measure_to'], 'voice' => $entry['voice'], 'verse' => $entry['verse'], 'page' => $entry['page'],
				'text' => $entry['text'], 'role' => self::roleFor(ReviewPoint::UNCERTAIN, true, null)];
		}
		foreach ($report['ausgelassen'] as $entry) {
			$points[] = ['category' => ReviewPoint::OMITTED, 'measures' => $entry['measures'], 'measure_from' => $entry['measure_from'],
				'measure_to' => $entry['measure_to'], 'voice' => null, 'verse' => null, 'page' => null,
				'text' => $entry['text'], 'role' => self::roleFor(ReviewPoint::OMITTED, true, null)];
		}
		foreach ($report['material'] as $text) {
			$points[] = ['category' => ReviewPoint::MATERIAL, 'measures' => null, 'measure_from' => null, 'measure_to' => null,
				'voice' => null, 'verse' => null, 'page' => null, 'text' => $text, 'role' => self::roleFor(ReviewPoint::MATERIAL, false, null)];
		}
		foreach ($report['rueckfragen'] as $entry) {
			$points[] = ['category' => ReviewPoint::QUESTION, 'measures' => $entry['measures'], 'measure_from' => $entry['measure_from'],
				'measure_to' => $entry['measure_to'], 'voice' => $entry['voice'], 'verse' => null, 'page' => null, 'text' => $entry['text'],
				'role' => self::roleFor(ReviewPoint::QUESTION, $entry['measures'] !== null, $entry['addressee'])];
		}
		return $points;
	}

	/**
	 * The role responsible for a point: musical points (uncertain readings,
	 * omissions, questions about measures) go to the conductor, the material
	 * and questions without a place in the score to the catalog librarian.
	 * The catalog librarian may decide every point anyway.
	 */
	public static function roleFor(string $category, bool $hasMeasures, ?string $addressee): string {
		if ($addressee !== null) {
			return $addressee;
		}
		return match ($category) {
			ReviewPoint::UNCERTAIN, ReviewPoint::OMITTED => Roles::CONDUCTOR,
			ReviewPoint::QUESTION => $hasMeasures ? Roles::CONDUCTOR : Roles::LIBRARIAN,
			default => Roles::LIBRARIAN,
		};
	}

	/**
	 * Identifies the same point in a later report of the copy: category,
	 * measures, voice and text, compared without case and extra spaces.
	 *
	 * @param ReportPoint $point
	 */
	public static function pointKey(array $point): string {
		$normalize = fn (?string $text): string => mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)$text)));
		return hash('sha256', implode("\x1F", [$point['category'], $normalize($point['measure_from'] ?? $point['measures']),
			$normalize($point['measure_to'] ?? $point['measures']), $normalize($point['voice']), $normalize($point['text'])]));
	}

	/**
	 * Canonical shelf mark ("05-01" → "5-1"), or null if the text is none.
	 */
	public static function canonicalShelfmark(string $shelfmark): ?string {
		if (preg_match('/^\s*0*(\d{1,6})\s*-\s*0*(\d{1,6})\s*$/', $shelfmark, $m) !== 1) {
			return null;
		}
		return (int)$m[1] . '-' . (int)$m[2];
	}

	/**
	 * Measures as printed: "23", "12–14", "T. 12-14", "Takt 5", also a
	 * number. `measures` keeps the text, `measure_from`/`measure_to` are set
	 * when both ends look like printed measure numbers.
	 *
	 * @return Place
	 */
	public static function measures(string|int|null $value): array {
		$text = $value === null ? '' : trim((string)$value);
		if ($text === '') {
			return ['measures' => null, 'measure_from' => null, 'measure_to' => null];
		}
		$core = (string)preg_replace('/^(?:measures?|bars?|Takte?|Tkt\.?|T\.?|mm?\.?)\s*(?=\d)/iu', '', $text);
		$parts = preg_split('/\s*(?:[–—,;\/]|-|\bbis\b|\bto\b|\bund\b|\band\b)\s*/u', $core, -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false) {
			$parts = [];
		}
		$from = $parts[0] ?? null;
		$to = $parts === [] ? null : $parts[count($parts) - 1];
		if ($from === null || $to === null || !self::isMeasureNumber($from) || !self::isMeasureNumber($to)) {
			$from = $to = null;
		}
		return ['measures' => mb_substr($text, 0, 32), 'measure_from' => $from, 'measure_to' => $to];
	}

	/**
	 * Whether a text looks like a printed measure number ("0", "17", "34X"),
	 * the way tasks accept them.
	 */
	public static function isMeasureNumber(string $text): bool {
		return preg_match(self::MEASURE, $text) === 1 && preg_match('/\d/', $text) === 1;
	}

	/**
	 * The measures a free text refers to ("T. 23 …", "… in Takt 5"), or none.
	 *
	 * @return Place
	 */
	public static function measuresIn(string $text, bool $leadingOnly): array {
		$pattern = '/' . ($leadingOnly ? '^\s*' : '') . self::MEASURE_REFERENCE . '/u';
		if (preg_match($pattern, $text, $m) !== 1) {
			return ['measures' => null, 'measure_from' => null, 'measure_to' => null];
		}
		return self::measures($m[1]);
	}

	/**
	 * A voice as written in the report; null for all voices.
	 */
	public static function voice(?string $voice): ?string {
		if ($voice === null || in_array(mb_strtolower(trim($voice)), self::NO_VOICE, true)) {
			return null;
		}
		return mb_substr(trim($voice), 0, 100);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @throws InvalidInputException
	 */
	private static function basename(array $data): string {
		// Checked before the text is cleaned: a control character is refused, not removed.
		if (self::hasControlCharacter($data['basisname'] ?? null)) {
			throw new InvalidInputException('The base name in the report is not a valid file name.');
		}
		$basename = self::text($data, 'basisname', 'basisname', 200, true) ?? '';
		if ($basename === '.' || $basename === '..' || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $basename) === 1
			|| str_starts_with($basename, '.') || $basename !== trim($basename)) {
			throw new InvalidInputException('The base name in the report is not a valid file name.');
		}
		return $basename;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<Warning> $warnings
	 * @return array{typ: ?string, beschreibung: ?string, seiten: ?int, schreiber_oder_verlag: ?string}
	 */
	private static function template(array $data, array &$warnings): array {
		$template = self::lenient(fn () => self::object($data, 'vorlage', 'vorlage'), 'vorlage', $warnings) ?? [];
		return [
			'typ' => self::lenient(fn () => self::text($template, 'typ', 'vorlage.typ', 32), 'vorlage.typ', $warnings),
			'beschreibung' => self::lenient(fn () => self::text($template, 'beschreibung', 'vorlage.beschreibung', 2000), 'vorlage.beschreibung', $warnings),
			'seiten' => self::lenient(fn () => self::integer($template, 'seiten', 'vorlage.seiten'), 'vorlage.seiten', $warnings),
			'schreiber_oder_verlag' => self::lenient(fn () => self::text($template, 'schreiber_oder_verlag', 'vorlage.schreiber_oder_verlag', 500),
				'vorlage.schreiber_oder_verlag', $warnings),
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<Warning> $warnings
	 * @return array{takte: ?int, auftakt: ?bool, stimmen: ?int, systeme: ?int, strophen: ?int}
	 */
	private static function extent(array $data, array &$warnings): array {
		$extent = self::lenient(fn () => self::object($data, 'umfang', 'umfang'), 'umfang', $warnings) ?? [];
		$number = function (string $key) use ($extent, &$warnings): ?int {
			return self::lenient(fn () => self::integer($extent, $key, 'umfang.' . $key), 'umfang.' . $key, $warnings);
		};
		return [
			'takte' => $number('takte'),
			'auftakt' => self::lenient(fn () => self::boolean($extent, 'auftakt', 'umfang.auftakt'), 'umfang.auftakt', $warnings),
			'stimmen' => $number('stimmen'),
			'systeme' => $number('systeme'),
			'strophen' => $number('strophen'),
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return list<Uncertain>
	 * @throws InvalidInputException
	 */
	private static function uncertain(array $data): array {
		$entries = [];
		$list = self::entries($data, 'unsicher', 'unsicher');
		foreach (array_keys($list) as $i) {
			$path = 'unsicher[' . $i . ']';
			$entry = self::objectValue($list[$i], $path);
			$text = self::text($entry, 'was', $path . '.was', 2000, true) ?? '';
			$entries[] = self::measures(self::text($entry, 'takt', $path . '.takt', 64, false, true)) + [
				'voice' => self::voice(self::text($entry, 'stimme', $path . '.stimme', 100, false, true)),
				'text' => $text,
				'page' => self::integer($entry, 'seite', $path . '.seite'),
				'verse' => self::integer($entry, 'strophe', $path . '.strophe'),
			];
		}
		return $entries;
	}

	/**
	 * @param array<array-key, mixed> $marks the object `vortragszeichen`
	 * @return list<Omitted>
	 * @throws InvalidInputException
	 */
	private static function omitted(array $marks): array {
		$entries = [];
		foreach (self::textList($marks, 'ausgelassen', 'vortragszeichen.ausgelassen', 2000) as $text) {
			$entries[] = self::measuresIn($text, true) + ['text' => $text];
		}
		return $entries;
	}

	/**
	 * Questions: text, or (proposed extension) an object with the question,
	 * a place and an addressee.
	 *
	 * @param array<array-key, mixed> $data
	 * @return list<Question>
	 * @throws InvalidInputException
	 */
	private static function questions(array $data): array {
		$entries = [];
		$list = self::entries($data, 'rueckfragen', 'rueckfragen');
		foreach (array_keys($list) as $i) {
			$path = 'rueckfragen[' . $i . ']';
			$entry = self::asObject($list[$i]);
			if ($entry === null) {
				// A question as text
				$text = self::textValue($list[$i], $path, 2000, false, false);
				if ($text !== null) {
					$entries[] = self::measuresIn($text, false) + ['voice' => null, 'text' => $text, 'addressee' => null];
				}
				continue;
			}
			$text = self::text($entry, 'frage', $path . '.frage', 2000) ?? self::text($entry, 'text', $path . '.text', 2000);
			if ($text === null) {
				throw new InvalidInputException('"%s" is missing in the report.', [$path . '.frage']);
			}
			$place = self::measures(self::text($entry, 'takt', $path . '.takt', 64, false, true));
			$addressee = self::text($entry, 'an', $path . '.an', 40);
			$entries[] = ($place['measures'] !== null ? $place : self::measuresIn($text, false)) + [
				'voice' => self::voice(self::text($entry, 'stimme', $path . '.stimme', 100, false, true)),
				'text' => $text,
				'addressee' => $addressee === null ? null : (self::ADDRESSEES[mb_strtolower($addressee)] ?? null),
			];
		}
		return $entries;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<Warning> $warnings
	 * @return array{einstufung: ?string, beteiligte: list<Person>, ausgabe: ?string, rights: ?string}
	 * @throws InvalidInputException
	 */
	private static function rights(array $data, array &$warnings): array {
		$rights = self::object($data, 'rechte', 'rechte') ?? [];
		$class = self::text($rights, 'einstufung', 'rechte.einstufung', 32);
		if ($class !== null && !isset(self::RIGHTS_CLASSES[$class])) {
			$warnings[] = ['Unknown rights classification "%s" was ignored.', [$class]];
			$class = null;
		}
		$persons = [];
		$list = self::entries($rights, 'beteiligte', 'rechte.beteiligte', 50);
		foreach (array_keys($list) as $i) {
			$path = 'rechte.beteiligte[' . $i . ']';
			$entry = self::objectValue($list[$i], $path);
			$persons[] = [
				'rolle' => self::text($entry, 'rolle', $path . '.rolle', 500),
				'name' => self::text($entry, 'name', $path . '.name', 500),
				'lebensdaten' => self::text($entry, 'lebensdaten', $path . '.lebensdaten', 500, false, true),
				'beleg' => self::text($entry, 'beleg', $path . '.beleg', 1000),
			];
		}
		return [
			'einstufung' => $class,
			'beteiligte' => $persons,
			'ausgabe' => self::text($rights, 'ausgabe', 'rechte.ausgabe', 1000),
			'rights' => self::text($rights, 'rights', 'rechte.rights', 2000),
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array{herkunft: ?string, pruefstand: ?string, begruendung: ?string, felder: array<string, string>}
	 * @throws InvalidInputException
	 */
	private static function catalog(array $data): array {
		$wv = self::object($data, 'wv', 'wv') ?? [];
		$fields = [];
		$given = self::object($wv, 'felder', 'wv.felder') ?? [];
		if (count($given) > 50) {
			throw new InvalidInputException('"%s" in the report has too many entries.', ['wv.felder']);
		}
		foreach (array_keys($given) as $key) {
			$name = (string)$key;
			$path = 'wv.felder.' . mb_substr($name, 0, 100);
			if (mb_strlen($name) > 100) {
				throw new InvalidInputException('"%s" in the report is too long.', [$path]);
			}
			$text = self::text($given, $name, $path, 2000, false, true);
			if ($text !== null) {
				$fields[$name] = $text;
			}
		}
		return [
			'herkunft' => self::text($wv, 'herkunft', 'wv.herkunft', 5000),
			'pruefstand' => self::text($wv, 'pruefstand', 'wv.pruefstand', 20000),
			'begruendung' => self::text($wv, 'begruendung', 'wv.begruendung', 5000),
			'felder' => $fields,
		];
	}

	/**
	 * Points the new file has done (proposed extension): review point ids
	 * or places.
	 *
	 * @param array<array-key, mixed> $data
	 * @return list<Done>
	 * @throws InvalidInputException
	 */
	private static function done(array $data): array {
		$done = [];
		$list = self::entries($data, 'erledigt', 'erledigt');
		foreach (array_keys($list) as $i) {
			$id = self::id($list[$i]);
			if ($id !== null) {
				$done[] = $id;
				continue;
			}
			$path = 'erledigt[' . $i . ']';
			$entry = self::objectValue($list[$i], $path);
			$done[] = self::measures(self::text($entry, 'takt', $path . '.takt', 64, false, true))
				+ ['voice' => self::voice(self::text($entry, 'stimme', $path . '.stimme', 100, false, true))];
		}
		return $done;
	}

	/**
	 * Types of the fields the app does not use; a wrong type only gives a
	 * warning.
	 *
	 * @param array<array-key, mixed> $data
	 * @param list<Warning> $warnings
	 */
	private static function checkOther(array $data, array &$warnings): void {
		$expected = ['gegenprobe' => 'object', 'geprueft' => 'object', 'aufwand' => 'object', 'lernen' => 'list', 'pruefpunkte' => 'list',
			'generator' => 'list', 'scores' => 'text'];
		foreach ($expected as $field => $type) {
			if (!self::hasType($data[$field] ?? null, $type)) {
				$warnings[] = ['"%s" in the report has an unexpected type and was ignored.', [$field]];
			}
		}
	}

	/**
	 * Whether a value is missing (null) or of a type: `object`, `list` or `text`.
	 */
	private static function hasType(mixed $value, string $type): bool {
		return $value === null || match ($type) {
			'object' => is_array($value) && ($value === [] || !array_is_list($value)),
			'list' => is_array($value) && array_is_list($value),
			default => is_string($value),
		};
	}

	/**
	 * Runs a check of a field the app only shows; a wrong value gives a
	 * warning and null instead of an error.
	 *
	 * @template T
	 * @param callable(): T $check
	 * @param list<Warning> $warnings
	 * @return T|null
	 */
	private static function lenient(callable $check, string $path, array &$warnings): mixed {
		try {
			return $check();
		} catch (InvalidInputException) {
			$warnings[] = ['"%s" in the report has an unexpected type and was ignored.', [$path]];
			return null;
		}
	}

	/**
	 * The decoded report: an object.
	 *
	 * @return array<array-key, mixed>
	 * @throws InvalidInputException
	 */
	private static function root(mixed $value): array {
		$object = self::asObject($value);
		if ($object === null) {
			throw new InvalidInputException('The report must be a JSON object.');
		}
		return $object;
	}

	/**
	 * An object (JSON `{}`; an empty `[]` counts as an empty object), or null.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private static function asObject(mixed $value): ?array {
		return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
	}

	private static function hasControlCharacter(mixed $value): bool {
		return is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
	}

	/**
	 * A review point id (a whole number above 0, also as text), or null.
	 */
	private static function id(mixed $value): ?int {
		if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
			$value = (int)$value;
		}
		return is_int($value) && $value > 0 ? $value : null;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @throws InvalidInputException
	 */
	private static function text(array $data, string $key, string $path, int $max, bool $required = false, bool $numbers = false): ?string {
		return self::textValue($data[$key] ?? null, $path, $max, $required, $numbers);
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function textValue(mixed $value, string $path, int $max, bool $required, bool $numbers): ?string {
		if ($value === null) {
			if ($required) {
				throw new InvalidInputException('"%s" is missing in the report.', [$path]);
			}
			return null;
		}
		if ($numbers && (is_int($value) || is_float($value))) {
			$value = (string)$value;
		}
		if (!is_string($value)) {
			throw new InvalidInputException('"%s" in the report must be text.', [$path]);
		}
		$text = self::cleanText($value, $path, $max);
		if ($text === null && $required) {
			throw new InvalidInputException('"%s" is missing in the report.', [$path]);
		}
		return $text;
	}

	/**
	 * Text without control characters (line breaks and tabs stay), trimmed;
	 * null when empty.
	 *
	 * @throws InvalidInputException when longer than $max characters
	 */
	private static function cleanText(string $value, string $path, int $max): ?string {
		$text = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
		if ($text === '') {
			return null;
		}
		if (mb_strlen($text) > $max) {
			throw new InvalidInputException('"%s" in the report is too long.', [$path]);
		}
		return $text;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @throws InvalidInputException
	 */
	private static function integer(array $data, string $key, string $path): ?int {
		return self::integerValue($data[$key] ?? null, $path);
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function integerValue(mixed $value, string $path): ?int {
		if ($value === null) {
			return null;
		}
		if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
			$value = (int)trim($value);
		}
		if (!is_int($value) || $value < 0 || $value > 1000000) {
			throw new InvalidInputException('"%s" in the report must be a whole number.', [$path]);
		}
		return $value;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @throws InvalidInputException
	 */
	private static function boolean(array $data, string $key, string $path): ?bool {
		return self::booleanValue($data[$key] ?? null, $path);
	}

	/**
	 * @throws InvalidInputException
	 */
	private static function booleanValue(mixed $value, string $path): ?bool {
		if ($value === null) {
			return null;
		}
		if (!is_bool($value)) {
			throw new InvalidInputException('"%s" in the report must be yes or no.', [$path]);
		}
		return $value;
	}

	/**
	 * An object (JSON `{}`; an empty `[]` counts as an empty object), or null.
	 *
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>|null
	 * @throws InvalidInputException
	 */
	private static function object(array $data, string $key, string $path): ?array {
		if (($data[$key] ?? null) === null) {
			return null;
		}
		return self::objectValue($data[$key], $path);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws InvalidInputException
	 */
	private static function objectValue(mixed $value, string $path): array {
		$object = self::asObject($value);
		if ($object === null) {
			throw new InvalidInputException('"%s" in the report must be an object.', [$path]);
		}
		return $object;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return list<mixed>
	 * @throws InvalidInputException
	 */
	private static function entries(array $data, string $key, string $path, int $max = self::MAX_ENTRIES): array {
		return self::listValue($data[$key] ?? null, $path, $max);
	}

	/**
	 * @return list<mixed>
	 * @throws InvalidInputException
	 */
	private static function listValue(mixed $value, string $path, int $max): array {
		if ($value === null) {
			return [];
		}
		if (!is_array($value) || !array_is_list($value)) {
			throw new InvalidInputException('"%s" in the report must be a list.', [$path]);
		}
		if (count($value) > $max) {
			throw new InvalidInputException('"%s" in the report has too many entries.', [$path]);
		}
		return $value;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return list<string>
	 * @throws InvalidInputException
	 */
	private static function textList(array $data, string $key, string $path, int $max): array {
		$texts = [];
		$list = self::entries($data, $key, $path);
		foreach (array_keys($list) as $i) {
			$text = self::textValue($list[$i], $path . '[' . $i . ']', $max, false, false);
			if ($text !== null) {
				$texts[] = $text;
			}
		}
		return $texts;
	}
}
