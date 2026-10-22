<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Controller;

use OCA\ChoirScores\ResponseDefinitions;
use OCA\ChoirScores\Service\CatalogueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\IRequest;

/**
 * Import and export of the work catalogue (Werkverzeichnis) as CSV.
 *
 * @psalm-import-type ChoirScoresCatalogueImportResult from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueExport from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueValue from ResponseDefinitions
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CatalogueApiController extends OCSController {
	use ServiceExceptionBridgeTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CatalogueService $catalogueService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Import the work catalogue from a CSV file
	 *
	 * The file is semicolon-separated UTF-8 with the catalogue header. Rows are matched to scores
	 * by the column "Signatur"; rows with a missing or duplicate signature or invalid values are
	 * reported as errors and not applied. Scores that are not in the file stay untouched.
	 * With dryRun (the default) nothing is saved and the result shows what would change.
	 *
	 * @param string $csv The content of the CSV file
	 * @param bool $dryRun Only report the changes without saving them
	 * @return DataResponse<Http::STATUS_OK, ChoirScoresCatalogueImportResult, array{}> Counts and changes per row
	 * @throws OCSBadRequestException If the file cannot be read as a catalogue
	 * @throws OCSForbiddenException If the user may not edit scores
	 * @throws Exception If saving fails
	 *
	 * 200: The file was analysed, and applied unless dryRun was set
	 * 400: The file cannot be read as a catalogue
	 * 403: The user may not edit scores
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/catalogue/import')]
	public function importCatalogue(string $csv, bool $dryRun = true): DataResponse {
		$result = $this->callService(fn () => $this->catalogueService->importCatalogue($csv, $dryRun));
		return new DataResponse($result);
	}

	/**
	 * Export all scores as a work catalogue CSV file
	 *
	 * @return DataResponse<Http::STATUS_OK, ChoirScoresCatalogueExport, array{}> File name and content (UTF-8 with BOM)
	 * @throws OCSForbiddenException If the user may not read scores
	 *
	 * 200: The catalogue as CSV
	 * 403: The user may not read scores
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/catalogue/export')]
	public function exportCatalogue(): DataResponse {
		$export = $this->callService(fn () => $this->catalogueService->exportCatalogue());
		return new DataResponse($export);
	}

	/**
	 * Get the catalogue values of a score that are not stored in a field
	 *
	 * @param int $id The ID of the score
	 * @return DataResponse<Http::STATUS_OK, list<ChoirScoresCatalogueValue>, array{}> Column names and values
	 * @throws OCSNotFoundException If the score does not exist
	 * @throws OCSForbiddenException If the user may not read scores
	 *
	 * 200: The catalogue values of the score
	 * 404: The score does not exist
	 * 403: The user may not read scores
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores/{id}/catalogue')]
	public function getScoreCatalogueValues(int $id): DataResponse {
		$values = $this->callService(fn () => $this->catalogueService->getCatalogueValues($id));
		return new DataResponse($values);
	}
}
