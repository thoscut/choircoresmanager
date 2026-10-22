<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\integration\Service;

use OCA\ChoirScores\Db\ScoreBookScoreLinkMapper;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Db\ScoreTagLinkMapper;
use OCA\ChoirScores\Db\TagMapper;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\Service\AuthorizationService;
use OCA\ChoirScores\Service\Catalogue\CatalogueCsv;
use OCA\ChoirScores\Service\CatalogueService;
use OCA\ChoirScores\Service\ConfigService;
use OCA\ChoirScores\Service\RepertoireClassifier;
use OCA\ChoirScores\Tests\integration\Db\MapperTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Integration test: importing the catalogue fixture into the database and exporting it again
 * reproduces the table.
 *
 * Authorization and app config are replaced by test doubles; scores go through the real mapper.
 */
final class CatalogueRoundTripTest extends MapperTestCase {
	private const FIXTURE = __DIR__ . '/../../fixtures/catalogue-sample.csv';

	private ScoreMapper $scoreMapper;
	private CatalogueService $service;
	/** @var list<string> */
	private array $catalogueColumns = [];

	protected function setUp(): void {
		parent::setUp();

		$tagMapper = new TagMapper($this->db);
		$this->scoreMapper = new ScoreMapper(
			$this->db,
			new ScoreTagLinkMapper($this->db),
			$tagMapper,
			new ScoreBookScoreLinkMapper($this->db),
			\OC::$server->get(RepertoireClassifier::class),
		);

		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCatalogueColumns')->willReturnCallback(fn (): array => $this->catalogueColumns);
		$configService->method('setCatalogueColumns')->willReturnCallback(function (array $columns): void {
			$this->catalogueColumns = $columns;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new CatalogueService(
			$this->scoreMapper,
			$this->createMock(AuthorizationService::class),
			$this->createMock(ScorePolicy::class),
			$configService,
			$this->db,
			\OC::$server->get(ITimeFactory::class),
			$l10n,
		);
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
			$row = array_combine($table['header'], $record['cells']);
			$rows[$row['Signatur']] = $row;
		}
		return ['header' => $table['header'], 'rows' => $rows];
	}

	public function testImportThenExportReproducesTheTable(): void {
		$fixture = (string)file_get_contents(self::FIXTURE);

		$result = $this->service->importCatalogue($fixture, false);
		$this->assertSame(['new' => 6, 'changed' => 0, 'unchanged' => 0, 'errors' => 0], $result['counts']);
		$this->assertCount(6, $this->scoreMapper->findAll());

		$original = self::table($fixture);
		$exported = self::table($this->service->exportCatalogue()['content']);

		$this->assertSame($original['header'], $exported['header']);
		$this->assertEqualsCanonicalizing(array_keys($original['rows']), array_keys($exported['rows']));
		foreach ($original['rows'] as $signature => $row) {
			$this->assertSame($row, $exported['rows'][$signature], "row $signature");
		}
	}

	public function testSecondImportFindsNoChanges(): void {
		$fixture = (string)file_get_contents(self::FIXTURE);
		$this->service->importCatalogue($fixture, false);

		$result = $this->service->importCatalogue($fixture, true);

		$this->assertSame(['new' => 0, 'changed' => 0, 'unchanged' => 6, 'errors' => 0], $result['counts']);
	}

	public function testImportedLastPerformanceIsUsedForTheRepertoireClass(): void {
		$this->service->importCatalogue((string)file_get_contents(self::FIXTURE), false);

		$bySignature = [];
		foreach ($this->scoreMapper->findAll() as $score) {
			$bySignature[(string)$score->getSignature()] = $score;
		}

		$this->assertSame('2024-06-22', $bySignature['10-1']->getLastPerformed());
		$this->assertSame('1999-05-01', $bySignature['1-9']->getLastPerformed());
		$this->assertSame('earlier', $bySignature['1-9']->getRepertoireClass());
		$this->assertNull($bySignature['5-19']->getLastPerformed());
		$this->assertSame('not_documented', $bySignature['5-19']->getRepertoireClass());
		$this->assertSame('active', $bySignature['5-19']->getInventoryStatus());
	}
}
