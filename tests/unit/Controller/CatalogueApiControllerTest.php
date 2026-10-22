<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Controller;

use OCA\OrchestraScoresManager\Controller\CatalogueApiController;
use OCA\OrchestraScoresManager\Service\CatalogueService;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CatalogueApiController.
 */
final class CatalogueApiControllerTest extends TestCase {
	private CatalogueService $catalogueService;
	private CatalogueApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->catalogueService = $this->createMock(CatalogueService::class);
		$this->controller = new CatalogueApiController(
			'orchestrascoresmanager',
			$this->createMock(IRequest::class),
			$this->catalogueService,
		);
	}

	#[TestWith([true])]
	#[TestWith([false])]
	public function testImportPassesContentAndDryRun(bool $dryRun): void {
		$result = [
			'dryRun' => $dryRun,
			'counts' => ['new' => 1, 'changed' => 0, 'unchanged' => 0, 'errors' => 0],
			'mappedColumns' => ['Signatur'],
			'extraColumns' => [],
			'rows' => [],
		];
		$this->catalogueService->expects($this->once())
			->method('importCatalogue')
			->with("Signatur\n1-1\n", $dryRun)
			->willReturn($result);

		$response = $this->controller->importCatalogue("Signatur\n1-1\n", $dryRun);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testImportIsADryRunByDefault(): void {
		$this->catalogueService->expects($this->once())
			->method('importCatalogue')
			->with('csv', true)
			->willReturn(['dryRun' => true, 'counts' => ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'errors' => 0], 'mappedColumns' => [], 'extraColumns' => [], 'rows' => []]);

		$this->controller->importCatalogue('csv');
	}

	public function testImportTurnsInvalidFilesIntoBadRequest(): void {
		$this->catalogueService->method('importCatalogue')
			->willThrowException(new \InvalidArgumentException('The file is empty.'));

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('The file is empty.');
		$this->controller->importCatalogue('');
	}

	public function testImportTurnsMissingPermissionIntoForbidden(): void {
		$this->catalogueService->method('importCatalogue')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(OCSForbiddenException::class);
		$this->controller->importCatalogue('csv', false);
	}

	public function testExportReturnsFileNameAndContent(): void {
		$export = ['fileName' => 'Catalogue-2026-10-22.csv', 'content' => "\u{FEFF}Mappe\r\n"];
		$this->catalogueService->method('exportCatalogue')->willReturn($export);

		$response = $this->controller->exportCatalogue();

		$this->assertSame($export, $response->getData());
	}

	public function testGetScoreCatalogueValues(): void {
		$values = [['column' => 'Mappe', 'value' => '5']];
		$this->catalogueService->expects($this->once())
			->method('getCatalogueValues')
			->with(7)
			->willReturn($values);

		$this->assertSame($values, $this->controller->getScoreCatalogueValues(7)->getData());
	}
}
