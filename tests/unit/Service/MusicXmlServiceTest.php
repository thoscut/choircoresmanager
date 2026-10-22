<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCA\OrchestraScoresManager\Service\AuthorizationService;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCA\OrchestraScoresManager\Service\PermissionService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MusicXmlService.
 */
final class MusicXmlServiceTest extends TestCase {
	private const VALID_XML = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">'
		. '<score-partwise version="4.0"><part-list/></score-partwise>';

	private IAppData $appData;
	private ISimpleFolder $folder;
	private ScoreMapper $scoreMapper;
	private AuthorizationService $authorizationService;
	private ScorePolicy $scorePolicy;
	private MusicXmlService $service;
	/** @var list<string> */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();

		$this->appData = $this->createMock(IAppData::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('musicxml')->willReturn($this->folder);
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->scorePolicy = $this->createMock(ScorePolicy::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new MusicXmlService(
			$this->appData,
			$this->scoreMapper,
			$this->authorizationService,
			$this->scorePolicy,
			$l10n,
		);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $file) {
			@unlink($file);
		}
		parent::tearDown();
	}

	private function createTmpFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'osm-test');
		file_put_contents($path, $content);
		$this->tmpFiles[] = $path;
		return $path;
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function createZip(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'osm-test');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $path;
	}

	private function createScore(int $id = 1, ?string $musicxmlFile = null): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle('Test');
		$score->setMusicxmlFile($musicxmlFile);
		$score->resetUpdatedFields();
		return $score;
	}

	public function testUploadStoresFileUnderScoreIdAndKeepsDisplayName(): void {
		$score = $this->createScore(7);
		$this->scoreMapper->method('find')->with(7)->willReturn($score);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$this->folder->method('getFile')->with('score-7')->willThrowException(new NotFoundException());
		$this->folder->expects($this->once())
			->method('newFile')
			->with('score-7', self::VALID_XML);

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getMusicxmlFile() === 'Ave Maria.musicxml'));

		$this->service->uploadForScore(7, '../../etc/Ave Maria.musicxml', $this->createTmpFile(self::VALID_XML));
	}

	public function testUploadReplacesExistingFile(): void {
		$score = $this->createScore(3, 'old.musicxml');
		$this->scoreMapper->method('find')->willReturn($score);

		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('putContent')->with(self::VALID_XML);
		$this->folder->method('getFile')->with('score-3')->willReturn($file);
		$this->folder->expects($this->never())->method('newFile');

		$this->service->uploadForScore(3, 'new.xml', $this->createTmpFile(self::VALID_XML));
	}

	public function testUploadAcceptsCompressedMusicXml(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->folder->expects($this->once())->method('newFile');

		$path = $this->createZip([
			'META-INF/container.xml' => '<container/>',
			'score.musicxml' => self::VALID_XML,
		]);
		$this->service->uploadForScore(1, 'score.mxl', $path);
	}

	public function testUploadRequiresUpdatePermission(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->authorizationService->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(PermissionDeniedException::class);
		$this->service->uploadForScore(1, 'score.musicxml', $this->createTmpFile(self::VALID_XML));
	}

	public static function invalidUploadProvider(): array {
		return [
			'wrong extension' => ['score.pdf', self::VALID_XML, 'Only MusicXML files'],
			'svg disguised as musicxml' => ['score.musicxml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'not a valid MusicXML'],
			'html' => ['score.xml', '<html><body>hi</body></html>', 'not a valid MusicXML'],
			'entity declaration' => [
				'score.musicxml',
				'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><score-partwise>&xxe;</score-partwise>',
				'entity declarations',
			],
			'empty' => ['score.musicxml', '', 'empty'],
		];
	}

	#[DataProvider('invalidUploadProvider')]
	public function testUploadRejectsInvalidFiles(string $name, string $content, string $message): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->uploadForScore(1, $name, $this->createTmpFile($content));
	}

	public function testUploadRejectsZipWithoutScore(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->uploadForScore(1, 'score.mxl', $this->createZip(['image.png' => 'x']));
	}

	public function testUploadRejectsZipBomb(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->folder->expects($this->never())->method('newFile');

		// highly compressible entries summing up to more than the uncompressed size limit
		$chunkSize = 1024 * 1024;
		$chunkFile = $this->createTmpFile(str_repeat('a', $chunkSize));
		$path = tempnam(sys_get_temp_dir(), 'osm-test');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('score.musicxml', self::VALID_XML);
		for ($i = 0; $i <= MusicXmlService::MAX_UNCOMPRESSED_SIZE / $chunkSize; $i++) {
			$zip->addFile($chunkFile, "part$i.xml");
		}
		$zip->close();
		$this->assertLessThan(MusicXmlService::MAX_UPLOAD_SIZE, filesize($path));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('too large when uncompressed');
		$this->service->uploadForScore(1, 'score.mxl', $path);
	}

	public function testUploadRejectsUnknownScore(): void {
		$this->scoreMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Score not found');
		$this->service->uploadForScore(99, 'score.musicxml', $this->createTmpFile(self::VALID_XML));
	}

	public function testReadFileReturnsXmlWithMimeType(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(self::VALID_XML);
		$this->folder->method('getFile')->with('score-5')->willReturn($file);

		$result = $this->service->readFile($this->createScore(5, 'x.musicxml'));

		$this->assertSame(self::VALID_XML, $result['content']);
		$this->assertSame(MusicXmlService::MIME_TYPE_XML, $result['mimeType']);
		$this->assertSame('score-5.musicxml', $result['fileName']);
	}

	public function testReadFileDetectsCompressedFile(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn("PK\x03\x04rest");
		$this->folder->method('getFile')->willReturn($file);

		$result = $this->service->readFile($this->createScore(5, 'x.mxl'));

		$this->assertSame(MusicXmlService::MIME_TYPE_COMPRESSED, $result['mimeType']);
		$this->assertSame('score-5.mxl', $result['fileName']);
	}

	public function testReadFileFailsWithoutFile(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->readFile($this->createScore(5));
	}

	public function testDeleteRemovesFileAndClearsName(): void {
		$score = $this->createScore(4, 'x.musicxml');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('delete');
		$this->folder->method('getFile')->with('score-4')->willReturn($file);

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getMusicxmlFile() === null));

		$this->service->deleteForScore(4);
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
		$permissionService->method('canCurrentUserEdit')->willReturn($canEdit);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new MusicXmlService(
			$this->appData,
			$this->scoreMapper,
			new AuthorizationService($this->createMock(LoggerInterface::class), 'orchestrascoresmanager', $l10n),
			new ScorePolicy($permissionService),
			$l10n,
		);
	}

	private function createPreservationCopy(): Score {
		$score = $this->createScore(6, 'protected.mxl');
		$score->setPublicationStatus('internal_preservation_copy');
		return $score;
	}

	public function testGetForScoreDeniesPreservationCopyToNonEditors(): void {
		$this->scoreMapper->method('find')->with(6)->willReturn($this->createPreservationCopy());
		$this->folder->expects($this->never())->method('getFile');

		$this->expectException(PermissionDeniedException::class);
		$this->createServiceWithRealPolicy(false)->getForScore(6);
	}

	public function testGetForScoreServesPreservationCopyToEditors(): void {
		$this->scoreMapper->method('find')->with(6)->willReturn($this->createPreservationCopy());
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(self::VALID_XML);
		$this->folder->method('getFile')->with('score-6')->willReturn($file);

		$this->assertSame(self::VALID_XML, $this->createServiceWithRealPolicy(true)->getForScore(6)['content']);
	}

	#[DataProvider('servedToEveryoneProvider')]
	public function testGetForScoreServesOtherScoresToNonEditors(string $publicationStatus): void {
		$score = $this->createScore(7, 'score.musicxml');
		$score->setPublicationStatus($publicationStatus);
		$this->scoreMapper->method('find')->with(7)->willReturn($score);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(self::VALID_XML);
		$this->folder->method('getFile')->with('score-7')->willReturn($file);

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
