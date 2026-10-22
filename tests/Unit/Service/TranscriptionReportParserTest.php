<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ReviewPoint;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TranscriptionReportParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading the report of a transcription (format bericht/1): the fields the
 * app uses, the review points they become, and untrusted input that is
 * too large, too deep, of the wrong type or with a path in the base name.
 * The report here is invented.
 */
#[CoversClass(TranscriptionReportParser::class)]
final class TranscriptionReportParserTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function report(array $changes = []): array {
		$report = [
			'format' => 'bericht/1',
			'signatur' => '7-03',
			'basisname' => '7-03_Ein Testlied',
			'titel' => 'Ein Testlied',
			'weg' => 'neuaufbau',
			'vorlage' => ['typ' => 'druck', 'seiten' => 2, 'beschreibung' => 'Zwei Seiten, vier Systeme.', 'schreiber_oder_verlag' => 'Testverlag'],
			'umfang' => ['takte' => 24, 'auftakt' => true, 'stimmen' => 4, 'systeme' => 2, 'strophen' => 2],
			'gegenprobe' => ['audiveris' => 'keine', 'ersatz' => []],
			'geprueft' => ['taktlaengen' => 'voll'],
			'vortragszeichen' => ['dynamik' => 2, 'ausgelassen' => ['T. 12–14 Bögen im Bass', 'Atemzeichen']],
			'unsicher' => [
				['takt' => '23', 'stimme' => 'B', 'was' => 'd oder c'],
				['takt' => 5, 'stimme' => 'alle', 'was' => 'Punkt oder Fleck'],
				['takt' => 'T. 7-8', 'stimme' => 'T1', 'was' => 'Silbe unklar'],
			],
			'material' => ['Seite 2 fehlt in der zweiten Kopie'],
			'rueckfragen' => ['Soll T. 9 wie gedruckt gesungen werden?', 'Gehört die zweite PDF zu einem anderen Werk?'],
			'rechte' => ['einstufung' => 'gemeinfrei', 'beteiligte' => [['rolle' => 'Musik', 'name' => 'A. Beispiel', 'lebensdaten' => '1801–1870', 'beleg' => 'Testquelle']],
				'ausgabe' => 'Testverlag 1900'],
			'wv' => ['herkunft' => 'Neuaufbau aus der Spec.', 'pruefstand' => 'Alle Takte gegengelesen; unsicher T. 23.', 'begruendung' => 'Alle Beteiligten seit über 70 Jahren tot.',
				'felder' => ['Verlag / Bestellnummer' => 'Testverlag 12']],
			'lernen' => [],
			'scores' => '| 7-3 | Ein Testlied | … |',
		];
		return array_replace($report, $changes);
	}

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	public function testAValidReport(): void {
		$result = TranscriptionReportParser::parse(self::json(self::report()));
		$report = $result['report'];
		self::assertSame([], $result['warnings']);
		self::assertSame('7-3', $report['signatur']);
		self::assertSame('7-03_Ein Testlied', $report['basisname']);
		self::assertSame('neuaufbau', $report['weg']);
		self::assertSame(['measures' => '23', 'measure_from' => '23', 'measure_to' => '23', 'voice' => 'B', 'text' => 'd oder c', 'page' => null, 'verse' => null],
			$report['unsicher'][0]);
		self::assertNull($report['unsicher'][1]['voice'], '"alle" is no single voice');
		self::assertSame('5', $report['unsicher'][1]['measure_from'], 'a number is a measure');
		self::assertSame(['7', '8'], [$report['unsicher'][2]['measure_from'], $report['unsicher'][2]['measure_to']]);
		self::assertSame(['12', '14'], [$report['ausgelassen'][0]['measure_from'], $report['ausgelassen'][0]['measure_to']]);
		self::assertNull($report['ausgelassen'][1]['measures']);
		self::assertSame('gemeinfrei', $report['rechte']['einstufung']);
		self::assertSame('A. Beispiel', $report['rechte']['beteiligte'][0]['name']);
		self::assertSame(['Verlag / Bestellnummer' => 'Testverlag 12'], $report['wv']['felder']);
		self::assertSame(24, $report['umfang']['takte']);
	}

	public function testPointsAndTheirRoles(): void {
		$points = TranscriptionReportParser::points(TranscriptionReportParser::parse(self::json(self::report()))['report']);
		self::assertSame(['uncertain', 'uncertain', 'uncertain', 'omitted', 'omitted', 'material', 'question', 'question'], array_column($points, 'category'));
		$roles = array_column($points, 'role');
		self::assertSame(Roles::CONDUCTOR, $roles[0], 'musical points go to the conductor');
		self::assertSame(Roles::CONDUCTOR, $roles[3]);
		self::assertSame(Roles::LIBRARIAN, $roles[5], 'the material goes to the catalog librarian');
		self::assertSame(Roles::CONDUCTOR, $roles[6], 'a question about measures goes to the conductor');
		self::assertSame('9', $points[6]['measure_from']);
		self::assertSame(Roles::LIBRARIAN, $roles[7], 'a question without measures goes to the catalog librarian');
	}

	public function testRoleRules(): void {
		self::assertSame(Roles::CONDUCTOR, TranscriptionReportParser::roleFor(ReviewPoint::UNCERTAIN, false, null));
		self::assertSame(Roles::CONDUCTOR, TranscriptionReportParser::roleFor(ReviewPoint::OMITTED, false, null));
		self::assertSame(Roles::LIBRARIAN, TranscriptionReportParser::roleFor(ReviewPoint::MATERIAL, true, null));
		self::assertSame(Roles::LIBRARIAN, TranscriptionReportParser::roleFor(ReviewPoint::QUESTION, false, null));
		self::assertSame(Roles::LIBRARIAN, TranscriptionReportParser::roleFor(ReviewPoint::QUESTION, true, Roles::LIBRARIAN), 'an addressee wins');
	}

	public function testQuestionsAsObjectsAndDonePoints(): void {
		$report = TranscriptionReportParser::parse(self::json(self::report([
			'rueckfragen' => [['frage' => 'Wie im Druck?', 'takt' => '4', 'stimme' => 'Alt', 'an' => 'Archiv']],
			'erledigt' => [12, '13', ['takt' => '23', 'stimme' => 'B']],
			'runde' => 41,
			'datum' => '2026-10-20',
		])))['report'];
		self::assertSame(['measures' => '4', 'measure_from' => '4', 'measure_to' => '4', 'voice' => 'Alt', 'text' => 'Wie im Druck?', 'addressee' => Roles::LIBRARIAN],
			$report['rueckfragen'][0]);
		self::assertSame(12, $report['erledigt'][0]);
		self::assertSame(13, $report['erledigt'][1]);
		self::assertSame(['measures' => '23', 'measure_from' => '23', 'measure_to' => '23', 'voice' => 'B'], $report['erledigt'][2]);
		self::assertSame('41', $report['runde']);
		self::assertSame('2026-10-20', $report['datum']);
	}

	public function testUnknownAndMissingFieldsGiveWarnings(): void {
		$data = self::report(['zusatz' => ['x' => 1], 'weg' => 'zauberei', 'datum' => '20.10.2026']);
		unset($data['lernen']);
		$result = TranscriptionReportParser::parse(self::json($data));
		$messages = array_column($result['warnings'], 0);
		self::assertContains('Unknown fields in the report were ignored: %s', $messages);
		self::assertContains('"%s" is missing in the report.', $messages);
		self::assertContains('Unknown way of transcription "%s" was ignored.', $messages);
		self::assertContains('"%s" in the report is not a date (YYYY-MM-DD) and was ignored.', $messages);
		self::assertNull($result['report']['weg']);
		self::assertArrayNotHasKey('zusatz', $result['report'], 'unknown fields are not kept');
	}

	public function testAWrongTypeOfAShownFieldOnlyWarns(): void {
		$result = TranscriptionReportParser::parse(self::json(self::report(['umfang' => ['takte' => 'viele'], 'geprueft' => 'ja'])));
		self::assertNull($result['report']['umfang']['takte']);
		self::assertContains('"%s" in the report has an unexpected type and was ignored.', array_column($result['warnings'], 0));
	}

	public function testMeasures(): void {
		self::assertSame(['measures' => '12–14', 'measure_from' => '12', 'measure_to' => '14'], TranscriptionReportParser::measures('12–14'));
		self::assertSame(['measures' => 'Takt 5', 'measure_from' => '5', 'measure_to' => '5'], TranscriptionReportParser::measures('Takt 5'));
		self::assertSame(['measures' => '0', 'measure_from' => '0', 'measure_to' => '0'], TranscriptionReportParser::measures(0));
		self::assertSame(['measures' => '23, 25', 'measure_from' => '23', 'measure_to' => '25'], TranscriptionReportParser::measures('23, 25'));
		self::assertSame(['measures' => 'alle', 'measure_from' => null, 'measure_to' => null], TranscriptionReportParser::measures('alle'));
		self::assertSame(['measures' => '17; DROP', 'measure_from' => null, 'measure_to' => null], TranscriptionReportParser::measures('17; DROP'));
		self::assertSame(['measures' => null, 'measure_from' => null, 'measure_to' => null], TranscriptionReportParser::measures(null));
		self::assertSame('9', TranscriptionReportParser::measuresIn('Gilt das auch für T. 9?', false)['measure_from']);
		self::assertNull(TranscriptionReportParser::measuresIn('Gilt das auch für T. 9?', true)['measures']);
	}

	public function testPointKeysIgnoreCaseAndSpaces(): void {
		$point = ['category' => 'uncertain', 'measures' => '23', 'measure_from' => '23', 'measure_to' => '23', 'voice' => 'B', 'verse' => null, 'page' => null,
			'text' => 'd oder c', 'role' => Roles::CONDUCTOR];
		self::assertSame(TranscriptionReportParser::pointKey($point), TranscriptionReportParser::pointKey(['text' => '  D  oder C ', 'voice' => 'b'] + $point));
		self::assertNotSame(TranscriptionReportParser::pointKey($point), TranscriptionReportParser::pointKey(['measure_from' => '24', 'measure_to' => '24'] + $point));
	}

	public function testCanonicalShelfmark(): void {
		self::assertSame('5-1', TranscriptionReportParser::canonicalShelfmark('05-01'));
		self::assertSame('12-345', TranscriptionReportParser::canonicalShelfmark(' 12 - 345 '));
		self::assertNull(TranscriptionReportParser::canonicalShelfmark('5/1'));
		self::assertNull(TranscriptionReportParser::canonicalShelfmark('5-1; x'));
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function refused(): iterable {
		yield 'no JSON' => ['{"format": "bericht/1",', 'The report is not valid JSON.'];
		yield 'a list' => ['[1, 2]', 'The report must be a JSON object.'];
		yield 'a string' => ['"bericht"', 'The report must be a JSON object.'];
		yield 'too deep' => ['{"format": "bericht/1", "x": ' . str_repeat('[', 40) . str_repeat(']', 40) . '}', 'The report is nested too deeply.'];
		yield 'other format' => [self::json(self::report(['format' => 'bericht/2'])), 'The report has an unknown format (expected bericht/1).'];
		yield 'format missing' => [self::json(array_diff_key(self::report(), ['format' => true])), '"%s" is missing in the report.'];
		yield 'signatur missing' => [self::json(array_diff_key(self::report(), ['signatur' => true])), '"%s" is missing in the report.'];
		yield 'signatur not a shelf mark' => [self::json(self::report(['signatur' => '7-3; DROP TABLE'])), 'The shelf mark in the report is not valid.'];
		yield 'signatur as list' => [self::json(self::report(['signatur' => ['7-3']])), '"%s" in the report must be text.'];
		yield 'base name with a path' => [self::json(self::report(['basisname' => '../../7-03_Ein Testlied'])), 'The base name in the report is not a valid file name.'];
		yield 'base name with a folder' => [self::json(self::report(['basisname' => 'Mappe_7/7-03_Ein Testlied'])), 'The base name in the report is not a valid file name.'];
		yield 'base name with a backslash' => [self::json(self::report(['basisname' => '..\\7-03_Ein Testlied'])), 'The base name in the report is not a valid file name.'];
		yield 'base name with a NUL byte' => [self::json(self::report(['basisname' => "7-03_Ein\0Testlied"])), 'The base name in the report is not a valid file name.'];
		yield 'base name dot dot' => [self::json(self::report(['basisname' => '..'])), 'The base name in the report is not a valid file name.'];
		yield 'base name of another copy' => [self::json(self::report(['basisname' => '7-04_Ein Testlied'])), 'The base name in the report belongs to another shelf mark.'];
		yield 'base name too long' => [self::json(self::report(['basisname' => '7-03_' . str_repeat('x', 300)])), '"%s" in the report is too long.'];
		yield 'uncertain not a list' => [self::json(self::report(['unsicher' => ['takt' => '1', 'was' => 'x']])), '"%s" in the report must be a list.'];
		yield 'uncertain entry not an object' => [self::json(self::report(['unsicher' => ['d oder c']])), '"%s" in the report must be an object.'];
		yield 'uncertain without text' => [self::json(self::report(['unsicher' => [['takt' => '1']]])), '"%s" is missing in the report.'];
		yield 'uncertain text as number' => [self::json(self::report(['unsicher' => [['takt' => '1', 'was' => 42]]])), '"%s" in the report must be text.'];
		yield 'material as text' => [self::json(self::report(['material' => 'Seite 2 fehlt'])), '"%s" in the report must be a list.'];
		yield 'rights as list' => [self::json(self::report(['rechte' => ['gemeinfrei']])), '"%s" in the report must be an object.'];
		yield 'fields as list' => [self::json(self::report(['wv' => ['felder' => ['Komponist']]])), '"%s" in the report must be an object.'];
		yield 'too many entries' => [self::json(self::report(['material' => array_fill(0, 501, 'x')])), '"%s" in the report has too many entries.'];
		yield 'text too long' => [self::json(self::report(['wv' => ['herkunft' => str_repeat('x', 5001)]])), '"%s" in the report is too long.'];
	}

	#[DataProvider('refused')]
	public function testRefusedReports(string $raw, string $message): void {
		try {
			TranscriptionReportParser::parse($raw);
			self::fail('refused: ' . $message);
		} catch (InvalidInputException $e) {
			self::assertSame($message, $e->getMessage());
		}
	}

	public function testTooLarge(): void {
		$this->expectExceptionObject(new InvalidInputException('The report is too large (at most %s MB).', [1]));
		TranscriptionReportParser::parse(self::json(self::report(['lernen' => [str_repeat('x', TranscriptionReportParser::MAX_BYTES)]])));
	}

	public function testControlCharactersAreRemoved(): void {
		$report = TranscriptionReportParser::parse(self::json(self::report(['material' => ["Seite\x07 2\x1B fehlt"]])))['report'];
		self::assertSame(['Seite 2 fehlt'], $report['material']);
	}

	public function testAByteOrderMarkIsAccepted(): void {
		self::assertSame('7-3', TranscriptionReportParser::parse("\xEF\xBB\xBF" . self::json(self::report()))['report']['signatur']);
	}
}
