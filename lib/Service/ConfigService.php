<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCP\AppFramework\Services\IAppConfig;

class ConfigService {
	public const DEFAULT_REPERTOIRE_CURRENT_YEARS = 3;
	public const DEFAULT_REPERTOIRE_DORMANT_YEARS = 10;

	private IAppConfig $appConfig;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		IAppConfig $appConfig,
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
	 * Get the number of years within which a performed score counts as current repertoire
	 *
	 * @return int
	 */
	public function getRepertoireCurrentYears(): int {
		return $this->appConfig->getAppValueInt('repertoire_current_years', self::DEFAULT_REPERTOIRE_CURRENT_YEARS);
	}

	/**
	 * Get the number of years within which a performed score counts as dormant repertoire
	 *
	 * @return int
	 */
	public function getRepertoireDormantYears(): int {
		return $this->appConfig->getAppValueInt('repertoire_dormant_years', self::DEFAULT_REPERTOIRE_DORMANT_YEARS);
	}

	/**
	 * Set the repertoire class thresholds
	 *
	 * @param int $currentYears years within which a score counts as current
	 * @param int $dormantYears years within which a score counts as dormant
	 * @throws \InvalidArgumentException if the thresholds are not positive or not ascending
	 */
	public function setRepertoireThresholds(int $currentYears, int $dormantYears): void {
		if ($currentYears < 1 || $dormantYears < $currentYears) {
			throw new \InvalidArgumentException('Thresholds must be positive and the dormant threshold must not be smaller than the current threshold');
		}
		$this->appConfig->setAppValueInt('repertoire_current_years', $currentYears);
		$this->appConfig->setAppValueInt('repertoire_dormant_years', $dormantYears);
	}
}
