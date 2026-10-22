<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\Service\AuthorizationService;
use OCA\ChoirScores\Service\Exceptions\NotFoundException;
use OCA\ChoirScores\Service\Exceptions\PermissionDeniedException;
use OCA\ChoirScores\Service\MusicXmlService;
use OCA\ChoirScores\Service\PermissionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MusicXmlService.
 *
 * Nextcloud Files is mocked: the root folder returns a user folder per user id,
 * and the user folder returns the nodes for a file id.
 */
final class MusicXmlServiceTest extends TestCase {
	private const VALID_XML = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">'
		. '<score-partwise version="4.0"><part-list/></score-partwise>';

	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private ITempManager&MockObject $tempManager;
	private ScoreMapper&MockObject $scoreMapper;
	private AuthorizationService&MockObject $authorizationService;
	private ScorePolicy&MockObject $scorePolicy;
	private MusicXmlService $service;
	/** @var array<string, Folder&MockObject> */
	private array $userFolders = [];
	/** @var list<string> */
	private array $tmpFiles = [];

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
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'chs-test');
			$this->tmpFiles[] = $path;
			return $path;
		});
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->scorePolicy = $this->createMock(ScorePolicy::class);

		$this->service = $this->createService($this->authorizationService, $this->scorePolicy);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $file) {
			@unlink($file);
		}
		parent::tearDown();
	}

	private function createService(AuthorizationService $authorizationService, ScorePolicy $scorePolicy): MusicXmlService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		return new MusicXmlService(
			$this->rootFolder,
			$this->userSession,
			$this->tempManager,
			$this->scoreMapper,
			$authorizationService,
			$scorePolicy,
			$l10n,
		);
	}

	private function loginAs(string $userId): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * A mock of a user's home folder; from Nextcloud 34 on, getUserFolder() returns the dedicated IUserFolder.
	 */
	private function createUserFolderMock(): Folder&MockObject {
		/** @var class-string<Folder> $class */
		$class = interface_exists('OCP\\Files\\IUserFolder') ? 'OCP\\Files\\IUserFolder' : Folder::class;
		return $this->createMock($class);
	}

	/**
	 * Make the given nodes reachable for a user under a file id.
	 *
	 * @param list<File|Folder> $nodes
	 */
	private function giveAccess(string $userId, int $fileId, array $nodes): void {
		$folder = $this->userFolders[$userId] ?? $this->createUserFolderMock();
		$folder->method('getById')->willReturnCallback(fn (int $id) => $id === $fileId ? $nodes : []);
		$this->userFolders[$userId] = $folder;
	}

	private function createFile(string $name, string $content, bool $readable = true, int $mtime = 1760000000): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('getContent')->willReturn($content);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getMTime')->willReturn($mtime);
		return $file;
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function createZip(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'chs-test');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return (string)file_get_contents($path);
	}

	/**
	 * A compressed file whose highly compressible entries sum up to more than the uncompressed size limit.
	 */
	private function createZipBomb(): string {
		$chunkSize = 1024 * 1024;
		$chunkFile = tempnam(sys_get_temp_dir(), 'chs-test');
		$this->tmpFiles[] = $chunkFile;
		file_put_contents($chunkFile, str_repeat('a', $chunkSize));
		$path = tempnam(sys_get_temp_dir(), 'chs-test');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('score.musicxml', self::VALID_XML);
		for ($i = 0; $i <= MusicXmlService::MAX_UNCOMPRESSED_SIZE / $chunkSize; $i++) {
			$zip->addFile($chunkFile, "part$i.xml");
		}
		$zip->close();
		$content = (string)file_get_contents($path);
		$this->assertLessThan(MusicXmlService::MAX_FILE_SIZE, strlen($content));
		return $content;
	}

	private function createScore(int $id = 1, ?int $fileId = null, ?string $linkedBy = null): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle('Test');
		if ($fileId !== null) {
			$score->setMusicxmlFile('linked.musicxml');
			$score->setMusicxmlFileId($fileId);
			$score->setMusicxmlLinkedBy($linkedBy);
		}
		$score->resetUpdatedFields();
		return $score;
	}

	public function testLinkStoresFileIdAndLinkingUserAndKeepsDisplayName(): void {
		$score = $this->createScore(7);
		$this->scoreMapper->method('find')->with(7)->willReturn($score);
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile('Ave Maria.musicxml', self::VALID_XML)]);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getMusicxmlFile() === 'Ave Maria.musicxml'
				&& $s->getMusicxmlFileId() === 42
				&& $s->getMusicxmlLinkedBy() === 'librarian'));

		$this->service->linkForScore(7, 42);
	}

	public function testLinkUsesAReadableNodeIfTheFileIsReachableSeveralTimes(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [
			$this->createMock(Folder::class),
			$this->createFile('blocked.musicxml', self::VALID_XML, false),
			$this->createFile('readable.musicxml', self::VALID_XML),
		]);

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getMusicxmlFile() === 'readable.musicxml'));

		$this->service->linkForScore(1, 42);
	}

	public function testLinkAcceptsCompressedMusicXml(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$content = $this->createZip([
			'META-INF/container.xml' => '<container/>',
			'score.musicxml' => self::VALID_XML,
		]);
		$this->giveAccess('librarian', 42, [$this->createFile('score.mxl', $content)]);

		$this->scoreMapper->expects($this->once())->method('update');

		$this->service->linkForScore(1, 42);
	}

	public function testLinkRequiresUpdatePermission(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('singer');
		$this->authorizationService->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(PermissionDeniedException::class);
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRequiresLoggedInUser(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->userSession->method('getUser')->willReturn(null);
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(PermissionDeniedException::class);
		$this->service->linkForScore(1, 42);
	}

	public static function inaccessibleFileProvider(): array {
		return [
			'file id not reachable for the user' => [[]],
			'file not readable' => [['unreadable']],
			'folder instead of a file' => [['folder']],
		];
	}

	/**
	 * @param list<string> $nodeKinds
	 */
	#[DataProvider('inaccessibleFileProvider')]
	public function testLinkFailsIfTheCurrentUserCannotReadTheFile(array $nodeKinds): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$nodes = array_map(fn (string $kind) => $kind === 'folder'
			? $this->createMock(Folder::class)
			: $this->createFile('score.musicxml', self::VALID_XML, false), $nodeKinds);
		$this->giveAccess('librarian', 42, $nodes);
		// another user could read it, but that must not matter
		$this->giveAccess('admin', 42, [$this->createFile('score.musicxml', self::VALID_XML)]);
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('does not exist or you are not allowed to read it');
		$this->service->linkForScore(1, 42);
	}

	public static function invalidFileProvider(): array {
		return [
			'wrong extension' => ['score.pdf', self::VALID_XML, 'Only MusicXML files'],
			'svg disguised as musicxml' => ['score.musicxml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'not a valid MusicXML'],
			'html' => ['score.xml', '<html><body>hi</body></html>', 'not a valid MusicXML'],
			'entity declaration (XXE)' => [
				'score.musicxml',
				'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><score-partwise>&xxe;</score-partwise>',
				'entity declarations',
			],
			'empty' => ['score.musicxml', '', 'empty'],
			'not a zip despite the magic bytes' => ['score.mxl', "PK\x03\x04garbage", 'not a valid compressed MusicXML'],
		];
	}

	#[DataProvider('invalidFileProvider')]
	public function testLinkRejectsInvalidFiles(string $name, string $content, string $message): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile($name, $content)]);
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRejectsZipWithoutScore(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile('score.mxl', $this->createZip(['image.png' => 'x']))]);
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRejectsZipBomb(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$this->giveAccess('librarian', 42, [$this->createFile('score.mxl', $this->createZipBomb())]);
		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('too large when uncompressed');
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRejectsTooLargeFileWithoutReadingIt(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('huge.musicxml');
		$file->method('isReadable')->willReturn(true);
		$file->method('getSize')->willReturn(MusicXmlService::MAX_FILE_SIZE + 1);
		$file->expects($this->never())->method('getContent');
		$this->giveAccess('librarian', 42, [$file]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('too large');
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRejectsFileThatCannotBeRead(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->loginAs('librarian');
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('score.musicxml');
		$file->method('isReadable')->willReturn(true);
		$file->method('getSize')->willReturn(100);
		$file->method('getContent')->willThrowException(new NotPermittedException());
		$this->giveAccess('librarian', 42, [$file]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('could not be read');
		$this->service->linkForScore(1, 42);
	}

	public function testLinkRejectsUnknownScore(): void {
		$this->scoreMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score not found');
		$this->service->linkForScore(99, 42);
	}

	public function testUnlinkOnlyRemovesTheReference(): void {
		$score = $this->createScore(4, 42, 'librarian');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);
		// the file in Files is not even looked at, let alone deleted
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getMusicxmlFile() === null
				&& $s->getMusicxmlFileId() === null
				&& $s->getMusicxmlLinkedBy() === null));

		$this->service->unlinkForScore(4);
	}

	public function testReadFileUsesTheAccessOfTheLinkingUser(): void {
		// nobody is logged in, as on the public page
		$this->userSession->method('getUser')->willReturn(null);
		$this->giveAccess('librarian', 42, [$this->createFile('linked.musicxml', self::VALID_XML)]);

		$result = $this->service->readFile($this->createScore(5, 42, 'librarian'));

		$this->assertSame(self::VALID_XML, $result['content']);
		$this->assertSame(MusicXmlService::MIME_TYPE_XML, $result['mimeType']);
		$this->assertSame('score-5.musicxml', $result['fileName']);
	}

	public function testReadFileDetectsCompressedFile(): void {
		$content = $this->createZip(['score.musicxml' => self::VALID_XML]);
		$this->giveAccess('librarian', 42, [$this->createFile('linked.mxl', $content)]);

		$result = $this->service->readFile($this->createScore(5, 42, 'librarian'));

		$this->assertSame($content, $result['content']);
		$this->assertSame(MusicXmlService::MIME_TYPE_COMPRESSED, $result['mimeType']);
		$this->assertSame('score-5.mxl', $result['fileName']);
	}

	public function testReadFileFailsWithoutLinkedFile(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('No MusicXML file is linked');
		$this->service->readFile($this->createScore(5));
	}

	public static function unavailableFileProvider(): array {
		return [
			'file deleted or moved out of reach' => ['librarian', []],
			'linking user lost read access' => ['librarian', ['unreadable']],
			'linking user no longer exists' => ['former-librarian', null],
		];
	}

	/**
	 * @param list<string>|null $nodeKinds Nodes reachable for the user, null if the user does not exist
	 */
	#[DataProvider('unavailableFileProvider')]
	public function testReadFileFailsIfTheLinkedFileIsUnavailable(string $linkedBy, ?array $nodeKinds): void {
		if ($nodeKinds !== null) {
			$this->giveAccess($linkedBy, 42, array_map(fn () => $this->createFile('linked.musicxml', self::VALID_XML, false), $nodeKinds));
		}

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('missing or no longer accessible');
		$this->service->readFile($this->createScore(5, 42, $linkedBy));
	}

	public static function invalidServedContentProvider(): array {
		return [
			'replaced by a document with entity declarations' => [
				'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><score-partwise>&xxe;</score-partwise>',
				'entity declarations',
			],
			'replaced by HTML' => ['<html><body><script>alert(1)</script></body></html>', 'not a valid MusicXML'],
		];
	}

	#[DataProvider('invalidServedContentProvider')]
	public function testReadFileValidatesTheContentAgain(string $content, string $message): void {
		$this->giveAccess('librarian', 42, [$this->createFile('linked.musicxml', $content)]);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage($message);
		$this->service->readFile($this->createScore(5, 42, 'librarian'));
	}

	public function testReadFileRejectsZipBomb(): void {
		$this->giveAccess('librarian', 42, [$this->createFile('linked.mxl', $this->createZipBomb())]);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('too large when uncompressed');
		$this->service->readFile($this->createScore(5, 42, 'librarian'));
	}

	public function testGetFileMTimeReturnsModificationTimeOfReadableFile(): void {
		$file = $this->createFile('linked.musicxml', self::VALID_XML, true, 1761000000);
		$file->expects($this->never())->method('getContent');
		$this->giveAccess('librarian', 42, [$file]);

		$this->assertSame(1761000000, $this->service->getFileMTime($this->createScore(5, 42, 'librarian')));
	}

	public function testGetFileMTimeIsNullWithoutUsableFile(): void {
		$this->giveAccess('librarian', 42, [$this->createFile('linked.musicxml', self::VALID_XML, false)]);
		$this->giveAccess('librarian', 43, [$this->createFile('linked.pdf', self::VALID_XML)]);

		$this->assertNull($this->service->getFileMTime($this->createScore(5)));
		$this->assertNull($this->service->getFileMTime($this->createScore(5, 42, 'librarian')));
		$this->assertNull($this->service->getFileMTime($this->createScore(5, 43, 'librarian')));
		$this->assertNull($this->service->getFileMTime($this->createScore(5, 42, 'unknown-user')));
	}

	public function testGetForScoreRequiresReadPermission(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ)
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(PermissionDeniedException::class);
		$this->service->getForScore(1);
	}

	/**
	 * A service using the real policy, so that the preservation copy rule itself is tested.
	 */
	private function createServiceWithRealPolicy(bool $canEdit): MusicXmlService {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isCurrentUserLoggedIn')->willReturn(true);
		$permissionService->method('canCurrentUserEdit')->willReturn($canEdit);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $this->createService(
			new AuthorizationService($this->createMock(LoggerInterface::class), 'choirscores', $l10n),
			new ScorePolicy($permissionService),
		);
	}

	private function createPreservationCopy(): Score {
		$score = $this->createScore(6, 42, 'librarian');
		$score->setPublicationStatus('internal_preservation_copy');
		return $score;
	}

	public function testGetForScoreDeniesPreservationCopyToNonEditors(): void {
		$this->scoreMapper->method('find')->with(6)->willReturn($this->createPreservationCopy());
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->expectException(PermissionDeniedException::class);
		$this->createServiceWithRealPolicy(false)->getForScore(6);
	}

	public function testGetForScoreServesPreservationCopyToEditors(): void {
		$this->scoreMapper->method('find')->with(6)->willReturn($this->createPreservationCopy());
		$this->giveAccess('librarian', 42, [$this->createFile('linked.musicxml', self::VALID_XML)]);

		$this->assertSame(self::VALID_XML, $this->createServiceWithRealPolicy(true)->getForScore(6)['content']);
	}

	#[DataProvider('servedToEveryoneProvider')]
	public function testGetForScoreServesOtherScoresToNonEditors(string $publicationStatus): void {
		$score = $this->createScore(7, 42, 'librarian');
		$score->setPublicationStatus($publicationStatus);
		$this->scoreMapper->method('find')->with(7)->willReturn($score);
		$this->giveAccess('librarian', 42, [$this->createFile('linked.musicxml', self::VALID_XML)]);

		$this->assertSame(self::VALID_XML, $this->createServiceWithRealPolicy(false)->getForScore(7)['content']);
	}

	public static function servedToEveryoneProvider(): array {
		return [
			'approved' => ['approved'],
			'to clarify' => ['to_clarify'],
			'blocked' => ['blocked'],
		];
	}
}
