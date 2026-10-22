<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\MirrorFile;
use OCA\Notenarchiv\Db\MirrorFileMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The mirror folder of the print station (milestone 0.7, module
 * "print_mirror"), the one place besides small corrections where the app
 * writes files (exception to guideline 4):
 *
 * - Only into a target folder of its own that an administrator chooses; it
 *   must be neither the archive folder nor inside it (checked when it is
 *   chosen and before every sync). The app reads and writes as the account
 *   that chose it.
 * - It holds the PDF scans of the versions the list of the print station
 *   shows (ReleaseListService, the same rule, no second one), never internal
 *   preservation copies, named `<shelf mark> <title>.pdf`, and a
 *   `LIESMICH.txt` with the date and a note.
 * - Missing files are copied, changed scans replaced, files no longer
 *   released removed. The manifest (table notarch_mirror_files: name, file
 *   id, SHA-256 of what was written) tells which files the app wrote: only
 *   those are replaced or removed, and only while their content is still
 *   the one written. Other files in the folder stay as they are.
 * - Every write and every removal is in the audit log. The daily checks
 *   sync once the first sync has been started by hand after the preview.
 *
 * Reasons for skipped files are keys, translated by the controller.
 *
 * @psalm-type MirrorEntry = array{name: string, shelfmark: ?string, reason: ?string}
 * @psalm-type MirrorReport = array{time: int, dryRun: bool, writable: bool, copied: list<MirrorEntry>, replaced: list<MirrorEntry>,
 *     removed: list<MirrorEntry>, skipped: list<MirrorEntry>, unchanged: int, files: int}
 */
class PrintMirrorService {
	public const MODULE = Modules::PRINT_MIRROR;
	public const README = 'LIESMICH.txt';
	public const OBJECT_TYPE = 'print_mirror';

	/** Why a file was skipped (keys; the controller translates them) */
	public const FOREIGN = 'foreign';
	public const CHANGED = 'changed';
	public const NOT_ACCESSIBLE = 'not_accessible';
	public const NOT_PDF = 'not_pdf';
	public const INVALID_NAME = 'invalid_name';
	public const PRESERVATION = 'preservation';
	public const WRITE_FAILED = 'write_failed';

