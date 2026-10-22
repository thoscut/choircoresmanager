<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Controller;

use OCA\ChoirScores\Service\ConfigService;
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
	use ServiceExceptionBridgeTrait;

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
	 * A score is current if its last performance lies at most currentYears calendar years back,
	 * dormant if at most dormantYears, and earlier otherwise.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{currentYears: int, dormantYears: int}, array{}> Current thresholds
	 *
	 * 200: Successful response with the thresholds
	 */
	#[ApiRoute(verb: 'GET', url: '/admin/repertoireThresholds')]
	public function getRepertoireThresholds(): DataResponse {
		return new DataResponse($this->configService->getRepertoireThresholds());
	}

	/**
	 * Update the thresholds used to derive the repertoire class of a score
	 *
	 * @param int $currentYears Years within which a performance counts as current (0 <= currentYears < dormantYears)
	 * @param int $dormantYears Years within which a performance counts as dormant (at most 100)
	 * @return DataResponse<Http::STATUS_OK, array{currentYears: int, dormantYears: int}, array{}> Updated thresholds
	 * @throws OCSBadRequestException If the thresholds are invalid
	 *
	 * 200: Successful update of the thresholds
	 * 400: Invalid thresholds
	 */
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/admin/repertoireThresholds')]
	public function postRepertoireThresholds(int $currentYears, int $dormantYears): DataResponse {
		$this->callService(fn () => $this->configService->setRepertoireThresholds($currentYears, $dormantYears));
		return new DataResponse($this->configService->getRepertoireThresholds());
	}

	/**
	 * Get whether the public page for freely available scores is enabled
	 *
	 * @return DataResponse<Http::STATUS_OK, array{publicPageEnabled: bool}, array{}> Current setting
	 *
	 * 200: Successful response with the setting
	 */
	#[ApiRoute(verb: 'GET', url: '/admin/publicPage')]
	public function getPublicPage(): DataResponse {
		return new DataResponse([
			'publicPageEnabled' => $this->configService->isPublicPageEnabled(),
		]);
	}

	/**
	 * Enable or disable the public page for freely available scores
	 *
	 * @param bool $publicPageEnabled Whether the public page is enabled
	 * @return DataResponse<Http::STATUS_OK, array{publicPageEnabled: bool}, array{}> Updated setting
	 *
	 * 200: Successful update of the setting
	 */
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/admin/publicPage')]
	public function postPublicPage(bool $publicPageEnabled): DataResponse {
		$this->configService->setPublicPageEnabled($publicPageEnabled);
		return new DataResponse(['publicPageEnabled' => $this->configService->isPublicPageEnabled()]);
	}
}
