<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Command;

use OCA\Notenarchiv\Service\PrivacyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Access for one person (Art. 15 GDPR) as JSON on standard output.
 */
class PrivacyReport extends Command {
	public function __construct(
		private PrivacyService $privacy,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('notenarchiv:privacy-report')
			->setDescription('Write what the music archive stores about an account or a name as JSON (access, Art. 15 GDPR)')
			->addArgument('who', InputArgument::REQUIRED, 'Nextcloud account or name as written in the folder book');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->privacy->report((string)$input->getArgument('who'));
		$output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
			OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
