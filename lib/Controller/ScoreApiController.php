<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\Db\Comment;
use OCA\OrchestraScoresManager\Db\Enum\PublicationStatus;
use OCA\OrchestraScoresManager\Db\Enum\RightsStatus;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Db\ScoreTagLinkMapper;
use OCA\OrchestraScoresManager\Db\TagMapper;
use OCA\OrchestraScoresManager\ResponseDefinitions;
use OCA\OrchestraScoresManager\Service\CommentService;
use OCA\OrchestraScoresManager\Service\FolderCollectionService;
use OCA\OrchestraScoresManager\Service\ScoreService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\IRequest;

/**
 * @psalm-import-type OrchestraScoresManagerScore from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerComment from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerFolderCollection from ResponseDefinitions
 * @psalm-import-type OrchestraScoresManagerFolderCollectionScore from ResponseDefinitions
 * @psalm-suppress UnusedClass - Controller discovered by NextCloud framework
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ScoreApiController extends OCSController {
	use ServiceExceptionBridgeTrait;

	/** Fields that may be changed with a PATCH request; everything else in the request is ignored */
	private const PATCHABLE_FIELDS = [
		'title', 'titleShort', 'composer', 'arranger', 'publisher', 'year', 'difficulty',
		'medleyContents', 'defects', 'physicalCopiesStatus', 'digitalStatus', 'gemaIds', 'duration',
		'rightsStatus', 'rightsNote', 'rightsConfirmedAt', 'rightsConfirmedBy',
		'publicationStatus', 'publicationNote', 'publicationBlockedUntil',
		'tagIds', 'scoreBook',
	];
	/** Fields given as Y-m-d strings in the API */
	private const DATE_FIELDS = ['rightsConfirmedAt', 'publicationBlockedUntil'];
	/** Fields that must not be null; a null value is turned into an invalid value for the service to reject */
	private const NON_NULLABLE_STATUS_FIELDS = ['rightsStatus', 'publicationStatus'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ScoreMapper $scoreMapper,
		private readonly ScoreTagLinkMapper $linkMapper,
		private readonly TagMapper $tagMapper,
		private readonly ScoreService $scoreService,
		private readonly CommentService $commentService,
		private readonly FolderCollectionService $folderCollectionService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return all available scores or a list of specific scores by IDs
	 *
	 * @param list<int>|null $ids Optional list of score IDs to fetch
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerScore>, array{}> the list of scores
	 *
	 * 200: Successful response with the list of scores
	 * @throws OCSBadRequestException Invalid request parameters
	 * @throws OCSForbiddenException Insufficient permissions
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores')]
	public function getScores(?array $ids = null): DataResponse {
		if ($ids !== null) {
			$scores = $this->callService(fn () => $this->scoreService->getScoresByIds($ids));
		} else {
			$scores = $this->callService(fn () => $this->scoreService->getAllScores());
		}
		return new DataResponse($scores);
	}

	/**
	 * Add a new score
	 *
	 * @param string $title The title of the score
	 * @param string|null $titleShort The short title of the score
	 * @param string|null $composer The composer of the score
	 * @param string|null $arranger The arranger of the score
	 * @param string|null $publisher The publisher of the score
	 * @param int|null $year The year of publication
	 * @param float|null $difficulty The difficulty level of the score
	 * @param list<string>|null $medleyContents The contents of the medley
	 * @param string|null $defects Any defects of the score
	 * @param string|null $physicalCopiesStatus The status of physical copies
	 * @param string|null $digitalStatus The digital status of the score
	 * @param list<string>|null $gemaIds The GEMA IDs of the score
	 * @param int|null $duration The duration of the score in seconds
	 * @param 'public_domain'|'licensed'|'protected'|'open'|null $rightsStatus The rights status of the score
	 * @param string|null $rightsNote The reasoning for the rights status
	 * @param string|null $rightsConfirmedAt The date (Y-m-d) on which a person confirmed the rights status
	 * @param string|null $rightsConfirmedBy Who confirmed the rights status, e.g. initials (at most 32 characters)
	 * @param 'approved'|'to_clarify'|'blocked'|'internal_preservation_copy'|null $publicationStatus The publication status of the score
	 * @param string|null $publicationNote The reasoning for the publication status
	 * @param string|null $publicationBlockedUntil The date (Y-m-d) until which a blocked score stays blocked; only allowed for blocked scores
	 * @param list<int>|null $tagIds optional list of tag ids to link
	 * @param array{id: int, index: int}|null $scoreBook Score book info with id and index (both required)
	 *
	 * @return DataResponse<Http::STATUS_CREATED, OrchestraScoresManagerScore, array{}> the created score
	 *
	 * 201: Successful creation of the score
	 *
	 * @throws Exception if DB insertion fails
	 * @throws \Throwable
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/scores')]
	public function postScore(
		string $title,
		?string $titleShort = null,
		?string $composer = null,
		?string $arranger = null,
		?string $publisher = null,
		?int $year = null,
		?float $difficulty = null,
		?array $medleyContents = null,
		?string $defects = null,
		?string $physicalCopiesStatus = null,
		?string $digitalStatus = null,
		?array $gemaIds = null,
		?int $duration = null,
		?string $rightsStatus = null,
		?string $rightsNote = null,
		?string $rightsConfirmedAt = null,
		?string $rightsConfirmedBy = null,
		?string $publicationStatus = null,
		?string $publicationNote = null,
		?string $publicationBlockedUntil = null,
		?array $tagIds = null,
		?array $scoreBook = null,
	): DataResponse {
		$confirmedAt = $this->callService(fn () => $this->scoreService->parseDate($rightsConfirmedAt));
		$blockedUntil = $this->callService(fn () => $this->scoreService->parseDate($publicationBlockedUntil));

		$score = Score::fromParams([
			'title' => $title,
			'titleShort' => $titleShort,
			'composer' => $composer,
			'arranger' => $arranger,
			'publisher' => $publisher,
			'year' => $year,
			'difficulty' => $difficulty,
			'medleyContents' => $medleyContents,
			'defects' => $defects,
			'physicalCopiesStatus' => $physicalCopiesStatus,
			'digitalStatus' => $digitalStatus,
			'gemaIds' => $gemaIds,
			'duration' => $duration,
			'rightsStatus' => $rightsStatus ?? RightsStatus::OPEN->value,
			'rightsNote' => $rightsNote,
			'rightsConfirmedAt' => $confirmedAt,
			'rightsConfirmedBy' => $rightsConfirmedBy,
			'publicationStatus' => $publicationStatus ?? PublicationStatus::TO_CLARIFY->value,
			'publicationNote' => $publicationNote,
			'publicationBlockedUntil' => $blockedUntil,
		]);

		// Build score book info if provided
		$scoreBookInfo = null;
		if ($scoreBook !== null) {
			$scoreBookInfo = [
				'scoreBookId' => $scoreBook['id'] ?? null,
				'index' => $scoreBook['index'] ?? null,
			];
		}

		$created = $this->callService(fn () => $this->scoreService->createScore($score, $tagIds, $scoreBookInfo));

		return new DataResponse($created, Http::STATUS_CREATED);
	}

	/**
	 * Update an existing score
	 *
	 * @param int $id The ID of the score to update
	 * @param string|null $title The title of the score
	 * @param string|null $titleShort The short title of the score
	 * @param string|null $composer The composer of the score
	 * @param string|null $arranger The arranger of the score
	 * @param string|null $publisher The publisher of the score
	 * @param int|null $year The year of publication
	 * @param float|null $difficulty The difficulty level of the score
	 * @param list<string>|null $medleyContents The contents of the medley
	 * @param string|null $defects Any defects of the score
	 * @param string|null $physicalCopiesStatus The status of physical copies
	 * @param string|null $digitalStatus The digital status of the score
	 * @param list<string>|null $gemaIds The GEMA IDs of the score
	 * @param int|null $duration The duration of the score in seconds
	 * @param 'public_domain'|'licensed'|'protected'|'open'|null $rightsStatus The rights status of the score
	 * @param string|null $rightsNote The reasoning for the rights status
	 * @param string|null $rightsConfirmedAt The date (Y-m-d) on which a person confirmed the rights status
	 * @param string|null $rightsConfirmedBy Who confirmed the rights status, e.g. initials (at most 32 characters)
	 * @param 'approved'|'to_clarify'|'blocked'|'internal_preservation_copy'|null $publicationStatus The publication status of the score
	 * @param string|null $publicationNote The reasoning for the publication status
	 * @param string|null $publicationBlockedUntil The date (Y-m-d) until which a blocked score stays blocked; only allowed for blocked scores
	 * @param list<int>|null $tagIds optional list of tag ids to link
	 * @param array{id?: int, index?: int}|null $scoreBook Score book info (id and/or index). Set id to null to remove from book.
	 *
	 * @return DataResponse<Http::STATUS_OK, OrchestraScoresManagerScore, array{}> the updated score
	 *
	 * @throws OCSBadRequestException If the score is not found
	 * @throws Exception
	 * @throws \Throwable If adding/removing tags fails
	 *
	 * 200: Successful update of the score
	 *
	 * @psalm-suppress PossiblyUnusedParam - extracted from request params
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/scores/{id}')]
	public function patchScore(
		int $id,
		?string $title = null,
		?string $titleShort = null,
		?string $composer = null,
		?string $arranger = null,
		?string $publisher = null,
		?int $year = null,
		?float $difficulty = null,
		?array $medleyContents = null,
		?string $defects = null,
		?string $physicalCopiesStatus = null,
		?string $digitalStatus = null,
		?array $gemaIds = null,
		?int $duration = null,
		?string $rightsStatus = null,
		?string $rightsNote = null,
		?string $rightsConfirmedAt = null,
		?string $rightsConfirmedBy = null,
		?string $publicationStatus = null,
		?string $publicationNote = null,
		?string $publicationBlockedUntil = null,
		?array $tagIds = null,
		?array $scoreBook = null,
	): DataResponse {
		// authorization will be handled in services
		$score = $this->callService(fn () => $this->scoreService->getScoreById($id));
		// Only take over known fields. This also drops the NC-added "_route" param and keeps
		// fields managed elsewhere (e.g. the MusicXML file) from being set via PATCH.
		$params = array_intersect_key($this->request->getParams(), array_flip(self::PATCHABLE_FIELDS));

		foreach (self::NON_NULLABLE_STATUS_FIELDS as $field) {
			if (array_key_exists($field, $params) && $params[$field] === null) {
				$params[$field] = '';
			}
		}
		foreach (self::DATE_FIELDS as $field) {
			if (array_key_exists($field, $params)) {
				/** @psalm-suppress MixedAssignment */
				$value = $params[$field];
				$params[$field] = $this->callService(fn () => $this->scoreService->parseDate($value));
			}
		}

		// Handle score book info separately
		$scoreBookInfo = null;
		$hasScoreBook = array_key_exists('scoreBook', $params);

		if ($hasScoreBook) {
			/** @var array{id?: int|null, index?: int|null}|null $scoreBookParam */
			$scoreBookParam = $params['scoreBook'];
			$scoreBookInfo = [];
			if ($scoreBookParam === null) {
				// Setting scoreBook to null means remove from book
				$scoreBookInfo['scoreBookId'] = null;
			} else {
				if (array_key_exists('id', $scoreBookParam)) {
					$scoreBookInfo['scoreBookId'] = $scoreBookParam['id'];
				}
				if (array_key_exists('index', $scoreBookParam)) {
					$scoreBookInfo['index'] = $scoreBookParam['index'];
				}
			}
		}

		// Remove score book param from regular field updates
		unset($params['scoreBook']);
		/** @var string $field
		 * @psalm-suppress MixedAssignment
		 * @psalm-suppress MixedArgumentTypeCoercion
		 */
		foreach ($params as $field => $value) {
			if ($field === 'tagIds') {
				continue;
			} // handled separately in service
			$score->{'set' . ucfirst($field)}($value);
		}

		$updated = $this->callService(fn () => $this->scoreService->updateScore($score, $tagIds, $scoreBookInfo));
		return new DataResponse($updated, Http::STATUS_OK);
	}

	/**
	 * Delete a specific score
	 *
	 * @param int $id The ID of the score to delete
	 * @return DataResponse<Http::STATUS_NO_CONTENT, null, array{}> empty response
	 *
	 * 204: Successful deletion of the score
	 * 400: Bad request
	 * 403: Forbidden
	 * @throws OCSBadRequestException if the score does not exist
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/scores/{id}')]
	public function deleteScore(int $id): DataResponse {
		$this->callService(fn () => $this->scoreService->deleteScoreById($id));
		return new DataResponse(null, Http::STATUS_NO_CONTENT);
	}

	/**
	 * Get all comments for a specific score
	 *
	 * @param int $id The ID of the score
	 *
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerComment>, array{}> the list of comments
	 *
	 * 200: Successful response with the list of comments
	 * 400: Bad request
	 * 403: Forbidden
	 * @throws OCSBadRequestException if the request is invalid
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores/{id}/comments')]
	public function getScoreComments(int $id): DataResponse {
		$comments = $this->callService(fn () => $this->commentService->getCommentsForScore($id));
		return new DataResponse($comments);
	}

	/**
	 * Create a new comment for a specific score
	 *
	 * @param int $id The ID of the score
	 * @param string $content The content of the comment
	 * @param string $userId The user ID of the commenter
	 * @param int $creationDate The creation date timestamp
	 *
	 * @return DataResponse<Http::STATUS_CREATED, OrchestraScoresManagerComment, array{}> the created comment
	 *
	 * 201: Successful creation of the comment
	 * 400: Bad request
	 * 403: Forbidden
	 * @throws OCSBadRequestException if the request is invalid
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/scores/{id}/comments')]
	public function postScoreComment(int $id, string $content, string $userId, int $creationDate): DataResponse {
		$comment = Comment::fromParams([
			'content' => $content,
			'userId' => $userId,
			'creationDate' => $creationDate,
			'scoreId' => $id,
		]);

		$created = $this->callService(fn () => $this->commentService->createComment($comment));
		return new DataResponse($created, Http::STATUS_CREATED);
	}

	/**
	 * Get all folder collections for a specific score
	 *
	 * @param int $id The ID of the score
	 *
	 * @return DataResponse<Http::STATUS_OK, list<OrchestraScoresManagerFolderCollectionScore>, array{}> the list of folder collection information
	 *
	 * 200: Successful response with the list of folder collections
	 * 400: Bad request
	 * 403: Forbidden
	 * @throws OCSBadRequestException if the request is invalid
	 * @throws OCSForbiddenException if the user does not have permission
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/scores/{id}/foldercollections')]
	public function getScoreFolderCollections(int $id): DataResponse {
		$folderCollections = $this->callService(fn () => $this->folderCollectionService->getFolderCollectionsForScore($id));
		return new DataResponse($folderCollections);
	}
}
