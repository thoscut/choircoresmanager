<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\AppInfo\Application;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\PublicScoreService;
use OCA\OrchestraScoresManager\Utility\ResponseHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pages and files for anonymous visitors.
 *
 * Only scores approved for publication whose rights status (public domain or licensed)
 * has been confirmed are exposed, see PublicScoreService.
 *
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PublicController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PublicScoreService $publicScoreService,
		private readonly IInitialState $initialState,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Public page listing all publishable scores with a MusicXML player.
	 *
	 * @return PublicTemplateResponse|NotFoundResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/public')]
	public function index(): PublicTemplateResponse|NotFoundResponse {
		if (!$this->publicScoreService->isEnabled()) {
			return new NotFoundResponse();
		}

		$this->initialState->provideInitialState('publicScores', $this->publicScoreService->getPublicScores());

		$response = new PublicTemplateResponse(Application::APP_ID, 'public');
		$response->setHeaderTitle($this->l->t('Public scores'));
		$response->setContentSecurityPolicy(ResponseHelper::playerContentSecurityPolicy());
		return $response;
	}

	/**
	 * Download the MusicXML file of a publishable score.
	 *
	 * @return DataDownloadResponse|NotFoundResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/public/scores/{id}/musicxml')]
	public function musicXml(int $id): DataDownloadResponse|NotFoundResponse {
		try {
			$file = $this->publicScoreService->getPublicMusicXml($id);
		} catch (\InvalidArgumentException|NotFoundException) {
			return new NotFoundResponse();
		}
		return ResponseHelper::musicXmlDownload($file);
	}
}
