<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\ResponseDefinitions;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCA\OrchestraScoresManager\Utility\ResponseHelper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @psalm-import-type OrchestraScoresManagerScore from ResponseDefinitions
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class MusicXmlApiController extends OCSController {
	use ServiceExceptionBridgeTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MusicXmlService $musicXmlService,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Download the MusicXML file of a score
	 *
	 * @param int $id The ID of the score
	 * @return DataDownloadResponse<Http::STATUS_OK, 'application/vnd.recordare.musicxml+xml'|'application/vnd.recordare.musicxml', array{}> the MusicXML file
	 *
	 * 200: The MusicXML file
	 * @throws OCSBadRequestException if the score or file does not exist
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores/{id}/musicxml')]
	public function getMusicXml(int $id): DataDownloadResponse {
		$file = $this->callService(fn () => $this->musicXmlService->getForScore($id));
		return ResponseHelper::musicXmlDownload($file);
	}

	/**
	 * Upload a MusicXML file (.musicxml, .xml or .mxl) for a score, replacing an existing one
	 *
	 * The file must be sent as multipart form data in the field "file".
	 *
	 * @param int $id The ID of the score
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerScore, array{}> the updated score
	 *
	 * 200: The file was stored
	 * @throws OCSBadRequestException if the score does not exist or the file is invalid
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/scores/{id}/musicxml')]
	public function postMusicXml(int $id): DataResponse {
		$file = $this->request->getUploadedFile('file');
		if (!isset($file['tmp_name'], $file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
			throw new OCSBadRequestException($this->l->t('No file uploaded'));
		}

		$score = $this->callService(fn () => $this->musicXmlService->uploadForScore($id, (string)$file['name'], $file['tmp_name']));
		return new DataResponse($score->jsonSerialize());
	}

	/**
	 * Remove the MusicXML file of a score
	 *
	 * @param int $id The ID of the score
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerScore, array{}> the updated score
	 *
	 * 200: The file was removed
	 * @throws OCSBadRequestException if the score does not exist
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/scores/{id}/musicxml')]
	public function deleteMusicXml(int $id): DataResponse {
		$score = $this->callService(fn () => $this->musicXmlService->deleteForScore($id));
		return new DataResponse($score->jsonSerialize());
	}
}
