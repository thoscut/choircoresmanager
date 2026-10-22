<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;

/**
 * Read-only access to scores for anonymous visitors.
 *
 * This service deliberately does not reuse the other services or policies, which
 * require a login. It only ever exposes scores that are approved for
 * publication, in the public domain or licensed, and whose rights status a person
 * has confirmed (filtered in SQL), only while the public page is enabled, and only
 * a whitelisted set of fields.
 *
 * @psalm-type OrchestraScoresManagerPublicScore = array{
 *     id: int,
 *     title: string,
 *     composer: string|null,
 *     arranger: string|null,
 *     year: int|null,
 *     duration: int|null,
 *     hasMusicXml: bool,
 *     lastUpdated: string|null
 * }
 */
class PublicScoreService {
	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly ScoreMapper $scoreMapper,
		private readonly ConfigService $configService,
		private readonly MusicXmlService $musicXmlService,
	) {
	}

	public function isEnabled(): bool {
		return $this->configService->isPublicPageEnabled();
	}

	/**
	 * Get all scores that may be shown publicly.
	 *
	 * @return list<OrchestraScoresManagerPublicScore>
	 * @throws \InvalidArgumentException if the public page is disabled
	 * @throws Exception
	 */
	public function getPublicScores(): array {
		$this->assertEnabled();
		return array_values(array_map(
			fn (Score $score) => $this->toPublicArray($score),
			$this->scoreMapper->findPublishable(),
		));
	}

	/**
	 * Get the MusicXML file of a score that may be shown publicly.
	 *
	 * @param int $id
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the public page is disabled or the score may not be shown
	 * @throws Exceptions\NotFoundException if the score has no readable, valid MusicXML file
	 */
	public function getPublicMusicXml(int $id): array {
		$this->assertEnabled();
		try {
			$score = $this->scoreMapper->findPublishableById($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			throw new \InvalidArgumentException('Score not found');
		}
		return $this->musicXmlService->readFile($score);
	}

	/**
	 * Convert a score to the fields that may be shown publicly.
	 *
	 * The date of the last update is the later one of the last change of the score
	 * and the last modification of its MusicXML file, as a Y-m-d date in UTC.
	 *
	 * @return OrchestraScoresManagerPublicScore
	 */
	private function toPublicArray(Score $score): array {
		$fileMTime = $this->musicXmlService->getFileMTime($score);
		$timestamps = array_filter([$score->getUpdatedAt()?->getTimestamp(), $fileMTime], fn (?int $value) => $value !== null);
		$lastUpdated = $timestamps === []
			? null
			: (new DateTimeImmutable('@' . max($timestamps)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

		return [
			'id' => $score->getId(),
			'title' => $score->getTitle(),
			'composer' => $score->getComposer(),
			'arranger' => $score->getArranger(),
			'year' => $score->getYear(),
			'duration' => $score->getDuration(),
			'hasMusicXml' => $fileMTime !== null,
			'lastUpdated' => $lastUpdated,
		];
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function assertEnabled(): void {
		if (!$this->isEnabled()) {
			throw new \InvalidArgumentException('Public page is disabled');
		}
	}
}
