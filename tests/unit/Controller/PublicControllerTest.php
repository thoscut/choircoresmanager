<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Controller;

use OCA\ChoirScores\Controller\PublicController;
use OCA\ChoirScores\Service\Exceptions\NotFoundException;
use OCA\ChoirScores\Service\MusicXmlService;
use OCA\ChoirScores\Service\PublicScoreService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicController.
 */
final class PublicControllerTest extends TestCase {
	private PublicScoreService $publicScoreService;
	private IInitialState $initialState;
	private PublicController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->publicScoreService = $this->createMock(PublicScoreService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PublicController(
			'choirscores',
			$this->createMock(IRequest::class),
			$this->publicScoreService,
			$this->initialState,
			$l10n,
		);
	}

	/**
	 * Some response classes need the Nextcloud server, which is available in CI but not in a plain checkout.
	 */
	private function requireServer(): void {
		if (!class_exists(\OC\AppScriptDependency::class)) {
			$this->markTestSkipped('Requires a Nextcloud server installation');
		}
	}

	public function testIndexReturnsNotFoundWhenDisabled(): void {
		$this->publicScoreService->method('isEnabled')->willReturn(false);
		$this->publicScoreService->expects($this->never())->method('getPublicScores');
		$this->initialState->expects($this->never())->method('provideInitialState');

		$this->assertInstanceOf(NotFoundResponse::class, $this->controller->index());
	}

	public function testIndexProvidesPublicScores(): void {
		$this->requireServer();
		$scores = [['id' => 1, 'title' => 'A', 'composer' => null, 'arranger' => null, 'year' => null, 'duration' => null, 'hasMusicXml' => false, 'lastUpdated' => null]];
		$this->publicScoreService->method('isEnabled')->willReturn(true);
		$this->publicScoreService->method('getPublicScores')->willReturn($scores);
		$this->initialState->expects($this->once())
			->method('provideInitialState')
			->with('publicScores', $scores);

		$response = $this->controller->index();

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame('public', $response->getTemplateName());
		$this->assertStringContainsString("'wasm-unsafe-eval'", $response->getContentSecurityPolicy()->buildPolicy());
	}

	public function testMusicXmlReturnsNotFoundForUnavailableScore(): void {
		$this->publicScoreService->method('getPublicMusicXml')
			->willThrowException(new \InvalidArgumentException('Score not found'));

		$this->assertInstanceOf(NotFoundResponse::class, $this->controller->musicXml(5));
	}

	public function testMusicXmlReturnsNotFoundForMissingOrInaccessibleFile(): void {
		$this->publicScoreService->method('getPublicMusicXml')
			->willThrowException(new NotFoundException('The linked MusicXML file is missing or no longer accessible.'));

		$this->assertInstanceOf(NotFoundResponse::class, $this->controller->musicXml(5));
	}

	public function testMusicXmlReturnsDownloadWithSafeHeaders(): void {
		$this->requireServer();
		$this->publicScoreService->method('getPublicMusicXml')->with(5)->willReturn([
			'content' => '<score-partwise/>',
			'mimeType' => MusicXmlService::MIME_TYPE_XML,
			'fileName' => 'score-5.musicxml',
		]);

		$response = $this->controller->musicXml(5);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = $response->getHeaders();
		$this->assertSame(MusicXmlService::MIME_TYPE_XML, $headers['Content-Type']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertStringContainsString('attachment', $headers['Content-Disposition']);
	}
}
