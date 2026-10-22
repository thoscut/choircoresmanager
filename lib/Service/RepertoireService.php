<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventItemMapper;
use OCA\Notenarchiv\Db\EventMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Repertoire figures derived from documented performances instead of being
 * maintained by hand: number of performance days, first and last date, and a
 * class relative to today:
 * - `current`: performed within the last N years (setting, default 3)
 * - `dormant`: performed within the last M years (setting, default 10)
 * - `earlier`: performed before that
 * - `none`: no documented performance (which does not mean "never sung")
 *
 * Song lists are reported separately: a song list is no proof of performance.
 */
class RepertoireService {
	public const DEFAULT_CURRENT_YEARS = 3;
	public const DEFAULT_DORMANT_YEARS = 10;

	public function __construct(
		private EventMapper $events,
		private EventItemMapper $items,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{current: int, dormant: int}
	 */
	public function thresholds(): array {
		return [
			'current' => $this->appConfig->getValueInt(Application::APP_ID, 'repertoire_current_years', self::DEFAULT_CURRENT_YEARS),
			'dormant' => $this->appConfig->getValueInt(Application::APP_ID, 'repertoire_dormant_years', self::DEFAULT_DORMANT_YEARS),
		];
	}

	public function setThresholds(int $current, int $dormant): void {
		$current = max(1, min($current, 50));
		$dormant = max($current, min($dormant, 100));
		$this->appConfig->setValueInt(Application::APP_ID, 'repertoire_current_years', $current);
		$this->appConfig->setValueInt(Application::APP_ID, 'repertoire_dormant_years', $dormant);
	}

	/**
	 * @return array<int, array{performances: int, first: ?string, last: ?string, class: string, songLists: list<string>}> keyed by work id
	 */
	public function perWork(): array {
		$events = $this->events->findAllById();
		$dates = [];
		$songLists = [];
		foreach ($this->items->findAll() as $item) {
			$workId = $item->getWorkId();
			$event = $events[$item->getEventId()] ?? null;
			if ($workId === null || $event === null || $item->getAssignment() !== 'certain') {
				continue;
			}
			if ($event->getKind() === Event::SONG_LIST) {
				$period = $event->getPeriod();
				if ($period !== null) {
					$songLists[$workId][$period] = true;
				}
				continue;
			}
			$date = $event->getEventDate();
			if ($event->getStatus() === 'sung' && $item->getCounts() === true && $date !== null) {
				$dates[$workId][$date] = true;
			}
		}

		$thresholds = $this->thresholds();
		$year = (int)$this->time->getDateTime()->format('Y');
		$result = [];
		foreach (array_unique(array_merge(array_keys($dates), array_keys($songLists))) as $workId) {
			// Array keys like "2003" (a year only) become integers in PHP; cast back.
			$list = array_map('strval', array_keys($dates[$workId] ?? []));
			sort($list);
			$last = $list === [] ? null : end($list);
			$lastYear = $last === null ? null : (int)substr($last, 0, 4);
			$result[$workId] = [
				'performances' => count($list),
				'first' => $list[0] ?? null,
				'last' => $last,
				'class' => match (true) {
					$lastYear === null => 'none',
					$lastYear >= $year - $thresholds['current'] => 'current',
					$lastYear >= $year - $thresholds['dormant'] => 'dormant',
					default => 'earlier',
				},
				'songLists' => array_map('strval', array_keys($songLists[$workId] ?? [])),
			];
		}
		return $result;
	}
}
