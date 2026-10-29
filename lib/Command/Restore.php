<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Command;

use OCA\Cantoria\Service\FullBackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restores a complete JSON backup into an empty catalog. The file is read
 * from the local file system of the server, so it also works when the archive
 * folder itself is lost.
 */
class Restore extends Command {
	public function __construct(
		private FullBackupService $backup,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('cantoria:restore')
			->setDescription('Restore a complete backup (JSON) into an empty catalog')
			->addArgument('file', InputArgument::REQUIRED, 'path of the backup file on the server')
			->addArgument('user', InputArgument::OPTIONAL, 'account recorded in the audit log');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		$content = is_readable($path) ? file_get_contents($path) : false;
		if ($content === false) {
			$output->writeln('<error>Cannot read ' . $path . '</error>');
			return 1;
		}
		$restored = $this->backup->restore($content, (string)($input->getArgument('user') ?? 'occ'));
		foreach ($restored as $table => $rows) {
			$output->writeln(sprintf('%-28s %6d', $table, $rows));
		}
		return 0;
	}
}
