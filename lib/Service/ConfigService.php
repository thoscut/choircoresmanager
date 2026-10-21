<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;

class ConfigService {
	/** Default number of years within which a performance counts as current repertoire */
	public const DEFAULT_REPERTOIRE_CURRENT_YEARS = 3;
	/** Default number of years within which a performance counts as dormant repertoire */
	public const DEFAULT_REPERTOIRE_DORMANT_YEARS = 10;
	/** Upper bound for both repertoire thresholds */
	public const MAX_REPERTOIRE_YEARS = 100;

	private IAppConfig $appConfig;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		IAppConfig $appConfig,
		private IL10N $l,
	) {
		$this->appConfig = $appConfig;
	}

	/**
	 * Get the list of groups allowed to edit the table
	 *
	 * @return list<string> List of group IDs
	 */
	public function getAllowedEditGroups(): array {
		/** @var list<string> */
		return $this->appConfig->getAppValueArray('allowed_edit_groups');
	}

	/**
	 * Set the list of groups allowed to edit the table
	 *
	 * @param array $groupIds List of group IDs
	 */
	public function setAllowedEditGroups(array $groupIds): void {
		// Remove empty values and duplicates, then reset array indices
		$groupIds = array_values(array_filter(array_unique($groupIds)));
		$this->appConfig->setAppValueArray('allowed_edit_groups', $groupIds);
	}

	/**
	 * Check if a specific group is allowed to edit
	 *
	 * @param string $groupId Group ID to check
	 * @return bool True if the group is allowed to edit
	 * @psalm-suppress PossiblyUnusedMethod - May be used in future
	 */
	public function isGroupAllowedToEdit(string $groupId): bool {
		$allowedGroups = $this->getAllowedEditGroups();
		return in_array($groupId, $allowedGroups, true);
	}

	/**
	 * Get the thresholds (in years) used to derive the repertoire class of a score.
	 *
	 * A score is current if its last performance lies at most currentYears calendar years back,
	 * dormant if it lies at most dormantYears back, and earlier otherwise.
	 * Invalid stored values (e.g. set via occ) fall back to the defaults.
	 *
	 * @return array{currentYears: int, dormantYears: int}
	 */
	public function getRepertoireThresholds(): array {
		$currentYears = $this->appConfig->getAppValueInt('repertoire_current_years', self::DEFAULT_REPERTOIRE_CURRENT_YEARS);
		$dormantYears = $this->appConfig->getAppValueInt('repertoire_dormant_years', self::DEFAULT_REPERTOIRE_DORMANT_YEARS);
		if (!self::areValidRepertoireThresholds($currentYears, $dormantYears)) {
			return [
				'currentYears' => self::DEFAULT_REPERTOIRE_CURRENT_YEARS,
				'dormantYears' => self::DEFAULT_REPERTOIRE_DORMANT_YEARS,
			];
		}
		return ['currentYears' => $currentYears, 'dormantYears' => $dormantYears];
	}

	/**
	 * Set the thresholds (in years) used to derive the repertoire class of a score.
	 *
	 * @param int $currentYears Years within which a performance counts as current
	 * @param int $dormantYears Years within which a performance counts as dormant
	 * @throws \InvalidArgumentException if not 0 <= currentYears < dormantYears <= 100
	 */
	public function setRepertoireThresholds(int $currentYears, int $dormantYears): void {
		if (!self::areValidRepertoireThresholds($currentYears, $dormantYears)) {
			throw new \InvalidArgumentException($this->l->t(
				'The years for current repertoire must be at least 0 and less than the years for dormant repertoire, which must be at most %d.',
				[self::MAX_REPERTOIRE_YEARS],
			));
		}
		$this->appConfig->setAppValueInt('repertoire_current_years', $currentYears);
		$this->appConfig->setAppValueInt('repertoire_dormant_years', $dormantYears);
	}

	/**
	 * Check whether 0 <= currentYears < dormantYears <= MAX_REPERTOIRE_YEARS.
	 *
	 * @param int $currentYears
	 * @param int $dormantYears
	 */
	private static function areValidRepertoireThresholds(int $currentYears, int $dormantYears): bool {
		return $currentYears >= 0 && $currentYears < $dormantYears && $dormantYears <= self::MAX_REPERTOIRE_YEARS;
	}
}
