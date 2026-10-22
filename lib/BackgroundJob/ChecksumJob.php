<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\BackgroundJob;

use OCA\Notenarchiv\Service\ChecksumService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly checksum check of the archive files, a limited number of files per
 * run (see ChecksumService). Detects silent corruption and notifies the
 * catalog librarian.
 */
class ChecksumJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ChecksumService $checksums,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$result = $this->checksums->run();
		if ($result['mismatches'] > 0) {
			$this->logger->warning('Notenarchiv: checksum differences found: ' . json_encode($result, JSON_THROW_ON_ERROR));
		} elseif ($result['checked'] > 0) {
			$this->logger->debug('Notenarchiv: checksums checked: ' . json_encode($result, JSON_THROW_ON_ERROR));
		}
	}
}
