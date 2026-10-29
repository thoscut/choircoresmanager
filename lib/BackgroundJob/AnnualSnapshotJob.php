<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\BackgroundJob;

use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\RunInProgressException;
use OCA\Cantoria\Exception\UserFacingException;
use OCA\Cantoria\Service\AnnualSnapshotService;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Freezes the state of the archive at the turn of the year (module
 * "annual_snapshot", milestone 0.8): in January, once, a snapshot for the
 * year before, while the setting "at the turn of the year" is on. Written as
 * the account that set the archive folder. A failure is logged and shown in
 * the view of the snapshots; the job tries again the next day until the end
 * of January.
 */
class AnnualSnapshotJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Modules $modules,
		private AppSettings $settings,
		private AnnualSnapshotService $snapshots,
		private RightsService $rights,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->modules->isEnabled(Modules::ANNUAL_SNAPSHOT) || !$this->snapshots->isAuto()) {
			return;
		}
		$uid = $this->settings->archiveInfo()['user'];
		if ($uid === '') {
			return;
		}
		$today = $this->rights->today($uid);
		if (substr($today, 5, 2) !== '01') {
			return;
		}
		$year = (int)substr($today, 0, 4) - 1;
		try {
			if ($this->snapshots->hasYear($year)) {
				return;
			}
			$result = $this->snapshots->createAtTurnOfYear($uid, $year);
			if ($result === null) {
				return;
			}
			$this->snapshots->setLastError(null);
			$this->logger->info('Cantoria: annual snapshot ' . $result['name'] . ' stored');
		} catch (RunInProgressException) {
			// A snapshot by hand is being made; the job checks again in six hours.
			return;
		} catch (\Throwable $e) {
			$this->logger->error('Cantoria: annual snapshot failed: ' . $e->getMessage(), ['exception' => $e]);
			// Only messages meant for users are shown; internal details stay in the log.
			$shown = $e instanceof UserFacingException ? $e
				: new InvalidInputException('The automatic snapshot failed. Details are in the Nextcloud log; make a snapshot by hand or ask the administration.');
			$this->snapshots->setLastError($shown->getMessage(), array_map('strval', $shown->getParameters()));
		}
	}
}
