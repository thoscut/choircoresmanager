<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Controller;

use OCA\OrchestraScoresManager\Controller\MusicXmlApiController;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
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
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new MusicXmlApiController(
			'orchestrascoresmanager',
			$this->createMock(IRequest::class),
			$this->musicXmlService,
			$l10n,
		);
	}

	public function testGetMusicXmlOfPreservationCopyIsForbiddenForNonEditors(): void {
		$this->musicXmlService->method('getForScore')
			->with(6)
			->willThrowException(new PermissionDeniedException('Insufficient permissions'));

		$this->expectException(OCSForbiddenException::class);
		$this->controller->getMusicXml(6);
	}
}
