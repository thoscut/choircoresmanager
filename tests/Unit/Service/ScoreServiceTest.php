<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\BoundedZip;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\UserFiles;
use OCA\Notenarchiv\Tests\Unit\Support\TempFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The score file for the viewer: only real MusicXML reaches the browser
 * (the viewer renders it as SVG inside the page), and only after the rights
 * policy and the user's own access to the file.
 */
#[CoversClass(ScoreService::class)]
#[CoversClass(BoundedZip::class)]
#[CoversClass(UserFiles::class)]
#[RequiresPhpExtension('zip')]
final class ScoreServiceTest extends TestCase {
	use TempFiles;

	private const PARTWISE = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">'
		. '<score-partwise version="4.0"><part-list><score-part id="P1"><part-name>Tenor</part-name></score-part></part-list>'
		. '<part id="P1"><measure number="1"/></part></score-partwise>';

	private const MEI = '<?xml version="1.0"?><mei xmlns="http://www.music-encoding.org/ns/mei"><music/></mei>';

	private const CONTAINER = '<?xml version="1.0" encoding="UTF-8"?><container><rootfiles>'
		. '<rootfile full-path="score.xml" media-type="application/vnd.recordare.musicxml+xml"/></rootfiles></container>';

	private File $file;
	private ?string $policyDenial = null;

