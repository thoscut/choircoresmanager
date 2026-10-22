<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\AppInfo\Application;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\ShareService;
use OCA\OrchestraScoresManager\Utility\ResponseHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Pages and files of public share links. Only freely available scores are exposed.
 *
 * Unknown tokens are throttled to make guessing them impractical.
 *
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ShareController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShareService $shareService,
		private readonly IInitialState $initialState,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Page showing a shared score or folder collection.
	 *
	 * @return PublicTemplateResponse|NotFoundResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: 'osm_share')]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}')]
	public function show(string $token): PublicTemplateResponse|NotFoundResponse {
		try {
			$shared = $this->shareService->getLinkShare($token);
		} catch (\InvalidArgumentException) {
			$response = new NotFoundResponse();
			$response->throttle(['token' => $token]);
			return $response;
		}

		$this->initialState->provideInitialState('sharedTarget', $shared);
		$this->initialState->provideInitialState('shareToken', $token);

		$response = new PublicTemplateResponse(Application::APP_ID, 'share');
		$response->setHeaderTitle($shared['title']);
		$response->setContentSecurityPolicy(ResponseHelper::playerContentSecurityPolicy());
		return $response;
	}

	/**
	 * Load a file of a score included in a share for display.
	 *
	 * @param string $kind 'musicxml' or 'pdf'
	 * @return DataDownloadResponse|NotFoundResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: 'osm_share')]
	#[FrontpageRoute(verb: 'GET', url: '/s/{token}/scores/{id}/{kind}', requirements: ['kind' => 'musicxml|pdf'])]
	public function file(string $token, int $id, string $kind): DataDownloadResponse|NotFoundResponse {
		try {
			$file = $this->shareService->getLinkShareFile($token, $id, $kind);
		} catch (\InvalidArgumentException) {
			$response = new NotFoundResponse();
			$response->throttle(['token' => $token]);
			return $response;
		} catch (NotFoundException) {
			// The share is valid, only the linked file is missing or invalid
			return new NotFoundResponse();
		}
		return ResponseHelper::fileDownload($file);
	}
}
