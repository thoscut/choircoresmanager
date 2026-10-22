<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Service\ConfigService;
use OCA\ChoirScores\Service\MusicXmlService;
use OCA\ChoirScores\Service\PublicScoreService;
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

	private function createPublishableScore(int $id, string $title): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle($title);
		$score->setRightsStatus('public_domain');
		$score->setRightsConfirmedAt(new \DateTimeImmutable('2026-10-01'));
		$score->setPublicationStatus('approved');
		return $score;
	}

	public function testGetPublicScoresUsesPublishableQueryAndWhitelistsFields(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = $this->createPublishableScore(1, 'Ave verum');
		$score->setComposer('Mozart');
		$score->setDefects('torn page');
		$score->setGemaIds(['123']);
		$score->setRightsNote('internal note');
		$score->setRightsConfirmedBy('TS');
		$score->setPublicationNote('internal publication note');
		$score->setMusicxmlFile('ave.musicxml');
		$score->setUpdatedAt(new \DateTimeImmutable('2026-10-15 23:30:00', new \DateTimeZone('UTC')));

		$this->scoreMapper->expects($this->once())
			->method('findPublishable')
			->willReturn([$score]);
		$this->musicXmlService->method('getFileMTime')
			->with($score)
			->willReturn((new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC')))->getTimestamp());

		$result = $this->service->getPublicScores();

		$this->assertSame([[
			'id' => 1,
			'title' => 'Ave verum',
			'composer' => 'Mozart',
			'arranger' => null,
			'year' => null,
			'duration' => null,
			'hasMusicXml' => true,
			'lastUpdated' => '2026-10-20',
		]], $result);
	}

	public function testLastUpdatedUsesScoreChangeIfLaterThanFile(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = $this->createPublishableScore(1, 'Ave verum');
		$score->setUpdatedAt(new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC')));
		$this->scoreMapper->method('findPublishable')->willReturn([$score]);
		$this->musicXmlService->method('getFileMTime')
			->willReturn((new \DateTimeImmutable('2025-01-01 08:00:00', new \DateTimeZone('UTC')))->getTimestamp());

		$this->assertSame('2026-10-21', $this->service->getPublicScores()[0]['lastUpdated']);
	}

	public function testScoreWithoutFileAndChangeDateHasNoMusicXmlAndNoDate(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = $this->createPublishableScore(2, 'Locus iste');
		$this->scoreMapper->method('findPublishable')->willReturn([$score]);
		$this->musicXmlService->method('getFileMTime')->willReturn(null);

		$result = $this->service->getPublicScores()[0];

		$this->assertFalse($result['hasMusicXml']);
		$this->assertNull($result['lastUpdated']);
	}

	public function testGetPublicScoresFailsWhenDisabled(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(false);
		$this->scoreMapper->expects($this->never())->method('findPublishable');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicScores();
	}

	public function testGetPublicMusicXmlUsesPublishableQuery(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);

		$score = $this->createPublishableScore(3, 'Ave verum');
		$this->scoreMapper->expects($this->once())
			->method('findPublishableById')
			->with(3)
			->willReturn($score);

		$file = ['content' => 'x', 'mimeType' => MusicXmlService::MIME_TYPE_XML, 'fileName' => 'score-3.musicxml'];
		$this->musicXmlService->expects($this->once())->method('readFile')->with($score)->willReturn($file);

		$this->assertSame($file, $this->service->getPublicMusicXml(3));
	}

	public function testGetPublicMusicXmlFailsForScoreThatMayNotBeShown(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(true);
		$this->scoreMapper->method('findPublishableById')
			->willThrowException(new DoesNotExistException('not found'));
		$this->musicXmlService->expects($this->never())->method('readFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicMusicXml(3);
	}

	public function testGetPublicMusicXmlFailsWhenDisabled(): void {
		$this->configService->method('isPublicPageEnabled')->willReturn(false);
		$this->scoreMapper->expects($this->never())->method('findPublishableById');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getPublicMusicXml(3);
	}
}
