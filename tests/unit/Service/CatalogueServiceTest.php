<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCA\OrchestraScoresManager\Service\AuthorizationService;
use OCA\OrchestraScoresManager\Service\Catalogue\CatalogueColumns;
use OCA\OrchestraScoresManager\Service\Catalogue\CatalogueCsv;
use OCA\OrchestraScoresManager\Service\CatalogueService;
use OCA\OrchestraScoresManager\Service\ConfigService;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CatalogueService, with scores kept in memory instead of a database.
 */
final class CatalogueServiceTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../../fixtures/catalogue-sample.csv';

	/** @var array<int, Score> */
	private array $scores = [];
	private int $nextId = 1;
	/** @var list<string> */
	private array $catalogueColumns = [];
	private ScoreMapper $scoreMapper;
	private AuthorizationService $authorizationService;
	private CatalogueService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->scoreMapper->method('findAll')->willReturnCallback(fn (): array => array_values($this->scores));
		$this->scoreMapper->method('find')->willReturnCallback(
			fn (int $id): Score => $this->scores[$id] ?? throw new DoesNotExistException('not found'),
		);
		$this->scoreMapper->method('insert')->willReturnCallback(function (Score $score): Score {
			$score->setId($this->nextId++);
			$score->resetUpdatedFields();
			$this->scores[$score->getId()] = $score;
			return $score;
		});
		$this->scoreMapper->method('update')->willReturnCallback(function (Score $score): Score {
			$score->resetUpdatedFields();
			return $score;
		});

		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCatalogueColumns')->willReturnCallback(fn (): array => $this->catalogueColumns);
		$configService->method('setCatalogueColumns')->willReturnCallback(function (array $columns): void {
			$this->catalogueColumns = $columns;
		});

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(new \DateTimeImmutable('2026-10-22 10:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->authorizationService = $this->createMock(AuthorizationService::class);

		$this->service = new CatalogueService(
			$this->scoreMapper,
			$this->authorizationService,
			$this->createMock(ScorePolicy::class),
			$configService,
			$this->createMock(IDBConnection::class),
			$timeFactory,
			$l10n,
		);
	}

	private static function fixture(): string {
		return (string)file_get_contents(self::FIXTURE);
	}

	/**
	 * Build CSV content from rows given as column => value, using the given header.
	 *
	 * @param list<string> $header
	 * @param list<array<string, string>> $rows
	 */
	private static function csv(array $header, array $rows): string {
		return CatalogueCsv::serialize($header, array_map(
			fn (array $row): array => array_map(fn (string $column): string => $row[$column] ?? '', $header),
			$rows,
		));
	}

	/**
	 * Parse CSV content into rows indexed by signature.
	 *
	 * @return array{header: list<string>, rows: array<string, array<string, string>>}
	 */
	private static function table(string $content): array {
		$table = CatalogueCsv::parse($content);
		$rows = [];
		foreach ($table['records'] as $record) {
			$row = array_combine($table['header'], array_pad($record['cells'], count($table['header']), ''));
			$rows[$row['Signatur']] = $row;
		}
		return ['header' => $table['header'], 'rows' => $rows];
	}

	private function addScore(string $title, ?string $signature, array $extra = []): Score {
		$score = new Score();
		$score->setTitle($title);
		$score->setSignature($signature);
		$score->setCatalogueExtraValues($extra);
		return $this->scoreMapper->insert($score);
	}

	public function testDryRunReportsNewRowsAndSavesNothing(): void {
		$this->scoreMapper->expects($this->never())->method('insert');

		$result = $this->service->importCatalogue(self::fixture(), true);

		$this->assertTrue($result['dryRun']);
		$this->assertSame(['new' => 6, 'changed' => 0, 'unchanged' => 0, 'errors' => 0], $result['counts']);
		$this->assertSame(['Signatur', 'Titel', 'Komponist', 'Zuletzt aufgeführt'], $result['mappedColumns']);
		$this->assertCount(27, $result['extraColumns']);
		$this->assertSame([], $this->scores);
		$this->assertSame([], $this->catalogueColumns);

		$row = $result['rows'][1];
		$this->assertSame(3, $row['line']);
		$this->assertSame('1-10', $row['signature']);
		$this->assertSame('Morgengruß; zweite Fassung', $row['title']);
		$this->assertSame('new', $row['status']);
		$this->assertNull($row['scoreId']);
		$this->assertContains(['column' => 'Verlag / Bestellnummer', 'old' => '', 'new' => 'Beispielverlag / BV 123; Nachdruck'], $row['changes']);
		$this->assertNotContains('Lizenz', array_column($result['rows'][0]['changes'], 'column'), 'empty cells are no change');
	}

	public function testImportThenExportReproducesTheTable(): void {
		$result = $this->service->importCatalogue(self::fixture(), false);
		$this->assertSame(6, $result['counts']['new']);

		$export = $this->service->exportCatalogue();

		$original = self::table(self::fixture());
		$exported = self::table($export['content']);
		$this->assertStringStartsWith(CatalogueCsv::BOM, $export['content']);
		$this->assertSame($original['header'], $exported['header']);
		$this->assertEqualsCanonicalizing(array_keys($original['rows']), array_keys($exported['rows']));
		foreach ($original['rows'] as $signature => $row) {
			$this->assertSame($row, $exported['rows'][$signature], "row $signature");
		}
	}

	public function testImportStoresMappedColumnsInFieldsAndTheRestVerbatim(): void {
		$this->service->importCatalogue(self::fixture(), false);

		$score = array_values(array_filter($this->scores, fn (Score $s) => $s->getSignature() === '10-1'))[0];
		$this->assertSame('Probelied am Weiher', $score->getTitle());
		$this->assertSame('Anton Beispiel (1801–1870)', $score->getComposer());
		$this->assertSame('2024-06-22', $score->getLastPerformedImported()?->format('Y-m-d'));
		$this->assertNull($score->getArranger(), 'Bearbeiter/Text is not split');
		$extra = $score->getCatalogueExtraValues();
		$this->assertSame('Satz: N. N. (1925–1995)', $extra['Bearbeiter/Text']);
		$this->assertSame('aktuell', $extra['Repertoire']);
		$this->assertArrayNotHasKey('Titel', $extra);
		$this->assertArrayNotHasKey('Lizenz', $extra, 'empty cells are not stored');
		$this->assertSame(CatalogueColumns::HEADER, $this->catalogueColumns);
	}

	public function testSecondImportOfTheSameFileChangesNothing(): void {
		$this->service->importCatalogue(self::fixture(), false);
		$this->scoreMapper->expects($this->never())->method('update');

		$result = $this->service->importCatalogue(self::fixture(), false);

		$this->assertSame(['new' => 0, 'changed' => 0, 'unchanged' => 6, 'errors' => 0], $result['counts']);
		$this->assertSame([], $result['rows']);
	}

	public function testChangedRowsReportFieldDiffsAndAreMatchedBySignature(): void {
		$existing = $this->addScore('Alter Titel', '5-19', ['Notenliste' => 'Herbst', 'Anmerkung' => 'alt']);
		$header = ['Signatur', 'Titel', 'Anmerkung', 'Notenliste', 'Zuletzt aufgeführt'];
		$csv = self::csv($header, [['Signatur' => '5-19', 'Titel' => 'Neuer Titel', 'Notenliste' => 'Herbst', 'Zuletzt aufgeführt' => '2020-03-01']]);

		$preview = $this->service->importCatalogue($csv, true);

		$this->assertSame(['new' => 0, 'changed' => 1, 'unchanged' => 0, 'errors' => 0], $preview['counts']);
		$this->assertSame($existing->getId(), $preview['rows'][0]['scoreId']);
		$this->assertSame([
			['column' => 'Titel', 'old' => 'Alter Titel', 'new' => 'Neuer Titel'],
			['column' => 'Anmerkung', 'old' => 'alt', 'new' => ''],
			['column' => 'Zuletzt aufgeführt', 'old' => '', 'new' => '2020-03-01'],
		], $preview['rows'][0]['changes']);
		$this->assertSame('Alter Titel', $existing->getTitle(), 'dry run saves nothing');

		$this->service->importCatalogue($csv, false);

		$this->assertCount(1, $this->scores);
		$this->assertSame('Neuer Titel', $existing->getTitle());
		$this->assertSame('2020-03-01', $existing->getLastPerformedImported()?->format('Y-m-d'));
		$this->assertSame(['Notenliste' => 'Herbst'], $existing->getCatalogueExtraValues());
	}

	public function testRowErrorsAreReportedAndNotApplied(): void {
		$this->addScore('Erste', '3-1');
		$this->addScore('Zweite', '3-1');
		$header = ['Signatur', 'Titel', 'Zuletzt aufgeführt'];
		$csv = self::csv($header, [
			['Signatur' => '4-1', 'Titel' => 'Doppelt A'],
			['Signatur' => '', 'Titel' => 'Ohne Signatur'],
			['Signatur' => '4-1', 'Titel' => 'Doppelt B'],
			['Signatur' => ' 4-2', 'Titel' => 'Mit Leerzeichen'],
			['Signatur' => '3-1', 'Titel' => 'Mehrdeutig'],
			['Signatur' => '4-3', 'Titel' => 'Falsches Datum', 'Zuletzt aufgeführt' => '22.06.2024'],
			['Signatur' => '4-4', 'Titel' => ''],
			['Signatur' => '4-5', 'Titel' => 'Gültig'],
		]) . "4-6;Zu viele;;Werte\r\n";

		$result = $this->service->importCatalogue($csv, false);

		$this->assertSame(['new' => 1, 'changed' => 0, 'unchanged' => 0, 'errors' => 8], $result['counts']);
		$errors = array_column($result['rows'], 'errors', 'line');
		$this->assertSame(['The signature occurs more than once in the file.'], $errors[2]);
		$this->assertSame(['The signature is missing.'], $errors[3]);
		$this->assertSame(['The signature occurs more than once in the file.'], $errors[4]);
		$this->assertSame(['The signature has leading or trailing spaces.'], $errors[5]);
		$this->assertSame(['The signature is used by more than one existing score.'], $errors[6]);
		$this->assertSame(['Zuletzt aufgeführt: The value is not a date in the form YYYY-MM-DD.'], $errors[7]);
		$this->assertSame(['Titel: The value must not be empty.'], $errors[8]);
		$this->assertSame(['The row has 4 values, but the header has only 3 columns.'], $errors[10]);
		$this->assertSame(['Erste', 'Zweite', 'Gültig'], array_values(array_map(fn (Score $s) => $s->getTitle(), $this->scores)));
	}

	public function testNewScoresNeedATitleColumn(): void {
		$result = $this->service->importCatalogue(self::csv(['Signatur', 'Mappe'], [['Signatur' => '1-1', 'Mappe' => '1']]), true);

		$this->assertSame(['New scores need the column "Titel".'], $result['rows'][0]['errors']);
	}

	public function testScoresNotInTheFileStayUntouched(): void {
		$other = $this->addScore('Nicht im Verzeichnis', '99-9', ['Mappe' => '99']);
		$withoutSignature = $this->addScore('Ohne Signatur', null);

		$this->service->importCatalogue(self::fixture(), false);

		$this->assertCount(8, $this->scores);
		$this->assertSame('Nicht im Verzeichnis', $other->getTitle());
		$this->assertSame(['Mappe' => '99'], $other->getCatalogueExtraValues());
		$this->assertSame('Ohne Signatur', $withoutSignature->getTitle());

		$exported = self::table($this->service->exportCatalogue()['content']);
		$this->assertArrayHasKey('99-9', $exported['rows']);
		$this->assertArrayHasKey('', $exported['rows']);
	}

	public function testExportSortsBySignatureInNaturalOrder(): void {
		$this->service->importCatalogue(self::fixture(), false);
		$this->addScore('Ohne Signatur', null);

		$table = CatalogueCsv::parse($this->service->exportCatalogue()['content']);

		$this->assertSame(['1-9', '1-10', '2-3', '5-19', '7-1a', '10-1', ''], array_map(fn (array $r) => $r['cells'][2], $table['records']));
	}

	public function testExportKeepsFurtherColumnsInFirstSeenOrder(): void {
		$this->addScore('Mit Zusatz', '1-1', ['Zusatz B' => 'b', 'Mappe' => '1', 'Zusatz A' => 'a']);
		$this->catalogueColumns = ['Signatur', 'Leer', 'Zusatz A'];

		$table = CatalogueCsv::parse($this->service->exportCatalogue()['content']);

		$this->assertSame(array_merge(CatalogueColumns::HEADER, ['Leer', 'Zusatz A', 'Zusatz B']), $table['header']);
		$row = array_combine($table['header'], $table['records'][0]['cells']);
		$this->assertSame(['1', '', 'a', 'b'], [$row['Mappe'], $row['Leer'], $row['Zusatz A'], $row['Zusatz B']]);
	}

	public function testUnknownColumnWithoutValuesSurvivesTheRoundTrip(): void {
		$header = array_merge(CatalogueColumns::HEADER, ['Neue Spalte']);
		$this->service->importCatalogue(self::csv($header, [['Signatur' => '1-1', 'Titel' => 'Probe']]), false);

		$table = CatalogueCsv::parse($this->service->exportCatalogue()['content']);

		$this->assertSame($header, $table['header']);
	}

	public function testValuesOfLaterMappedColumnsAreExportedAndTakenOverByTheNextImport(): void {
		// as if "Komponist" had been kept verbatim before it was mapped to a field
		$score = $this->addScore('Probe', '1-1', ['Komponist' => 'Anton Beispiel']);

		$table = self::table($this->service->exportCatalogue()['content']);
		$this->assertSame('Anton Beispiel', $table['rows']['1-1']['Komponist']);

		$this->service->importCatalogue(self::csv(['Signatur', 'Komponist'], [['Signatur' => '1-1', 'Komponist' => 'Anton Beispiel']]), false);
		$this->assertSame('Anton Beispiel', $score->getComposer());
		$this->assertSame([], $score->getCatalogueExtraValues());
	}

	public function testFileErrorsAreReportedAsInvalidArgument(): void {
		try {
			$this->service->importCatalogue("Titel;Komponist\nProbe;X\n", true);
			$this->fail('Expected InvalidArgumentException');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('The file has no column "Signatur".', $e->getMessage());
		}

		$this->expectExceptionMessage('The file is not valid UTF-8. Save it as "CSV UTF-8" and try again.');
		$this->service->importCatalogue("Signatur;Titel\n1-1;\xE4\n", true);
	}

	public function testImportRequiresEditPermission(): void {
		$this->authorizationService->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(PermissionDeniedException::class);
		$this->service->importCatalogue(self::fixture(), true);
	}

	public function testExportFileName(): void {
		$this->assertSame('Catalogue-2026-10-22.csv', $this->service->exportCatalogue()['fileName']);
	}

	public function testGetCatalogueValuesInCatalogueOrder(): void {
		$score = $this->addScore('Probe', '1-1', ['Zusatz' => 'z', 'Notenliste' => 'Advent', 'Mappe' => '1']);

		$this->assertSame([
			['column' => 'Mappe', 'value' => '1'],
			['column' => 'Notenliste', 'value' => 'Advent'],
			['column' => 'Zusatz', 'value' => 'z'],
		], $this->service->getCatalogueValues($score->getId()));
	}

	public function testGetCatalogueValuesOfUnknownScore(): void {
		$this->expectException(NotFoundException::class);

		$this->service->getCatalogueValues(4711);
	}
}
