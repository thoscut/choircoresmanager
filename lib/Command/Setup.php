<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Command;

use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ArchiveAccess;
use OCA\Notenarchiv\Service\Roles;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Setup without UI: archive folder and role groups. For instances with a
 * command line; managed instances do the same under Administration → Music
 * archive. With an archive folder it ends with the access check.
 */
class Setup extends Command {
	public function __construct(
		private AppSettings $settings,
		private Roles $roles,
		private IRootFolder $rootFolder,
		private ArchiveAccess $access,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('notenarchiv:setup')
			->setDescription('Set the archive folder and the role groups of the music archive')
			->addArgument('user', InputArgument::REQUIRED, 'account whose view of the archive folder is used')
			->addArgument('folder', InputArgument::OPTIONAL, 'archive folder, relative to the account\'s files')
			->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'role=group1,group2 (roles: ' . implode(', ', Roles::GROUP_ROLES) . ')');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		$path = self::text($input->getArgument('folder'));
		if ($path !== null && $path !== '') {
			$node = $this->rootFolder->getUserFolder($uid)->get($path);
			if (!$node instanceof Folder) {
				$output->writeln('<error>Not a folder: ' . $path . '</error>');
				return 1;
			}
			$set = $this->settings->setArchiveFolder($uid, $node->getId());
			$output->writeln('Archive folder: ' . $set . ' (id ' . $node->getId() . ')');
		}
		foreach (self::texts($input->getOption('role')) as $spec) {
			[$role, $groups] = array_pad(explode('=', $spec, 2), 2, '');
			$list = array_values(array_filter(array_map('trim', explode(',', $groups)), fn (string $group): bool => $group !== ''));
			$this->roles->setGroups($role, $list);
			$output->writeln('Role ' . $role . ': ' . ($list === [] ? '(no group)' : implode(', ', $list)));
		}
		if ($this->settings->archiveInfo()['id'] > 0) {
			$this->reportAccess($uid, $output);
		}
		return 0;
	}

	/**
	 * A short hint when accounts of the role groups cannot open the archive
	 * folder: the app reads files with the permissions of each account, so
	 * they would see the catalog but no files. Only reads.
	 */
	private function reportAccess(string $uid, OutputInterface $output): void {
		$report = $this->access->report($uid);
		/** @var array<string, list<string>> $missing uid => groups */
		$missing = [];
		foreach ($report['roles'] as $role) {
			foreach ($role['groups'] as $group) {
				if (!$group['exists']) {
					$output->writeln('<comment>The group ' . $group['id'] . ' of the role ' . $role['role'] . ' does not exist.</comment>');
				}
				foreach ($group['cannotOpen'] as $account) {
					$missing[$account['uid']][] = $group['id'];
				}
			}
		}
		if ($missing === []) {
			$output->writeln('Access check: every checked account of the role groups can open the archive folder (' . $report['checked'] . ' checked).');
		} else {
			$output->writeln('<comment>These accounts of the role groups cannot open the archive folder, so the app shows them no files:</comment>');
			foreach ($missing as $account => $groups) {
				$output->writeln('  ' . $account . ' (' . implode(', ', array_unique($groups)) . ')');
			}
			$output->writeln('<comment>Share the archive folder with the groups of the roles (reading is enough) or use a team folder or group folder.</comment>');
		}
		if (!$report['complete']) {
			$output->writeln('<comment>Not every account was checked (at most ' . $report['limit'] . ').</comment>');
		}
	}

	private static function text(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/**
	 * @return list<string>
	 */
	private static function texts(mixed $value): array {
		$texts = [];
		if (is_array($value)) {
			foreach (array_keys($value) as $key) {
				$text = self::text($value[$key]);
				if ($text !== null) {
					$texts[] = $text;
				}
			}
		}
		return $texts;
	}
}
