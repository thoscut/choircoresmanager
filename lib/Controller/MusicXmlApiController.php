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
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * MusicXML files of scores. The files live in Nextcloud Files; a score only links to one.
 *
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
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Download the MusicXML file of a score
	 *
	 * The content of the linked file is returned. The file of an internal preservation copy
	 * is only available to editors.
	 *
	 * @param int $id The ID of the score
	 * @return DataDownloadResponse<Http::STATUS_OK, 'application/vnd.recordare.musicxml+xml'|'application/vnd.recordare.musicxml', array{}> the MusicXML file
	 *
	 * 200: The MusicXML file
	 * @throws OCSBadRequestException if the score does not exist
	 * @throws OCSNotFoundException if no file is linked or the linked file is missing, not accessible or not a valid MusicXML file
	 * @throws OCSForbiddenException if the user does not have permission, e.g. for an internal preservation copy
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores/{id}/musicxml')]
	public function getMusicXml(int $id): DataDownloadResponse {
		$file = $this->callService(fn () => $this->musicXmlService->getForScore($id));
		return ResponseHelper::musicXmlDownload($file);
	}

	/**
	 * Link a MusicXML file (.musicxml, .xml or .mxl) from the user's files to a score, replacing an existing link
	 *
	 * The file stays in Nextcloud Files. The current user must be able to read it; later
	 * reads use the access of the user who linked the file.
	 *
	 * @param int $id The ID of the score
	 * @param int $fileId The Nextcloud file id of the MusicXML file
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerScore, array{}> the updated score
	 *
	 * 200: The file was linked
	 * @throws OCSBadRequestException if the score does not exist, the file cannot be read or is not a valid MusicXML file
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/scores/{id}/musicxml')]
	public function postMusicXml(int $id, int $fileId): DataResponse {
		$score = $this->callService(fn () => $this->musicXmlService->linkForScore($id, $fileId));
		return new DataResponse($score->jsonSerialize());
	}

	/**
	 * Remove the link to the MusicXML file of a score
	 *
	 * The file itself stays in Nextcloud Files.
	 *
	 * @param int $id The ID of the score
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerScore, array{}> the updated score
	 *
	 * 200: The link was removed
	 * @throws OCSBadRequestException if the score does not exist
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/scores/{id}/musicxml')]
	public function deleteMusicXml(int $id): DataResponse {
		$score = $this->callService(fn () => $this->musicXmlService->unlinkForScore($id));
		return new DataResponse($score->jsonSerialize());
	}
}
