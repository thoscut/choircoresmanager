<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** @psalm-suppress UnusedClass - Controller discovered by NextCloud framework */
#[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
class AdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get current list of groups allowed to edit scores
	 *
	 * @return DataResponse<Http::STATUS_OK, array{editGroups: list<string>}, array{}> Current settings
	 *
	 * 200: Successful response with the list of groups
	 */
	#[ApiRoute(verb: 'GET', url: '/admin/editGroups')]
	public function getEditGroups(): DataResponse {
		return new DataResponse([
			'editGroups' => $this->configService->getAllowedEditGroups(),
		]);
	}

	/**
	 * Update which groups are allowed to edit scores
	 *
	 * @param list<string> $editGroups List of group IDs allowed to edit
	 * @return DataResponse<Http::STATUS_OK, array{editGroups: list<string>}, array{}> Updated settings
	 * @throws OCSBadRequestException If input validation fails
	 *
	 * 200: Successful update of settings
	 * 400: Invalid input data
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/admin/editGroups')]
	public function postEditGroups(array $editGroups = []): DataResponse {
		// Update settings
		$this->configService->setAllowedEditGroups($editGroups);

		return new DataResponse(['editGroups' => $this->configService->getAllowedEditGroups()]);
	}

	/**
	 * Get the thresholds used to derive the repertoire class of a score
	 *
	 * @return DataResponse<Http::STATUS_OK, array{currentYears: int, dormantYears: int}, array{}> Current thresholds
	 *
	 * 200: Successful response with the thresholds
	 */
	#[ApiRoute(verb: 'GET', url: '/admin/repertoireThresholds')]
	public function getRepertoireThresholds(): DataResponse {
		return new DataResponse([
			'currentYears' => $this->configService->getRepertoireCurrentYears(),
			'dormantYears' => $this->configService->getRepertoireDormantYears(),
		]);
	}

	/**
	 * Update the thresholds used to derive the repertoire class of a score
	 *
	 * @param int $currentYears Scores performed within this many years count as current
	 * @param int $dormantYears Scores performed within this many years count as dormant
	 * @return DataResponse<Http::STATUS_OK, array{currentYears: int, dormantYears: int}, array{}> Updated thresholds
	 * @throws OCSBadRequestException If the thresholds are invalid
	 *
	 * 200: Successful update of the thresholds
	 * 400: Invalid thresholds
	 */
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/admin/repertoireThresholds')]
	public function postRepertoireThresholds(int $currentYears, int $dormantYears): DataResponse {
		try {
			$this->configService->setRepertoireThresholds($currentYears, $dormantYears);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return $this->getRepertoireThresholds();
	}
}
