<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service\Catalogue;

use OCA\OrchestraScoresManager\Service\Catalogue\CatalogueColumns;
use OCA\OrchestraScoresManager\Service\Catalogue\CatalogueCsv;
use OCA\OrchestraScoresManager\Service\Catalogue\CatalogueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalogue CSV parser and serializer.
 */
final class CatalogueCsvTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../../../fixtures/catalogue-sample.csv';

	/**
	 * Index the records of a parsed table by signature, each as column => value.
	 *
	 * @param array{header: list<string>, records: list<array{line: int, cells: list<string>}>} $table
	 * @return array<string, array<string, string>>
	 */
	private static function rowsBySignature(array $table): array {
		$rows = [];
		foreach ($table['records'] as $record) {
			$row = array_combine($table['header'], $record['cells']);
			$rows[$row['Signatur']] = $row;
		}
		return $rows;
	}

	public function testParseFixture(): void {
		$table = CatalogueCsv::parse((string)file_get_contents(self::FIXTURE));

		$this->assertSame(CatalogueColumns::HEADER, $table['header']);
		$this->assertCount(6, $table['records']);
		foreach ($table['records'] as $record) {
			$this->assertCount(31, $record['cells']);
		}

		$rows = self::rowsBySignature($table);
		$this->assertSame(['10-1', '1-10', '1-9', '2-3', '5-19', '7-1a'], array_map('strval', array_keys($rows)));
		$this->assertSame('Morgengruß; zweite Fassung', $rows['1-10']['Titel']);
		$this->assertSame("Zeile eins\nZeile zwei mit \"Zitat\"\n\nnach Leerzeile", $rows['1-10']['Anmerkung']);
		$this->assertSame('1-10_Morgengruss.pdf, 1-10_Morgengruss - Tenor I.pdf', $rows['1-10']['PDF-Dateien']);
		$this->assertSame('4', $rows['1-10']['Seiten']);
		$this->assertSame('Dörte Übermaß', $rows['1-9']['Komponist']);
		$this->assertSame('"Gänsefüßchen" im Titel', $rows['2-3']['Titel']);
		$this->assertSame(' führendes und folgendes Leerzeichen ', $rows['2-3']['Anmerkung']);
		$this->assertSame('nein — interne Erhaltungskopie', $rows['2-3']['Veröffentlichung']);
		$this->assertSame("Semikolon; Anführung \" und\r\nWindows-Zeilenumbruch", $rows['7-1a']['Anmerkung']);
		$this->assertSame('Satz: N. N. (1925–1995)', $rows['10-1']['Bearbeiter/Text']);
		$this->assertSame('2024-06-22', $rows['10-1']['Zuletzt aufgeführt']);
		$this->assertSame('', $rows['5-19']['Komponist']);
	}

	public function testParseReportsTheLineWhereARecordStarts(): void {
		$table = CatalogueCsv::parse((string)file_get_contents(self::FIXTURE));

		// the record of 1-10 spans four physical lines
		$this->assertSame([2, 3, 7, 8, 9, 10], array_column($table['records'], 'line'));
	}

	public function testParseWithoutBomAndWithCrlfLineEndings(): void {
		$table = CatalogueCsv::parse("Signatur;Titel\r\n1-1;Erstes\r\n1-2;\"Zwei\r\nZeilen\"\r\n");

		$this->assertSame(['Signatur', 'Titel'], $table['header']);
		$this->assertSame([['1-1', 'Erstes'], ['1-2', "Zwei\r\nZeilen"]], array_column($table['records'], 'cells'));
		$this->assertSame([2, 3], array_column($table['records'], 'line'));
	}

	public function testParseSkipsBlankLinesAndAcceptsMissingFinalLineBreak(): void {
		$table = CatalogueCsv::parse(CatalogueCsv::BOM . "Signatur;Titel\n\n1-1;Erstes\n\n1-2;Zweites");

		$this->assertSame([['1-1', 'Erstes'], ['1-2', 'Zweites']], array_column($table['records'], 'cells'));
		$this->assertSame([3, 5], array_column($table['records'], 'line'));
	}

	public function testParseKeepsEmptyCellsAndShortRows(): void {
		$table = CatalogueCsv::parse("A;B;C\n;;\nx\n");

		$this->assertSame([['', '', ''], ['x']], array_column($table['records'], 'cells'));
	}

	public function testParseTreatsQuotesInsideUnquotedValuesLiterally(): void {
		$table = CatalogueCsv::parse("A;B\n5\" Format;x\"y\n");

		$this->assertSame([['5" Format', 'x"y']], array_column($table['records'], 'cells'));
	}

	public static function invalidFileProvider(): array {
		return [
			'not UTF-8' => ["Signatur;Titel\n1-1;\xC4pfel\n", CatalogueException::NOT_UTF8, []],
			'empty' => ['', CatalogueException::EMPTY_FILE, []],
			'only BOM and blank lines' => [CatalogueCsv::BOM . "\n\n", CatalogueException::EMPTY_FILE, []],
			'unterminated quote' => ["Signatur;Titel\n1-1;Ok\n1-2;\"offen\n\n", CatalogueException::UNTERMINATED_QUOTE, ['line' => 3]],
			'empty column name' => ["Signatur;;Titel\n", CatalogueException::EMPTY_COLUMN_NAME, ['column' => 2]],
			'duplicate column' => ["Signatur;Titel;Titel\n", CatalogueException::DUPLICATE_COLUMN, ['name' => 'Titel']],
		];
	}

	#[DataProvider('invalidFileProvider')]
	public function testParseRejectsInvalidFiles(string $content, string $reason, array $context): void {
		try {
			CatalogueCsv::parse($content);
			$this->fail('Expected CatalogueException');
		} catch (CatalogueException $e) {
			$this->assertSame($reason, $e->reason);
			$this->assertSame($context, $e->context);
		}
	}

	public function testSerializeQuotesOnlyWhereNeeded(): void {
		$content = CatalogueCsv::serialize(
			['Signatur', 'Titel', 'Anmerkung'],
			[
				['5-19', 'Schlicht', ''],
				['10-1', 'Mit; Semikolon', 'Sagt "ja"'],
				['1-9', "Zwei\nZeilen", ' Leerzeichen '],
			],
		);

		$this->assertSame(
			CatalogueCsv::BOM
			. "Signatur;Titel;Anmerkung\r\n"
			. "5-19;Schlicht;\r\n"
			. "10-1;\"Mit; Semikolon\";\"Sagt \"\"ja\"\"\"\r\n"
			. "1-9;\"Zwei\nZeilen\";\" Leerzeichen \"\r\n",
			$content,
		);
	}

	public function testSerializeThenParseIsLossless(): void {
		$header = ['Signatur', 'Wert ; mit Semikolon', 'Ümläut'];
		$rows = [
			['1-9', '', '"'],
			['1-10', "a\r\nb\nc\rd", '""quoted""'],
			['10-1', ';;;', ' '],
			['2024-01-01', '=SUMME(A1)', 'ß'],
		];

		$table = CatalogueCsv::parse(CatalogueCsv::serialize($header, $rows));

		$this->assertSame($header, $table['header']);
		$this->assertSame($rows, array_column($table['records'], 'cells'));
	}

	public function testFixtureSurvivesParseSerializeParse(): void {
		$first = CatalogueCsv::parse((string)file_get_contents(self::FIXTURE));
		$second = CatalogueCsv::parse(CatalogueCsv::serialize($first['header'], array_column($first['records'], 'cells')));

		$this->assertSame($first['header'], $second['header']);
		$this->assertSame(array_column($first['records'], 'cells'), array_column($second['records'], 'cells'));
	}
}
