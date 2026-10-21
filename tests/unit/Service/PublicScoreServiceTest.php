<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Service\ConfigService;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCA\OrchestraScoresManager\Service\PublicScoreService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicScoreService.
 */
final class PublicScoreServiceTest extends TestCase {
	private ScoreMapper $scoreMapper;
	private ConfigService $configService;
	private MusicXmlService $musicXmlService;
	private PublicScoreService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->musicXmlService = $this->createMock(MusicXmlService::class);

		$this->service = new PublicScoreService($this->scoreMapper, $this->configService, $this->musicXmlService);
	}

	public function testGetPublicScoresOnlyQueriesFreeScoresAndWhitelistsFields(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = new Score();
		$score->setId(1);
		$score->setTitle('Ave verum');
		$score->setComposer('Mozart');
		$score->setDefects('torn page');
		$score->setGemaIds(['123']);
		$score->setRightsStatus('free');
		$score->setRightsNote('internal note');
		$score->setMusicxmlFile('ave.musicxml');

		$this->scoreMapper->expects($this->once())
			->method('findByRightsStatus')
			->with('free')
			->willReturn([$score]);

		$result = $this->service->getPublicScores();

		$this->assertSame([[
			'id' => 1,
			'title' => 'Ave verum',
			'composer' => 'Mozart',
			'arranger' => null,
			'year' => null,
			'duration' => null,
			'hasMusicXml' => true,
		]], $result);
	}

	public function testGetPublicScoresFailsWhenDisabled(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(false);
		$this->scoreMapper->expects($this->never())->method('findByRightsStatus');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicScores();
	}

	public function testGetPublicMusicXmlChecksRightsStatusInQuery(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = new Score();
		$score->setId(3);
		$this->scoreMapper->expects($this->once())
			->method('findByIdAndRightsStatus')
			->with(3, 'free')
			->willReturn($score);

		$file = ['content' => 'x', 'mimeType' => MusicXmlService::MIME_TYPE_XML, 'fileName' => 'score-3.musicxml'];
		$this->musicXmlService->expects($this->once())->method('readFile')->with($score)->willReturn($file);

		$this->assertSame($file, $this->service->getPublicMusicXml(3));
	}

	public function testGetPublicMusicXmlFailsForNonFreeScore(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);
		$this->scoreMapper->method('findByIdAndRightsStatus')
			->willThrowException(new DoesNotExistException('not found'));
		$this->musicXmlService->expects($this->never())->method('readFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicMusicXml(3);
	}

	public function testGetPublicMusicXmlFailsWhenDisabled(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(false);
		$this->scoreMapper->expects($this->never())->method('findByIdAndRightsStatus');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicMusicXml(3);
	}
}
