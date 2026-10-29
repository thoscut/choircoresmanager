<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Exception\RunInProgressException;
use OCA\Cantoria\Service\FileSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FileSync extends Command {
	public function __construct(
		private FileSyncService $fileSync,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:file-sync')
			->setDescription('Match the files in the archive folder against the catalog')
			->addArgument('user', InputArgument::REQUIRED, 'account whose view of the archive folder is used');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$report = $this->fileSync->sync((string)$input->getArgument('user'));
		} catch (RunInProgressException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln((string)json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
