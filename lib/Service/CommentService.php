<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCA\OrchestraScoresManager\Db\Comment;
use OCA\OrchestraScoresManager\Db\CommentMapper;
use OCA\OrchestraScoresManager\Policy\CommentPolicy;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IUserManager;
use OCP\IUserSession;

class CommentService {
	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly CommentMapper $commentMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly CommentPolicy $commentPolicy,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Get a comment by its ID.
	 *
	 * @param int $id
	 * @return Comment
	 * @throws NotFoundException
	 */
	public function getCommentById(int $id): Comment {
		$this->authorizationService->authorizePolicy($this->commentPolicy, PolicyInterface::ACTION_READ);
		try {
			$comment = $this->commentMapper->find($id);
			$this->enrichCommentWithDisplayName($comment);
			return $comment;
		} catch (MultipleObjectsReturnedException|DoesNotExistException|Exception $e) {
			throw new NotFoundException('Comment not found', 0, $e);
		}
	}

	/**
	 * Get all comments for a given score in chronological descending order.
	 *
	 * @param int $scoreId
	 * @return array<Comment>
	 * @throws Exception
	 */
	public function getCommentsForScore(int $scoreId): array {
		$this->authorizationService->authorizePolicy($this->commentPolicy, PolicyInterface::ACTION_READ);
		$comments = $this->commentMapper->findByScoreId($scoreId);

		// Enrich comments with display names
		foreach ($comments as $comment) {
			$this->enrichCommentWithDisplayName($comment);
		}

		return $comments;
	}

	/**
	 * Create a new comment if authorized.
	 * Default-deny: if authorization fails, an exception is thrown.
	 * Author and creation date are always taken from the current session and server time,
	 * never from the client.
	 *
	 * @throws Exception
	 * @throws \InvalidArgumentException If the content is empty
	 */
	public function createComment(Comment $comment): Comment {
		$this->authorizationService->authorizePolicy($this->commentPolicy, PolicyInterface::ACTION_CREATE);

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new PermissionDeniedException('Not authenticated');
		}
		if (trim($comment->getContent()) === '') {
			throw new \InvalidArgumentException('Comment content must not be empty');
		}

		$comment->setUserId($user->getUID());
		// milliseconds, matching what the frontend displays
		$comment->setCreationDate($this->timeFactory->getTime() * 1000);

		$created = $this->commentMapper->insert($comment);

		// Enrich with display name before returning
		$this->enrichCommentWithDisplayName($created);

		return $created;
	}

	/**
	 * Delete a comment if authorized.
	 *
	 * @param int $id
	 * @throws Exception
	 * @throws NotFoundException
	 */
	public function deleteComment(int $id): void {
		$this->authorizationService->authorizePolicy($this->commentPolicy, PolicyInterface::ACTION_DELETE);
		try {
			$comment = $this->commentMapper->find($id);
		} catch (MultipleObjectsReturnedException|DoesNotExistException $e) {
			throw new NotFoundException('Comment not found', 0, $e);
		}
		$this->commentMapper->delete($comment);
	}

	/**
	 * Enrich a comment with the display name of the user.
	 *
	 * @param Comment $comment
	 */
	private function enrichCommentWithDisplayName(Comment $comment): void {
		$userId = $comment->getUserId();
		$user = $this->userManager->get($userId);
		$displayName = $user?->getDisplayName();
		$comment->setAuthorDisplayName($displayName);
	}
}
