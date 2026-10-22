<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Command;

use OCA\Notenarchiv\Service\FileSyncService;
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
		$this->setName('notenarchiv:file-sync')
			->setDescription('Match the files in the archive folder against the catalog')
			->addArgument('user', InputArgument::REQUIRED, 'account whose view of the archive folder is used');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->fileSync->sync((string)$input->getArgument('user'));
		$output->writeln((string)json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return 0;
	}
}
