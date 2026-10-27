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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Erasure for one person (Art. 17 GDPR); a dry run unless --execute is given.
 */
class PrivacyErase extends Command {
	public function __construct(
		private PrivacyService $privacy,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('notenarchiv:privacy-erase')
			->setDescription('Remove an account or a name from the folder book and the audit log (erasure, Art. 17 GDPR)')
			->addArgument('who', InputArgument::REQUIRED, 'Nextcloud account or name as written in the folder book')
			->addOption('execute', null, InputOption::VALUE_NONE, 'erase; without it only shows what would be erased');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$who = (string)$input->getArgument('who');
		if (!$input->getOption('execute')) {
			$report = $this->privacy->report($who);
			$output->writeln(sprintf('Would anonymise %d loans and remove the name from %d audit entries. Run again with --execute.',
				count($report['loans']), count($report['auditEntries'])));
			if ($report['persons'] !== []) {
				$output->writeln(sprintf('%d persons of that name in the register of persons are not changed; remove their life dates in the app if needed.',
					count($report['persons'])));
			}
			return 0;
		}
		$result = $this->privacy->erase($who);
		$output->writeln(sprintf('Anonymised %d loans and %d audit entries.', $result['loans'], $result['auditEntries']));
		if ($result['persons'] > 0) {
			$output->writeln(sprintf('%d persons of that name in the register of persons were not changed.', $result['persons']));
		}
		return 0;
	}
}
