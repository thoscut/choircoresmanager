<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Controller;

use OCA\OrchestraScoresManager\Controller\MusicXmlApiController;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MusicXmlApiController.
 */
final class MusicXmlApiControllerTest extends TestCase {
	private MusicXmlService $musicXmlService;
	private MusicXmlApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->musicXmlService = $this->createMock(MusicXmlService::class);

		$this->controller = new MusicXmlApiController(
			'orchestrascoresmanager',
			$this->createMock(IRequest::class),
			$this->musicXmlService,
		);
	}

	public function testGetMusicXmlOfPreservationCopyIsForbiddenForNonEditors(): void {
		$this->musicXmlService->method('getForScore')
			->with(6)
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(OCSForbiddenException::class);
		$this->controller->getMusicXml(6);
	}

	public function testGetMusicXmlReturnsNotFoundForMissingFile(): void {
		$this->musicXmlService->method('getForScore')
			->willThrowException(new NotFoundException('The linked MusicXML file is missing or no longer accessible.'));

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('missing or no longer accessible');
		$this->controller->getMusicXml(6);
	}

	public function testPostMusicXmlLinksTheFile(): void {
		$score = new Score();
		$score->setId(3);
		$score->setTitle('Ave verum');
		$score->setMusicxmlFile('ave.musicxml');
		$score->setMusicxmlFileId(42);
		$score->setMusicxmlLinkedBy('librarian');

		$this->musicXmlService->expects($this->once())
			->method('linkForScore')
			->with(3, 42)
			->willReturn($score);

		$data = $this->controller->postMusicXml(3, 42)->getData();

		$this->assertSame(42, $data['musicxmlFileId']);
		$this->assertSame('ave.musicxml', $data['musicxmlFile']);
		$this->assertSame('Ave verum', $data['title']);
		$this->assertArrayNotHasKey('musicxmlLinkedBy', $data);
	}

	public function testPostMusicXmlRejectsInvalidFile(): void {
		$this->musicXmlService->method('linkForScore')
			->willThrowException(new \InvalidArgumentException('The file is not a valid MusicXML file'));

		$this->expectException(OCSBadRequestException::class);
		$this->controller->postMusicXml(3, 42);
	}

	public function testDeleteMusicXmlOnlyUnlinks(): void {
		$score = new Score();
		$score->setId(3);
		$this->musicXmlService->expects($this->once())->method('unlinkForScore')->with(3)->willReturn($score);

		$data = $this->controller->deleteMusicXml(3)->getData();

		$this->assertNull($data['musicxmlFileId']);
	}
}
