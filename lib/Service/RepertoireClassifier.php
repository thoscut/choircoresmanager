<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * Derives the repertoire class of a score from the date of its last performance.
 *
 * The class is never stored. "not_documented" means that no performance is on record,
 * not that the score was never sung.
 */
class RepertoireClassifier {
	public const CLASS_CURRENT = 'current';
	public const CLASS_DORMANT = 'dormant';
	public const CLASS_EARLIER = 'earlier';
	public const CLASS_NOT_DOCUMENTED = 'not_documented';
	public const CLASSES = [
		self::CLASS_CURRENT,
		self::CLASS_DORMANT,
		self::CLASS_EARLIER,
		self::CLASS_NOT_DOCUMENTED,
	];

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly ConfigService $configService,
		private readonly ITimeFactory $timeFactory,
		private readonly IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * Convert a point in time to a calendar date (Y-m-d) in the default timezone of the instance.
	 *
	 * @param \DateTimeImmutable $dateTime
	 * @return string
	 */
	public function toLocalDate(\DateTimeImmutable $dateTime): string {
		return $dateTime->setTimezone($this->dateTimeZone->getDefaultTimeZone())->format('Y-m-d');
	}

	/**
	 * Classify a score by the date of its last performance, using the configured thresholds.
	 *
	 * @param string|null $lastPerformed Date of the last performance (Y-m-d) or null if none is documented
	 * @return self::CLASS_*
	 */
	public function classify(?string $lastPerformed): string {
		$thresholds = $this->configService->getRepertoireThresholds();
		$currentYear = (int)$this->timeFactory->now()
			->setTimezone($this->dateTimeZone->getDefaultTimeZone())
			->format('Y');
		return self::classifyForYear($lastPerformed, $currentYear, $thresholds['currentYears'], $thresholds['dormantYears']);
	}

	/**
	 * Classify a score by the date of its last performance.
	 *
	 * current: year of last performance >= currentYear - currentYears
	 * dormant: year of last performance >= currentYear - dormantYears
	 * earlier: any older performance
	 * not_documented: no performance on record
	 *
	 * @param string|null $lastPerformed Date of the last performance (Y-m-d) or null
	 * @param int $currentYear The current calendar year
	 * @param int $currentYears Threshold for current repertoire in years
	 * @param int $dormantYears Threshold for dormant repertoire in years
	 * @return self::CLASS_*
	 */
	public static function classifyForYear(?string $lastPerformed, int $currentYear, int $currentYears, int $dormantYears): string {
		if ($lastPerformed === null || preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $lastPerformed, $matches) !== 1) {
			return self::CLASS_NOT_DOCUMENTED;
		}
		$year = (int)$matches[1];
		if ($year >= $currentYear - $currentYears) {
			return self::CLASS_CURRENT;
		}
		if ($year >= $currentYear - $dormantYears) {
			return self::CLASS_DORMANT;
		}
		return self::CLASS_EARLIER;
	}
}