	private const KEY_FOLDER_ID = 'print_mirror_folder_id';
	private const KEY_FOLDER_PATH = 'print_mirror_folder_path';
	private const KEY_USER = 'print_mirror_user';
	private const KEY_STARTED = 'print_mirror_started';
	private const KEY_REPORT = 'print_mirror_report';
	/** Longest file name without ".pdf" and a number */
	private const MAX_BASE = 120;
	/** Entries per list kept in the stored report */
	private const MAX_REPORT_ENTRIES = 500;

	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private AppSettings $settings,
		private ReleaseListService $releases,
		private ArchiveFileMapper $archiveFiles,
		private CopyMapper $copies,
		private MirrorFileMapper $mirrorFiles,
		private AuditLog $audit,
		private Roles $roles,
		private Modules $modules,
		private NotificationService $notifications,
		private IFilenameValidator $filenameValidator,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The target folder: Nextcloud file id, path as seen by the account that
	 * chose it, and that account (id 0: none chosen).
	 *
	 * @return array{id: int, path: string, user: string}
	 */
	public function info(): array {
		return [
			'id' => $this->appConfig->getValueInt(Application::APP_ID, self::KEY_FOLDER_ID, 0),
			'path' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_FOLDER_PATH, ''),
			'user' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_USER, ''),
		];
	}

	/**
	 * Checks a folder as target folder for the account without saving it.
	 *
	 * @return array{id: int, path: string, user: string}
	 * @throws InvalidInputException with what to do when it does not fit
	 */
	public function checkTargetFolder(string $uid, int $folderId): array {
		$this->roles->requireSettingsAccess($uid);
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $folderId > 0 ? $userFolder->getFirstNodeById($folderId) : null;
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The selected target folder is not a folder or not accessible. Please choose a folder.');
		}
		if ($this->settings->archiveInfo()['id'] === 0) {
			throw new InvalidInputException('Please choose the archive folder first: the print station copies the scans from it.');
		}
		if ($this->insideArchive($uid, $folder)) {
			throw new InvalidInputException('The target folder must be neither the archive folder nor inside it. Please choose a folder outside the archive folder, for example one shared with the print station.');
		}
		try {
			$this->settings->archiveFolder($uid);
		} catch (UserFacingException) {
			throw new InvalidInputException('Your account cannot read the archive folder. Please choose the target folder with an account that has access to the archive folder.');
		}
		if (!$folder->isCreatable() || !$folder->isUpdateable()) {
			throw new InvalidInputException('Your account may not create files in this folder. Please choose a folder where it may create, change and delete files.');
		}
		return ['id' => $folder->getId(), 'path' => $userFolder->getRelativePath($folder->getPath()) ?? $folder->getPath(), 'user' => $uid];
	}

	/**
	 * Saves the target folder (administrators). Another folder than before
	 * starts afresh: the files in the old folder stay there and are no
	 * longer looked after, and the daily sync waits for a first sync by hand.
	 * Folder id 0 removes the target folder.
	 *
	 * @return array{id: int, path: string, user: string}
	 */
	public function setTargetFolder(string $uid, int $folderId): array {
		$this->roles->requireSettingsAccess($uid);
		$old = $this->info();
		$new = $folderId === 0 ? ['id' => 0, 'path' => '', 'user' => ''] : $this->checkTargetFolder($uid, $folderId);
		if ($new['id'] !== $old['id']) {
			$this->mirrorFiles->deleteAll();
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_STARTED);
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_REPORT);
			$this->notifications->withdraw(NotificationService::PRINT_MIRROR_FAILED, self::OBJECT_TYPE, 'sync');
		}
		if ($new['id'] === 0) {
			foreach ([self::KEY_FOLDER_ID, self::KEY_FOLDER_PATH, self::KEY_USER] as $key) {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
			}
		} else {
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_FOLDER_ID, $new['id']);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_FOLDER_PATH, $new['path']);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_USER, $new['user']);
		}
		if ($new['path'] !== $old['path'] || $new['id'] !== $old['id']) {
			$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'print_mirror_folder', 'target_folder',
				$old['id'] === 0 ? null : $old['path'], $new['id'] === 0 ? null : $new['path']);
		}
		return $new;
	}

	/**
	 * Target folder, whether the daily sync runs, and the report of the
	 * last sync (catalog librarian and administrators).
	 *
	 * @return array{folder: ?array{id: int, path: string, user: string}, started: bool, report: ?array<array-key, mixed>}
	 */
	public function status(string $uid): array {
		$this->requireOperator($uid);
		$info = $this->info();
		return [
			'folder' => $info['id'] === 0 ? null : $info,
			'started' => $this->started(),
			'report' => self::arrayOrNull(json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_REPORT, '', true), true, 8)),
		];
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private static function arrayOrNull(mixed $value): ?array {
		return is_array($value) ? $value : null;
	}

	/**
	 * Compares the target folder with the list of the print station and,
	 * unless it is a dry run (the preview), copies, replaces and removes.
	 * The first sync by hand starts the daily sync.
	 *
	 * @param string|null $uid who started it; null for the daily checks
	 * @return MirrorReport
	 * @throws UserFacingException when the target folder cannot be used
	 */
	public function sync(?string $uid, bool $dryRun): array {
		if ($uid !== null) {
			$this->requireOperator($uid);
		}
		$info = $this->info();
		if ($info['id'] === 0) {
			throw new InvalidInputException('No target folder has been chosen for the print station yet. An administrator chooses it in the settings of the music archive.');
		}
		$account = $info['user'];
		$userFolder = $this->userFolder($account);
		$target = $userFolder->getFirstNodeById($info['id']);
		if (!$target instanceof Folder) {
			throw new InvalidInputException('The target folder of the print station is not accessible (deleted, moved or no longer shared). An administrator chooses it again in the settings.');
		}
		if ($this->insideArchive($account, $target)) {
			throw new InvalidInputException('The target folder lies inside the archive folder, where the app never writes. An administrator chooses another target folder in the settings.');
		}
		$writable = $target->isCreatable();
		if (!$dryRun && !$writable) {
			throw new InvalidInputException('The account %s may not write into the target folder of the print station. Give it write permission, or let an administrator choose another folder in the settings.', [$account]);
		}

		$report = ['time' => $this->time->getTime(), 'dryRun' => $dryRun, 'writable' => $writable, 'copied' => [], 'replaced' => [], 'removed' => [],
			'skipped' => [], 'unchanged' => 0, 'files' => 0];
		$operation = $this->audit->newOperation();
		$manifest = $this->mirrorFiles->findAllByName();
		$keep = [self::README => true];

		foreach ($this->wanted($account, $userFolder) as $entry) {
			$name = $entry['name'];
			$keep[$name] = true;
			$item = ['name' => $name, 'shelfmark' => $entry['shelfmark'], 'reason' => null];
			if ($entry['skip'] !== null || $entry['node'] === null) {
				$report['skipped'][] = ['reason' => $entry['skip'] ?? self::NOT_ACCESSIBLE] + $item;
				continue;
			}
			$row = $manifest[$name] ?? null;
			$existing = $target->nodeExists($name) ? $target->get($name) : null;
			if ($existing !== null && ($row === null || !$existing instanceof File || $existing->getId() !== $row->getFileId())) {
				// A file of this name that the app did not write (or no longer the one it wrote)
				$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
				$this->forget($row, $dryRun);
				continue;
			}
			if ($existing === null) {
				$this->forget($row, $dryRun);
				$this->write($dryRun, $report, 'copied', $item, fn () => $this->copy($target, $name, $entry, $uid, $operation));
				continue;
			}
			/** @var MirrorFile $row */
			if ($row->getSourceId() === $entry['archiveFile']->getId() && $row->getSourceEtag() === $entry['node']->getEtag()) {
				$report['unchanged']++;
				continue;
			}
			if (self::sha256($existing) !== $row->getSha256()) {
				$report['skipped'][] = ['reason' => self::CHANGED] + $item;
				$this->forget($row, $dryRun);
				continue;
			}
			$this->write($dryRun, $report, 'replaced', $item, fn () => $this->replace($existing, $row, $entry, $uid, $operation));
		}

		foreach ($manifest as $name => $row) {
			if (isset($keep[$name])) {
				continue;
			}
			$existing = $target->nodeExists($name) ? $target->get($name) : null;
			$item = ['name' => $name, 'shelfmark' => null, 'reason' => null];
			if ($existing === null) {
				$this->forget($row, $dryRun);
			} elseif (!$existing instanceof File || $existing->getId() !== $row->getFileId()) {
				$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
				$this->forget($row, $dryRun);
			} elseif (self::sha256($existing) !== $row->getSha256()) {
				$report['skipped'][] = ['reason' => self::CHANGED] + $item;
				$this->forget($row, $dryRun);
			} else {
				$this->write($dryRun, $report, 'removed', $item, function () use ($existing, $row, $name, $uid, $operation): void {
					$existing->delete();
					$this->mirrorFiles->delete($row);
					$this->audit->record($operation, $uid, self::OBJECT_TYPE, $row->getCopyId(), 'mirror_removed', 'file', $name, null);
				});
			}
		}
		$report['files'] = $report['unchanged'] + count($report['copied']) + count($report['replaced']);
		$this->readme($target, $manifest[self::README] ?? null, $account, $dryRun, $report, $uid, $operation);

		if (!$dryRun) {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_REPORT, json_encode(self::bounded($report), JSON_THROW_ON_ERROR), true);
			if ($uid !== null) {
				$this->appConfig->setValueBool(Application::APP_ID, self::KEY_STARTED, true);
			}
			$this->notifications->withdraw(NotificationService::PRINT_MIRROR_FAILED, self::OBJECT_TYPE, 'sync');
		}
		return $report;
	}

	/**
	 * The daily sync (daily checks): only while the module is on, a target
	 * folder is chosen and the first sync was started by hand. A failure is
	 * notified to the catalog librarian and the administrators.
	 */
	public function runDaily(): void {
		if (!$this->modules->isEnabled(self::MODULE) || $this->info()['id'] === 0 || !$this->started()) {
			return;
		}
		try {
			$this->sync(null, false);
		} catch (\Throwable $e) {
			$this->logger->error('Notenarchiv: sync of the print station failed: ' . $e->getMessage(), ['exception' => $e]);
			// Only messages meant for users go into the notification.
			$parameters = $e instanceof UserFacingException
				? ['error' => $e->getMessage(), 'errorParameters' => json_encode(array_map('strval', $e->getParameters()), JSON_THROW_ON_ERROR)]
				: [];
			$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::PRINT_MIRROR_FAILED, self::OBJECT_TYPE, 'sync', $parameters, true);
		}
	}

	/**
	 * File name without extension: `<shelf mark> <title>`, without the
	 * characters Windows and Nextcloud do not allow, shortened.
	 */
	public static function fileBase(string $shelfmark, ?string $title): string {
		$text = trim($shelfmark . ' ' . ($title ?? ''));
		$text = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $text) ?? '';
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '', ' .');
		if (mb_strlen($text) > self::MAX_BASE) {
			$text = rtrim(mb_substr($text, 0, self::MAX_BASE), ' .');
		}
		return $text === '' ? 'score' : $text;
	}

	/**
	 * The files that belong into the target folder: the scans (PDF) of the
	 * active copies of every version on the list of the print station.
	 *
	 * @return list<array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string}>
	 */
	private function wanted(string $account, Folder $userFolder): array {
		$scans = [];
		foreach ($this->archiveFiles->findFound() as $file) {
			$copyId = $file->getCopyId();
			if ($copyId !== null && $file->getRole() === 'score_pdf') {
				$scans[$copyId][] = $file;
			}
		}
		$kinds = [];
		foreach ($this->copies->findAll() as $copy) {
			$kinds[$copy->getId()] = $copy->getKind();
		}
		$entries = [];
		foreach ($this->releases->printStation($account) as $row) {
			foreach ($row['copies'] as $copy) {
				$files = $scans[$copy['id']] ?? [];
				usort($files, fn (ArchiveFile $a, ArchiveFile $b): int => $a->getId() <=> $b->getId());
				$base = self::fileBase($copy['shelfmark'], $row['title']);
				foreach ($files as $index => $file) {
					$name = $base . ($index > 0 ? ' (' . ($index + 1) . ')' : '') . '.pdf';
					$node = null;
					if (($kinds[$copy['id']] ?? null) === 'preservation_copy') {
						$skip = self::PRESERVATION;
					} elseif (!$this->filenameValidator->isFilenameValid($name)) {
						$skip = self::INVALID_NAME;
					} else {
						$found = $userFolder->getFirstNodeById($file->getFileId());
						$node = $found instanceof File ? $found : null;
						$skip = $node === null ? self::NOT_ACCESSIBLE : (ScanService::isPdf($node) ? null : self::NOT_PDF);
					}
					$entries[] = ['name' => $name, 'shelfmark' => $copy['shelfmark'], 'copyId' => $copy['id'], 'archiveFile' => $file,
						'node' => $skip === null ? $node : null, 'skip' => $skip];
				}
			}
		}
		return $entries;
	}

	/**
	 * Runs a write action unless it is a dry run, and reports it; a failed
	 * action is reported as skipped and the others go on.
	 *
	 * @param MirrorReport $report
	 * @param 'copied'|'replaced'|'removed' $list
	 * @param MirrorEntry $item
	 * @param callable(): void $action
	 */
	private function write(bool $dryRun, array &$report, string $list, array $item, callable $action): void {
		if (!$dryRun) {
			try {
				$action();
			} catch (\Exception $e) {
				$this->logger->warning('Notenarchiv: print station, ' . $item['name'] . ': ' . $e->getMessage(), ['exception' => $e]);
				$report['skipped'][] = ['reason' => self::WRITE_FAILED] + $item;
				return;
			}
		}
		$report[$list][] = $item;
	}

	/**
	 * @param array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string} $entry
	 */
	private function copy(Folder $target, string $name, array $entry, ?string $uid, string $operation): void {
		$source = $entry['node'];
		if ($source === null) {
			return;
		}
		$handle = $source->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('cannot read ' . $source->getPath());
		}
		try {
			$file = $target->newFile($name, $handle);
		} finally {
			// newFile() may close the stream itself
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
		$row = new MirrorFile();
		$row->setName($name);
		$row->setFileId($file->getId());
		$row->setSha256(self::sha256($file));
		$row->setCopyId($entry['copyId']);
		$row->setSourceId($entry['archiveFile']->getId());
		$row->setSourceEtag($source->getEtag());
		$row->setWritten($this->time->getTime());
		$this->mirrorFiles->insert($row);
		$this->audit->record($operation, $uid, self::OBJECT_TYPE, $entry['copyId'], 'mirror_copied', 'file', null, $name);
	}

	/**
	 * @param array{name: string, shelfmark: string, copyId: int, archiveFile: ArchiveFile, node: ?File, skip: ?string} $entry
	 */
	private function replace(File $existing, MirrorFile $row, array $entry, ?string $uid, string $operation): void {
		$source = $entry['node'];
		if ($source === null) {
			return;
		}
		$handle = $source->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('cannot read ' . $source->getPath());
		}
		try {
			$existing->putContent($handle);
		} finally {
			// putContent() may close the stream itself
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
		$row->setSha256(self::sha256($existing));
		$row->setCopyId($entry['copyId']);
		$row->setSourceId($entry['archiveFile']->getId());
		$row->setSourceEtag($source->getEtag());
		$row->setWritten($this->time->getTime());
		$this->mirrorFiles->update($row);
		$this->audit->record($operation, $uid, self::OBJECT_TYPE, $entry['copyId'], 'mirror_replaced', 'file', $row->getName(), $row->getName());
	}

	/**
	 * Writes LIESMICH.txt when the folder changed or it is missing; never
	 * over a file the app did not write or one changed since.
	 *
	 * @param MirrorReport $report
	 */
	private function readme(Folder $target, ?MirrorFile $row, string $account, bool $dryRun, array &$report, ?string $uid, string $operation): void {
		$existing = $target->nodeExists(self::README) ? $target->get(self::README) : null;
		$item = ['name' => self::README, 'shelfmark' => null, 'reason' => null];
		$own = $existing instanceof File && $row !== null && $existing->getId() === $row->getFileId();
		if ($existing !== null && !$own) {
			$report['skipped'][] = ['reason' => self::FOREIGN] + $item;
			$this->forget($row, $dryRun);
			return;
		}
		$changes = count($report['copied']) + count($report['replaced']) + count($report['removed']);
		if ($existing !== null && $changes === 0) {
			return;
		}
		/** @var ?File $existing */
		if ($existing !== null && $row !== null && self::sha256($existing) !== $row->getSha256()) {
			$report['skipped'][] = ['reason' => self::CHANGED] + $item;
			$this->forget($row, $dryRun);
			return;
		}
		$text = $this->readmeText($account, $report['files']);
		$this->write($dryRun, $report, $existing === null ? 'copied' : 'replaced', $item,
			function () use ($target, $existing, $row, $text, $uid, $operation): void {
				$file = $existing ?? $target->newFile(self::README, $text);
				if ($existing !== null) {
					$existing->putContent($text);
				}
				$entry = $row ?? new MirrorFile();
				$entry->setName(self::README);
				$entry->setFileId($file->getId());
				$entry->setSha256(hash('sha256', $text));
				$entry->setWritten($this->time->getTime());
				if ($row === null) {
					$this->mirrorFiles->insert($entry);
				} else {
					$this->mirrorFiles->update($entry);
				}
				$this->audit->record($operation, $uid, self::OBJECT_TYPE, null, 'mirror_readme', 'file', null, self::README);
			});
	}

	/**
	 * The text of LIESMICH.txt in the language of the account.
	 */
	private function readmeText(string $account, int $files): string {
		$user = $this->userManager->get($account);
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $account));
		$lines = [
			$l->t('Print station: mirror folder of the music archive'),
			$l->t('As of %s', [$now->format('Y-m-d H:i')]),
			'',
			$l->n('The music archive keeps this folder up to date. It holds %n score (PDF) released for the print station.',
				'The music archive keeps this folder up to date. It holds %n scores (PDF) released for the print station.', $files),
			$l->t('Print these scores only for the choir and do not pass them on.'),
			$l->t('The app copies, replaces and removes only the files it wrote itself. Files changed or added by others stay as they are.'),
			$l->t('Questions: please ask the music librarians.'),
		];
		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Forgets a file of the manifest (it is not the app's any more).
	 */
	private function forget(?MirrorFile $row, bool $dryRun): void {
		if ($row !== null && !$dryRun) {
			$this->mirrorFiles->delete($row);
		}
	}

	private function started(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_STARTED, false);
	}

	/**
	 * Whether a folder is the archive folder or lies inside it, as seen by
	 * the account and by the account that chose the archive folder (a
	 * folder may be reached through shares at different paths).
	 */
	private function insideArchive(string $uid, Folder $folder): bool {
		$archive = $this->settings->archiveInfo();
		if ($archive['id'] === 0) {
			return false;
		}
		if ($folder->getId() === $archive['id']) {
			return true;
		}
		foreach (array_unique(array_filter([$uid, $archive['user']])) as $user) {
			try {
				$view = $this->rootFolder->getUserFolder($user);
			} catch (\Throwable) {
				continue;
			}
			foreach ($view->getById($folder->getId()) as $node) {
				foreach ($view->getById($archive['id']) as $archiveNode) {
					if (str_starts_with($node->getPath() . '/', rtrim($archiveNode->getPath(), '/') . '/')) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function userFolder(string $account): Folder {
		try {
			return $this->rootFolder->getUserFolder($account);
		} catch (\Throwable) {
			throw new InvalidInputException('The account that chose the target folder of the print station no longer exists. An administrator chooses the target folder again in the settings.');
		}
	}

	private function requireOperator(string $uid): void {
		if (!$this->roles->isAdmin($uid)) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		}
	}

	private static function sha256(File $file): string {
		$handle = $file->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('cannot read ' . $file->getPath());
		}
		try {
			$context = hash_init('sha256');
			hash_update_stream($context, $handle);
			return hash_final($context);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	/**
	 * The report with at most MAX_REPORT_ENTRIES entries per list, to store.
	 *
	 * @param MirrorReport $report
	 * @return MirrorReport
	 */
	private static function bounded(array $report): array {
		foreach (['copied', 'replaced', 'removed', 'skipped'] as $list) {
			$report[$list] = array_slice($report[$list], 0, self::MAX_REPORT_ENTRIES);
		}
		return $report;
	}
}
