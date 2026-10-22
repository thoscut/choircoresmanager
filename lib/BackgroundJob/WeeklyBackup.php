<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\BackgroundJob;

use OCA\Notenarchiv\Exception\UserFacingException;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ExportService;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Weekly backup of the catalog into the archive folder (extended CSV and ODS
 * with the audit log), so that the regular file backup of the archive also
 * holds the catalog. Failures are reported to the catalog librarians and
 * admins.
 */
class WeeklyBackup extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AppSettings $settings,
		private ExportService $export,
		private NotificationService $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$uid = $this->settings->archiveInfo()['user'];
		if ($uid === '') {
			return;
		}
		try {
			$paths = $this->export->storeBackup('weekly', $uid);
			$this->notifications->withdraw(NotificationService::EXPORT_FAILED, 'export', 'weekly');
			$this->logger->info('Notenarchiv: weekly backup stored: ' . implode(', ', $paths));
		} catch (\Throwable $e) {
			$this->logger->error('Notenarchiv: weekly backup failed: ' . $e->getMessage(), ['exception' => $e]);
			// Only messages meant for users go into the notification (translated
			// when shown); internal details stay in the log.
			$parameters = $e instanceof UserFacingException
				? ['error' => $e->getMessage(), 'errorParameters' => json_encode(array_map('strval', $e->getParameters()), JSON_THROW_ON_ERROR)]
				: [];
			$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::EXPORT_FAILED, 'export', 'weekly', $parameters, true);
		}
	}
}
