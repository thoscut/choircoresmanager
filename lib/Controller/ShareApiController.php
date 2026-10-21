<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\ResponseDefinitions;
use OCA\OrchestraScoresManager\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type OrchestraScoresManagerShare from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerShareGroup from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerSharedTarget from ResponseDefinitions
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ShareApiController extends OCSController {
	use ServiceExceptionBridgeTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShareService $shareService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get all shares of a score or folder collection
	 *
	 * @param 'score'|'folder_collection' $targetType The type of the shared item
	 * @param int $targetId The ID of the shared item
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerShare>, array{}> the shares
	 *
	 * 200: The shares of the item
	 * @throws OCSBadRequestException if the target type is invalid
	 * @throws OCSForbiddenException if the user may not manage shares
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/shares')]
	public function getShares(string $targetType, int $targetId): DataResponse {
		return new DataResponse($this->callService(fn () => $this->shareService->getSharesForTarget($targetType, $targetId)));
	}

	/**
	 * Get all shares
	 *
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerShare>, array{}> all shares, newest first
	 *
	 * 200: All shares
	 * @throws OCSForbiddenException if the user may not manage shares
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/shares/all')]
	public function getAllShares(): DataResponse {
		return new DataResponse($this->callService(fn () => $this->shareService->getAllShares()));
	}

	/**
	 * Share a score or folder collection with a group or by a public link
	 *
	 * Public links only ever show scores whose rights status is "free".
	 *
	 * @param 'link'|'group' $shareType The type of share
	 * @param 'score'|'folder_collection' $targetType The type of the shared item
	 * @param int $targetId The ID of the shared item
	 * @param string|null $groupId The group to share with, required for group shares
	 * @param string|null $label Optional title shown instead of the item's title
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerShare, array{}> the new share
	 *
	 * 200: The share was created
	 * @throws OCSBadRequestException if the input is invalid
	 * @throws OCSForbiddenException if the user may not manage shares
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/shares')]
	public function postShare(string $shareType, string $targetType, int $targetId, ?string $groupId = null, ?string $label = null): DataResponse {
		return new DataResponse($this->callService(fn () => $this->shareService->createShare($shareType, $targetType, $targetId, $groupId, $label)));
	}

	/**
	 * Delete a share
	 *
	 * @param int $id The ID of the share
	 * @return DataResponse<Http::STATUS_OK, array{}, array{}>
	 *
	 * 200: The share was deleted
	 * @throws OCSBadRequestException if the share does not exist
	 * @throws OCSForbiddenException if the user may not manage shares
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/shares/{id}')]
	public function deleteShare(int $id): DataResponse {
		$this->callService(fn () => $this->shareService->deleteShare($id));
		return new DataResponse([]);
	}

	/**
	 * Get the groups items can be shared with
	 *
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerShareGroup>, array{}> the groups
	 *
	 * 200: The groups
	 * @throws OCSForbiddenException if the user may not manage shares
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/shares/groups')]
	public function getShareGroups(): DataResponse {
		return new DataResponse($this->callService(fn () => $this->shareService->getGroups()));
	}

	/**
	 * Get the items shared with the groups of the current user
	 *
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerSharedTarget>, array{}> the shared items
	 *
	 * 200: The shared items
	 * @throws OCSForbiddenException if nobody is logged in
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/shares/mine')]
	public function getSharedWithMe(): DataResponse {
		return new DataResponse($this->callService(fn () => $this->shareService->getSharedWithCurrentUser()));
	}
}
