<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCA\OrchestraScoresManager\Db\Enum\RightsStatus;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;

/**
 * Read-only access to scores for anonymous visitors.
 *
 * This service deliberately does not reuse the other services or policies, which
 * allow reading for everyone. It only ever exposes scores whose rights status is
 * "free" (filtered in SQL), only while the public page is enabled, and only a
 * whitelisted set of fields.
 *
 * @psalm-type OrchestraScoresManagerPublicScore = array{
 *     id: int,
 *     title: string,
 *     composer: string|null,
 *     arranger: string|null,
 *     year: int|null,
 *     duration: int|null,
 *     hasMusicXml: bool
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
	 * Get all freely available scores.
	 *
	 * @return list<OrchestraScoresManagerPublicScore>
	 * @throws \InvalidArgumentException if the public page is disabled
	 * @throws Exception
	 */
	public function getPublicScores(): array {
		$this->assertEnabled();
		return array_values(array_map(
			fn (Score $score) => self::toPublicArray($score),
			$this->scoreMapper->findByRightsStatus(RightsStatus::FREE->value),
		));
	}

	/**
	 * Get the MusicXML file of a freely available score.
	 *
	 * @param int $id
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the public page is disabled, the score is not free or has no file
	 */
	public function getPublicMusicXml(int $id): array {
		$this->assertEnabled();
		try {
			$score = $this->scoreMapper->findByIdAndRightsStatus($id, RightsStatus::FREE->value);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			throw new \InvalidArgumentException('Score not found');
		}
		return $this->musicXmlService->readFile($score);
	}

	/**
	 * Convert a score to the fields that may be shown publicly.
	 *
	 * @return OrchestraScoresManagerPublicScore
	 */
	public static function toPublicArray(Score $score): array {
		return [
			'id' => $score->getId(),
			'title' => $score->getTitle(),
			'composer' => $score->getComposer(),
			'arranger' => $score->getArranger(),
			'year' => $score->getYear(),
			'duration' => $score->getDuration(),
			'hasMusicXml' => $score->getMusicxmlFile() !== null,
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
