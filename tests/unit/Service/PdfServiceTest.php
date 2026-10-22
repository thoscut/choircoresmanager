<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCA\OrchestraScoresManager\Service\AuthorizationService;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\PdfService;
use OCA\OrchestraScoresManager\Service\PermissionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PdfService.
 *
 * Nextcloud Files is mocked: the root folder returns a user folder per user id,
 * and the user folder returns the nodes for a file id.
 */
final class PdfServiceTest extends TestCase {
	private const VALID_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";

	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private ScoreMapper&MockObject $scoreMapper;
	private AuthorizationService&MockObject $authorizationService;
	private ScorePolicy&MockObject $scorePolicy;
	private PdfService $service;
	/** @var array<string, Folder&MockObject> */
	private array $userFolders = [];

	protected function setUp(): void {
		parent::setUp();

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $userId): Folder {
			if (!isset($this->userFolders[$userId])) {
				// Like the real root folder for an unknown user
				throw new \Exception("User $userId does not exist");
			}
			return $this->userFolders[$userId];
		});
		$this->userSession = $this->createMock(IUserSession::class);
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->scorePolicy = $this->createMock(ScorePolicy::class);

		$this->service = $this->createService($this->authorizationService, $this->scorePolicy);
	}

	private function createService(AuthorizationService $authorizationService, ScorePolicy $scorePolicy): PdfService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		return new PdfService(
			$this->rootFolder,
			$this->userSession,
			$this->scoreMapper,
			$authorizationService,
			$scorePolicy,
			$l10n,
		);
	}

	/**
	 * A service using the real policy, so that the preservation copy rule itself is tested.
	 */
	private function createServiceWithRealPolicy(bool $canEdit): PdfService {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isCurrentUserLoggedIn')->willReturn(true);
		$permissionService->method('canCurrentUserEdit')->willReturn($canEdit);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $this->createService(
			new AuthorizationService($this->createMock(LoggerInterface::class), 'orchestrascoresmanager', $l10n),
			new ScorePolicy($permissionService),
		);
	}

	private function loginAs(string $userId): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Make the given nodes reachable for a user under a file id.
	 *
	 * @param list<File|Folder> $nodes
	 */
	private function giveAccess(string $userId, int $fileId, array $nodes): void {
		$folder = $this->userFolders[$userId] ?? $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(fn (int $id) => $id === $fileId ? $nodes : []);
		$this->userFolders[$userId] = $folder;
	}

	private function createFile(string $name, string $content, bool $readable = true): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('getContent')->willReturn($content);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}

	private function createScore(int $id = 1, ?int $fileId = null, ?string $linkedBy = null): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle('Test');
		if ($fileId !== null) {
			$score->setPdfFile('score.pdf');
			$score->setPdfFileId($fileId);
			$score->setPdfLinkedBy($linkedBy);
		}
		$score->resetUpdatedFields();
		return $score;
	}

	public function testLinkForScoreStoresFileIdNameAndUser(): void {
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile('Ave Maria.pdf', self::VALID_PDF)]);
		$this->scoreMapper->method('find')->with(1)->willReturn($this->createScore());
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE);
		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getPdfFile() === 'Ave Maria.pdf'
				&& $s->getPdfFileId() === 42
				&& $s->getPdfLinkedBy() === 'librarian'));

		$this->service->linkForScore(1, 42);
	}

	public function testLinkForScoreUsesReadableNodeIfFileIsReachableSeveralTimes(): void {
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [
			$this->createFile('hidden.pdf', self::VALID_PDF, false),
			$this->createFile('visible.pdf', self::VALID_PDF),
		]);
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getPdfFile() === 'visible.pdf'));

		$this->service->linkForScore(1, 42);
	}

	public function testLinkForScoreRequiresUpdatePermission(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->authorizationService->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(PermissionDeniedException::class);
		$this->service->linkForScore(1, 42);
	}

	public function testLinkForScoreRejectsFileTheUserCannotReach(): void {
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, []);
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The selected file does not exist or you are not allowed to read it');
		$this->service->linkForScore(1, 42);
	}

	public static function invalidFileProvider(): array {
		return [
			'wrong extension' => ['score.txt', self::VALID_PDF, 'Only PDF files are allowed'],
			'empty file' => ['score.pdf', '', 'The file is empty'],
			'no PDF header' => ['score.pdf', 'just some text', 'The file is not a valid PDF file'],
			'too large' => ['score.pdf', '%PDF-' . str_repeat('x', PdfService::MAX_FILE_SIZE), 'The file is too large (maximum 50 MB)'],
		];
	}

	#[DataProvider('invalidFileProvider')]
	public function testLinkForScoreRejectsInvalidFiles(string $name, string $content, string $message): void {
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile($name, $content)]);
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->linkForScore(1, 42);
	}

	public function testLinkForScoreAcceptsGarbageBeforeTheHeader(): void {
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile('scan.PDF', "\xEF\xBB\xBF" . self::VALID_PDF)]);
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->scoreMapper->expects($this->once())->method('update');

		$this->service->linkForScore(1, 42);
	}

	public function testLinkForScoreRejectsUnreadableFile(): void {
		$this->loginAs('librarian');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('score.pdf');
		$file->method('getSize')->willReturn(100);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willThrowException(new NotPermittedException());
		$this->giveAccess('librarian', 42, [$file]);
		$this->scoreMapper->method('find')->willReturn($this->createScore());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The file could not be read');
		$this->service->linkForScore(1, 42);
	}

	public function testUnlinkForScoreClearsTheLinkOnly(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore(1, 42, 'librarian'));
		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getPdfFile() === null
				&& $s->getPdfFileId() === null
				&& $s->getPdfLinkedBy() === null));

		$this->service->unlinkForScore(1);
	}

	public function testReadFileUsesTheAccessOfTheLinkingUser(): void {
		$this->giveAccess('librarian', 42, [$this->createFile('score.pdf', self::VALID_PDF)]);

		$file = $this->service->readFile($this->createScore(7, 42, 'librarian'));

		$this->assertSame(self::VALID_PDF, $file['content']);
		$this->assertSame('application/pdf', $file['mimeType']);
		$this->assertSame('score-7.pdf', $file['fileName']);
	}

	public function testReadFileFailsWithoutLinkedFile(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('No PDF file is linked to this score');
		$this->service->readFile($this->createScore(7));
	}

	public function testReadFileFailsIfTheLinkingUserLostAccess(): void {
		$this->giveAccess('librarian', 42, []);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('The linked PDF file is missing or no longer accessible.');
		$this->service->readFile($this->createScore(7, 42, 'librarian'));
	}

	public function testReadFileFailsIfTheLinkingUserWasDeleted(): void {
		$this->expectException(NotFoundException::class);
		$this->service->readFile($this->createScore(7, 42, 'gone'));
	}

	public function testReadFileFailsIfTheFileIsNoLongerAPdf(): void {
		$this->giveAccess('librarian', 42, [$this->createFile('score.pdf', 'replaced by text')]);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('The file is not a valid PDF file');
		$this->service->readFile($this->createScore(7, 42, 'librarian'));
	}

	public function testGetForScoreRequiresReadPermission(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ)
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(PermissionDeniedException::class);
		$this->service->getForScore(1);
	}

	public function testGetForScoreDeniesPreservationCopyToNonEditors(): void {
		$score = $this->createScore(6, 42, 'librarian');
		$score->setPublicationStatus('internal_preservation_copy');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->giveAccess('librarian', 42, [$this->createFile('copy.pdf', self::VALID_PDF)]);

		$this->expectException(PermissionDeniedException::class);
		$this->createServiceWithRealPolicy(false)->getForScore(6);
	}

	public function testGetForScoreServesPreservationCopyToEditors(): void {
		$score = $this->createScore(6, 42, 'librarian');
		$score->setPublicationStatus('internal_preservation_copy');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->giveAccess('librarian', 42, [$this->createFile('copy.pdf', self::VALID_PDF)]);

		$this->assertSame(self::VALID_PDF, $this->createServiceWithRealPolicy(true)->getForScore(6)['content']);
	}

	public function testGetForScoreServesRegularScoresToSingers(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore(6, 42, 'librarian'));
		$this->giveAccess('librarian', 42, [$this->createFile('score.pdf', self::VALID_PDF)]);

		$this->assertSame(self::VALID_PDF, $this->createServiceWithRealPolicy(false)->getForScore(6)['content']);
	}
}
