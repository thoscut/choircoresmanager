<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCA\OrchestraScoresManager\Service\AuthorizationService;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\PdfService;
use OCA\OrchestraScoresManager\Service\PermissionService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PdfService.
 */
final class PdfServiceTest extends TestCase {
	private const VALID_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";

	private ISimpleFolder $folder;
	private ScoreMapper $scoreMapper;
	private AuthorizationService $authorizationService;
	private ScorePolicy $scorePolicy;
	private PermissionService $permissionService;
	private PdfService $service;
	/** @var list<string> */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();

		$appData = $this->createMock(IAppData::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData->method('getFolder')->with('pdf')->willReturn($this->folder);
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->scorePolicy = $this->createMock(ScorePolicy::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new PdfService($appData, $this->scoreMapper, $this->authorizationService, $this->scorePolicy, $this->permissionService, $l10n);
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

	private function createScore(int $id = 1, ?string $pdfFile = null): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle('Test');
		$score->setPdfFile($pdfFile);
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
		$this->folder->expects($this->once())->method('newFile')->with('score-7', self::VALID_PDF);
		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getPdfFile() === 'Tenor 1.pdf'));

		$this->service->uploadForScore(7, '../Tenor 1.pdf', $this->createTmpFile(self::VALID_PDF));
	}

	public function testUploadRequiresUpdatePermission(): void {
		$this->scoreMapper->method('find')->willReturn($this->createScore());
		$this->authorizationService->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(PermissionDeniedException::class);
		$this->service->uploadForScore(1, 'score.pdf', $this->createTmpFile(self::VALID_PDF));
	}

	public static function invalidUploadProvider(): array {
		return [
			'wrong extension' => ['score.html', self::VALID_PDF, 'Only PDF files'],
			'html disguised as pdf' => ['score.pdf', '<html><script>alert(1)</script></html>', 'not a valid PDF'],
			'empty' => ['score.pdf', '', 'empty'],
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

	public function testReadFileReturnsPdf(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(self::VALID_PDF);
		$this->folder->method('getFile')->with('score-5')->willReturn($file);

		$result = $this->service->readFile($this->createScore(5, 'x.pdf'));

		$this->assertSame(['content' => self::VALID_PDF, 'mimeType' => 'application/pdf', 'fileName' => 'score-5.pdf'], $result);
	}

	public function testReadFileFailsWithoutFile(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->readFile($this->createScore(5));
	}

	public function testDeleteRemovesFileAndClearsName(): void {
		$score = $this->createScore(4, 'x.pdf');
		$this->scoreMapper->method('find')->willReturn($score);

		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('delete');
		$this->folder->method('getFile')->with('score-4')->willReturn($file);
		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Score $s) => $s->getPdfFile() === null));

		$this->service->deleteForScore(4);
	}

	public function testGetForScoreRefusesFilesWithoutFileAccess(): void {
		$score = $this->createScore(6, 'copy.pdf');
		$score->setPublicationStatus('internal_copy');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->permissionService->method('canAccessFiles')->with($score)->willReturn(false);
		$this->folder->expects($this->never())->method('getFile');

		$this->expectException(PermissionDeniedException::class);
		$this->service->getForScore(6);
	}

	public function testGetForScoreReadsFileWithFileAccess(): void {
		$score = $this->createScore(6, 'copy.pdf');
		$this->scoreMapper->method('find')->willReturn($score);
		$this->permissionService->method('canAccessFiles')->willReturn(true);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(self::VALID_PDF);
		$this->folder->method('getFile')->with('score-6')->willReturn($file);

		$this->assertSame(self::VALID_PDF, $this->service->getForScore(6)['content']);
	}
}
