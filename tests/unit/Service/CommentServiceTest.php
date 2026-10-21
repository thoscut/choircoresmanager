<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Comment;
use OCA\OrchestraScoresManager\Db\CommentMapper;
use OCA\OrchestraScoresManager\Policy\CommentPolicy;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Service\AuthorizationService;
use OCA\OrchestraScoresManager\Service\CommentService;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CommentService.
 */
final class CommentServiceTest extends TestCase {
	private CommentMapper $commentMapper;
	private AuthorizationService $authorizationService;
	private CommentPolicy $commentPolicy;
	private IUserManager $userManager;
	private IUserSession $userSession;
	private ITimeFactory $timeFactory;
	private CommentService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->commentPolicy = $this->createMock(CommentPolicy::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(1700000000);

		$this->service = new CommentService(
			$this->commentMapper,
			$this->authorizationService,
			$this->commentPolicy,
			$this->userManager,
			$this->userSession,
			$this->timeFactory
		);
	}

	public function testGetCommentByIdRequiresAuthorization(): void {
		$comment = new Comment();
		$comment->setId(1);
		$comment->setUserId('user1');

		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('getDisplayName')
			->willReturn('User One');

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->commentPolicy, PolicyInterface::ACTION_READ);

		$this->commentMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($comment);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user1')
			->willReturn($user);

		$result = $this->service->getCommentById(1);

		$this->assertSame($comment, $result);
		$this->assertSame('User One', $result->getAuthorDisplayName());
	}

	public function testGetCommentByIdThrowsWhenNotFound(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->commentMapper->expects($this->once())
			->method('find')
			->with(999)
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Comment not found');

		$this->service->getCommentById(999);
	}

	public function testGetCommentByIdSetsDisplayNameToNullWhenUserNotFound(): void {
		$comment = new Comment();
		$comment->setId(1);
		$comment->setUserId('deleted_user');

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->commentMapper->expects($this->once())
			->method('find')
			->willReturn($comment);

		$this->userManager->expects($this->once())
			->method('get')
			->with('deleted_user')
			->willReturn(null);

		$result = $this->service->getCommentById(1);

		$this->assertNull($result->getAuthorDisplayName());
	}

	public function testGetCommentsForScoreRequiresAuthorization(): void {
		$comment1 = new Comment();
		$comment1->setUserId('user1');
		$comment2 = new Comment();
		$comment2->setUserId('user2');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getDisplayName')->willReturn('User One');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getDisplayName')->willReturn('User Two');

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->commentPolicy, PolicyInterface::ACTION_READ);

		$this->commentMapper->expects($this->once())
			->method('findByScoreId')
			->with(5)
			->willReturn([$comment1, $comment2]);

		$this->userManager->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function ($userId) use ($user1, $user2) {
				return $userId === 'user1' ? $user1 : $user2;
			});

		$result = $this->service->getCommentsForScore(5);

		$this->assertCount(2, $result);
		$this->assertSame('User One', $result[0]->getAuthorDisplayName());
		$this->assertSame('User Two', $result[1]->getAuthorDisplayName());
	}

	public function testCreateCommentRequiresAuthorization(): void {
		$comment = new Comment();
		$comment->setContent('Hello');
		$sessionUser = $this->createMock(IUser::class);
		$sessionUser->method('getUID')->willReturn('user1');
		$this->userSession->method('getUser')->willReturn($sessionUser);
		$createdComment = new Comment();
		$createdComment->setId(1);
		$createdComment->setUserId('user1');

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('User One');

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->commentPolicy, PolicyInterface::ACTION_CREATE);

		$this->commentMapper->expects($this->once())
			->method('insert')
			->with($comment)
			->willReturn($createdComment);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user1')
			->willReturn($user);

		$result = $this->service->createComment($comment);

		$this->assertSame($createdComment, $result);
		$this->assertSame('User One', $result->getAuthorDisplayName());
	}

	public function testCreateCommentIgnoresClientSuppliedAuthorAndDate(): void {
		$comment = new Comment();
		$comment->setContent('Hello');
		$comment->setUserId('someone-else');
		$comment->setCreationDate(42);

		$sessionUser = $this->createMock(IUser::class);
		$sessionUser->method('getUID')->willReturn('user1');
		$this->userSession->method('getUser')->willReturn($sessionUser);

		$this->commentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Comment $c) => $c->getUserId() === 'user1' && $c->getCreationDate() === 1700000000000))
			->willReturnArgument(0);

		$this->service->createComment($comment);
	}

	public function testCreateCommentThrowsWithoutSessionUser(): void {
		$comment = new Comment();
		$comment->setContent('Hello');
		$this->userSession->method('getUser')->willReturn(null);

		$this->commentMapper->expects($this->never())->method('insert');
		$this->expectException(PermissionDeniedException::class);

		$this->service->createComment($comment);
	}

	public function testCreateCommentThrowsForEmptyContent(): void {
		$comment = new Comment();
		$comment->setContent('   ');
		$sessionUser = $this->createMock(IUser::class);
		$sessionUser->method('getUID')->willReturn('user1');
		$this->userSession->method('getUser')->willReturn($sessionUser);

		$this->commentMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->createComment($comment);
	}

	public function testCreateCommentThrowsWhenNotAuthorized(): void {
		$comment = new Comment();

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Not authorized'));

		$this->commentMapper->expects($this->never())
			->method('insert');

		$this->expectException(PermissionDeniedException::class);

		$this->service->createComment($comment);
	}

	public function testDeleteCommentRequiresAuthorization(): void {
		$comment = new Comment();
		$comment->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->commentPolicy, PolicyInterface::ACTION_DELETE);

		$this->commentMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($comment);

		$this->commentMapper->expects($this->once())
			->method('delete')
			->with($comment);

		$this->service->deleteComment(1);
	}

	public function testDeleteCommentThrowsWhenNotFound(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->commentMapper->expects($this->once())
			->method('find')
			->with(999)
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(DoesNotExistException::class);

		$this->service->deleteComment(999);
	}

	public function testGetCommentsForScorePropagatesMapperException(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->commentMapper->expects($this->once())
			->method('findByScoreId')
			->willThrowException(new Exception('Database error'));

		$this->expectException(Exception::class);

		$this->service->getCommentsForScore(5);
	}
}