	private function service(): ScoreService {
		$archiveFile = new ArchiveFile();
		$archiveFile->setId(7);
		$archiveFile->setCopyId(35);
		$archiveFile->setFileId(4711);
		$archiveFile->setName('5-01_Ave.musicxml');
		$archiveFile->setType('musicxml');
		$archiveFile->setRole('musicxml');
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('find')->willReturn($archiveFile);
		$copy = new Copy();
		$copy->setId(35);
		$copy->setVersionId(3);
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($copy);
		$version = new WorkVersion();
		$version->setId(3);
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($version);
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('viewDenial')->willReturnCallback(fn (): ?string => $this->policyDenial);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => $id === 4711 ? $this->file : null);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		return new ScoreService($files, $copies, $versions, $policy, $root, $this->tempManager());
	}

	private function fileWith(string $content, ?int $size = null): File {
		$file = $this->createStub(File::class);
		$file->method('getSize')->willReturn($size ?? strlen($content));
		$file->method('fopen')->willReturnCallback(function () use ($content) {
			$handle = fopen('php://memory', 'r+');
			self::assertIsResource($handle);
			fwrite($handle, $content);
			rewind($handle);
			return $handle;
		});
		$file->method('getName')->willReturn('5-01_Ave.musicxml');
		return $file;
	}

	public function testDeliversAMusicXmlScore(): void {
		$this->file = $this->fileWith(self::PARTWISE);
		$score = $this->service()->content(7, 'conductor');
		self::assertSame(self::PARTWISE, $score['content']);
		self::assertSame('application/vnd.recordare.musicxml+xml', $score['mime']);
	}

	public function testRightsPolicyComesFirst(): void {
		$this->file = $this->fileWith(self::PARTWISE);
		$this->policyDenial = 'Internal preservation copy: only the catalog librarian and the conductor may view it.';
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Internal preservation copy');
		$this->service()->content(7, 'reader');
	}

	public function testRefusesAFileThatOnlyPretendsToBeMusicXml(): void {
		$this->file = $this->fileWith(self::MEI);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('This file is not a MusicXML score.');
		$this->service()->content(7, 'conductor');
	}

	public function testRefusesAFileAboveTheLimit(): void {
		$this->file = $this->fileWith(self::PARTWISE, ScoreService::MAX_BYTES + 1);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is too large (at most %s MB).');
		$this->service()->content(7, 'conductor');
	}

	public function testReadsNoMoreThanTheLimitWhenTheRecordedSizeIsWrong(): void {
		$this->file = $this->fileWith(self::PARTWISE . str_repeat(' ', ScoreService::MAX_BYTES), 10);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is too large (at most %s MB).');
		$this->service()->content(7, 'conductor');
	}

	/**
	 * @return iterable<string, array{0: string, 1: bool}>
	 */
	public static function plainFiles(): iterable {
		yield 'partwise with public DTD' => [self::PARTWISE, true];
		yield 'timewise without DOCTYPE' => ['<score-timewise version="4.0"><part-list/></score-timewise>', true];
		yield 'UTF-8 byte order mark' => ["\xEF\xBB\xBF" . self::PARTWISE, true];
		yield 'UTF-16 with byte order mark' => ["\xFF\xFE" . (string)mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><score-partwise/>', 'UTF-16LE', 'UTF-8'), true];
		yield 'MEI' => [self::MEI, false];
		yield 'internal subset hiding another root' => ['<?xml version="1.0"?><!DOCTYPE mei [<!ENTITY x "]>">]><mei><score-partwise/></mei>', false];
		yield 'internal subset on a partwise score' => ['<!DOCTYPE score-partwise [<!ENTITY t "Tenor">]><score-partwise>&t;</score-partwise>', false];
		yield 'namespaced root' => ['<m:score-partwise xmlns:m="urn:x"/>', false];
		yield 'root in a namespace' => ['<score-partwise xmlns="http://www.music-encoding.org/ns/mei"/>', false];
		yield 'not well-formed' => ['<score-partwise><part></score-partwise>', false];
		yield 'HTML' => ['<html><body><script>alert(1)</script></body></html>', false];
		yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', false];
		yield 'ABC notation' => ["X:1\nT:Tune\nK:C\nCDEF|", false];
		yield 'empty' => ['', false];
	}

	#[DataProvider('plainFiles')]
	public function testPlainFiles(string $content, bool $isScore): void {
		self::assertSame($isScore, $this->service()->isScore($content));
	}

	/**
	 * @return iterable<string, array{0: array<string, string>, 1: bool}>
	 */
	public static function compressedFiles(): iterable {
		yield 'container and score' => [['META-INF/container.xml' => self::CONTAINER, 'score.xml' => self::PARTWISE], true];
		yield 'no container' => [['score.musicxml' => self::PARTWISE], true];
		yield 'further root file as PDF' => [['META-INF/container.xml' => str_replace('</rootfiles>', '<rootfile full-path="score.pdf" media-type="application/pdf"/></rootfiles>', self::CONTAINER),
			'score.xml' => self::PARTWISE, 'score.pdf' => '%PDF-1.7'], true];
		yield 'root file is MEI' => [['META-INF/container.xml' => self::CONTAINER, 'score.xml' => self::MEI], false];
		yield 'MEI next to the score' => [['META-INF/container.xml' => self::CONTAINER, 'score.xml' => self::PARTWISE, 'other.xml' => self::MEI], false];
		yield 'root file named with an entity' => [['META-INF/container.xml' => str_replace('score.xml', 'evil&#46;mei', self::CONTAINER),
			'evil.mei' => self::MEI, 'score.xml' => self::PARTWISE], false];
		yield 'no score at all' => [['META-INF/container.xml' => self::CONTAINER], false];
		yield 'named root file missing' => [['META-INF/container.xml' => str_replace('score.xml', 'other.mei', self::CONTAINER),
			'score.xml' => self::PARTWISE], false];
	}

	/**
	 * @param array<string, string> $entries
	 */
	#[DataProvider('compressedFiles')]
	public function testCompressedFiles(array $entries, bool $isScore): void {
		self::assertSame($isScore, $this->service()->isScore($this->zip($entries)));
	}

	public function testRefusesACompressedScoreThatUnpacksTooLarge(): void {
		$huge = str_replace('</score-partwise>', str_repeat(' ', ScoreService::MAX_BYTES) . '</score-partwise>', self::PARTWISE);
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('The file is too large (at most %s MB).');
		$this->service()->isScore($this->zip(['score.xml' => $huge]));
	}
}
