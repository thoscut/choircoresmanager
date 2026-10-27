<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IDateTimeZone;

/**
 * Annual snapshots of the archive (module "annual_snapshot", milestone 0.8):
 * a dated folder in the backup folder (AppSettings::backupFolder(), outside
 * the archive folder), like the weekly backup, with
 * - `catalog.csv`: the catalog in the extended CSV format (ExportService),
 * - `catalog.json`: the catalog tables of the app (FullBackupService), both
 *   without accounts, the folder book, the audit log and link tokens, so
 *   that a snapshot kept for years names no choir members,
 * - `manifest.csv`: SHA-256, size and path of every archive file the file
 *   sync knows: the checksum stored by the checksum job while the file is
 *   unchanged since, else computed from the content.
 *
 * Snapshots are written on request by the catalog librarian or, while the
 * setting is on, in January for the year before (AnnualSnapshotJob). They
 * are never overwritten or deleted by the app; the folder name holds the
 * year and the time: `<year>_<YYYYMMDD-HHMMSS>`.
 *
 * Two snapshots can be compared: new, removed and changed copies, changes
 * of rights and licenses, and files that are new, gone or changed by the
 * manifest. Snapshots are read and written as the account that set the
 * archive folder; the archive files for the manifest as well.
 *
 * @psalm-import-type Backup from FullBackupService
 * @psalm-type Row = array<string, string|int|float|bool|null>
 * @psalm-type ManifestEntry = array{path: string, sha256: string, size: ?int}
 * @psalm-type Index = array{copies: array<string, Row>, versions: array<int, Row>, works: array<int, Row>, licenses: array<int, Row>, shelfmarks: array<int, list<string>>}
 * @psalm-type Snapshot = array{name: string, year: int, created: string, complete: bool}
 * @psalm-type Change = array{field: string, old: string|int|null, new: string|int|null}
 * @psalm-type CopyRow = array{shelfmark: string, title: ?string}
 * @psalm-type ChangedRow = array{shelfmark: string, title: ?string, changes: list<Change>}
 * @psalm-type LicenseRow = array{shelfmark: string, title: ?string, holder: ?string, uses: ?string, change: string, changes: list<Change>}
 * @psalm-type FileRow = array{path: string, size: ?int}
 * @psalm-type Comparison = array{copies: array{added: list<CopyRow>, removed: list<CopyRow>, changed: list<ChangedRow>}, rights: list<ChangedRow>, licenses: list<LicenseRow>, files: array{added: list<FileRow>, removed: list<FileRow>, changed: list<FileRow>, compared: bool}}
 */
class AnnualSnapshotService {
	/** Sub folder of the backup folder (AppSettings::backupFolder()) */
	public const FOLDER = 'annual-snapshots';
	public const CATALOG_CSV = 'catalog.csv';
	public const CATALOG_JSON = 'catalog.json';
	public const MANIFEST = 'manifest.csv';
	public const FILES = [self::CATALOG_CSV, self::CATALOG_JSON, self::MANIFEST];

	/** App config: freeze automatically at the turn of the year (default on) */
	public const AUTO_KEY = 'annual_snapshot_auto';
	/** App config (lazy): message of the last failed automatic snapshot, JSON */
	public const ERROR_KEY = 'annual_snapshot_error';

	public const MANUAL = 'manual';
	public const TURN_OF_YEAR = 'turn_of_year';

	/** Fields compared per copy, per version (rights) and per license */
	private const COPY_FIELDS = ['status', 'kind', 'originals_count', 'copies_count'];
	private const RIGHTS_FIELDS = ['rights_status', 'confirmed_at', 'publication_status', 'preservation_copy', 'scientific_edition',
		'posthumous_work', 'first_published_year', 'portal_since', 'valid_since'];
	private const LICENSE_FIELDS = ['holder', 'uses', 'quantity', 'valid_from', 'valid_until'];

	private const NAME_PATTERN = '/^(\d{4})_(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})(?:-\d{1,3})?$/';

	public function __construct(
		private AppSettings $settings,
		private ExportService $export,
		private FullBackupService $backup,
		private ArchiveFileMapper $files,
		private RightsService $rights,
		private Roles $roles,
		private AuditLog $audit,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private RunLock $runLock,
	) {
	}

	/**
	 * The snapshots (newest first), the setting, the last error of the
	 * automatic snapshot and what a new snapshot would write where.
	 *
	 * @return array{snapshots: list<Snapshot>, auto: bool, lastError: ?array{message: string, parameters: list<string>}, running: array{since: int, by: string|null}|null, next: array{year: int, folder: string, files: list<string>, archiveFiles: int, bytes: int, exists: bool}}
	 */
	public function overview(string $uid): array {
		$this->roles->requireAccess($uid);
		$snapshots = $this->snapshots();
		$year = $this->currentYear($uid);
		$archiveFiles = 0;
		$bytes = 0;
		foreach ($this->files->findFound() as $file) {
			$archiveFiles++;
			$bytes += (int)$file->getSize();
		}
		return [
			'snapshots' => $snapshots,
			'auto' => $this->isAuto(),
			'lastError' => $this->lastError(),
			'running' => $this->runLock->state(RunLock::ANNUAL_SNAPSHOT),
			'next' => [
				'year' => $year,
				'folder' => $this->folderPath(),
				'files' => self::FILES,
				'archiveFiles' => $archiveFiles,
				'bytes' => $bytes,
				'exists' => array_filter($snapshots, fn (array $s): bool => $s['year'] === $year) !== [],
			],
		];
	}

	/**
	 * Freezes the current state for the current year (catalog librarian).
	 *
	 * @return array{name: string, folder: string, files: list<string>, manifest: array{files: int, bytes: int, missing: int}}
	 */
	public function createManually(string $uid): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		return $this->create($uid, $this->currentYear($uid), self::MANUAL);
	}

	/**
	 * Writes a snapshot as the given account. Existing snapshots are never
	 * overwritten: a second one of the same second gets a suffix.
	 *
	 * @param self::MANUAL|self::TURN_OF_YEAR $occasion
	 * @return array{name: string, folder: string, files: list<string>, manifest: array{files: int, bytes: int, missing: int}}
	 */
	public function create(string $uid, int $year, string $occasion): array {
		return $this->runLock->run(RunLock::ANNUAL_SNAPSHOT, $uid, fn (): array => $this->write($uid, $year, $occasion));
	}

	/**
	 * The snapshot at the turn of the year (background job), unless the year
	 * already has one; checked while holding the lock, so two jobs never
	 * write the same year.
	 *
	 * @return array{name: string, folder: string, files: list<string>, manifest: array{files: int, bytes: int, missing: int}}|null
	 */
	public function createAtTurnOfYear(string $uid, int $year): ?array {
		return $this->runLock->run(RunLock::ANNUAL_SNAPSHOT, null,
			fn (): ?array => $this->hasYear($year) ? null : $this->write($uid, $year, self::TURN_OF_YEAR));
	}

	/**
	 * @param self::MANUAL|self::TURN_OF_YEAR $occasion
	 * @return array{name: string, folder: string, files: list<string>, manifest: array{files: int, bytes: int, missing: int}}
	 */
	private function write(string $uid, int $year, string $occasion): array {
		$parent = $this->writableFolder();
		$archive = $this->settings->archiveFolder($this->settings->archiveInfo()['user']);
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid));
		$base = sprintf('%04d', $year) . '_' . $now->format('Ymd-His');
		$name = $base;
		for ($i = 2; $parent->nodeExists($name); $i++) {
			$name = $base . '-' . $i;
		}
		try {
			$folder = $parent->newFolder($name);
			$folder->newFile(self::CATALOG_CSV, $this->export->csv(true, true));
			// Nextcloud closes the stream after writing it.
			$folder->newFile(self::CATALOG_JSON, $this->backup->exportStream(true));
			// The manifest last: a folder without it is an incomplete snapshot.
			[$manifest, $totals] = $this->manifest($archive);
			$folder->newFile(self::MANIFEST, $manifest);
		} catch (NotPermittedException) {
			throw new InvalidInputException('The account %s may not write into the backup folder.', [$this->settings->archiveInfo()['user']]);
		}
		$this->audit->record($this->audit->newOperation(), $uid, 'annual_snapshot', null, 'created', null, null,
			$name . ' (' . $occasion . ', ' . $totals['files'] . ' files)');
		return ['name' => $name, 'folder' => $this->folderPath() . '/' . $name,
			'files' => self::FILES, 'manifest' => $totals];
	}

	/**
	 * Compares two snapshots by name; the older one first.
	 *
	 * @return array{older: Snapshot, newer: Snapshot}&Comparison
	 */
	public function compare(string $uid, string $older, string $newer): array {
		$this->roles->requireAccess($uid);
		$byName = [];
		foreach ($this->snapshots() as $snapshot) {
			$byName[$snapshot['name']] = $snapshot;
		}
		foreach ([$older, $newer] as $name) {
			if (!isset($byName[$name])) {
				throw new NotFoundException('This snapshot does not exist. Reload the list and choose another one.');
			}
			if (!$byName[$name]['complete']) {
				throw new InvalidInputException('The snapshot %s is incomplete (a file is missing). Choose another one.', [$name]);
			}
		}
		if ($older === $newer) {
			throw new InvalidInputException('Choose two different snapshots.');
		}
		$folder = $this->existingFolder();
		if ($folder === null) {
			throw new NotFoundException('This snapshot does not exist. Reload the list and choose another one.');
		}
		$old = $this->read($folder, $older);
		$new = $this->read($folder, $newer);
		return ['older' => $byName[$older], 'newer' => $byName[$newer]]
			+ self::compareData($old['backup'], $new['backup'], $old['manifest'], $new['manifest']);
	}

	/**
	 * The differences between two snapshots (pure function).
	 *
	 * @param Backup $old
	 * @param Backup $new
	 * @param array<string, ManifestEntry>|null $oldManifest path => entry, null when it cannot be read
	 * @param array<string, ManifestEntry>|null $newManifest
	 * @return Comparison
	 */
	public static function compareData(array $old, array $new, ?array $oldManifest, ?array $newManifest): array {
		$a = self::index($old);
		$b = self::index($new);

		$added = [];
		$removed = [];
		$changed = [];
		foreach ($b['copies'] as $shelfmark => $copy) {
			$before = $a['copies'][$shelfmark] ?? null;
			$title = self::title($b, $copy);
			if ($before === null) {
				$added[] = ['shelfmark' => self::shelfmarkOf($copy), 'title' => $title];
				continue;
			}
			$changes = self::changes($before, $copy, self::COPY_FIELDS);
			$oldTitle = self::title($a, $before);
			if ($oldTitle !== $title) {
				$changes[] = ['field' => 'title', 'old' => $oldTitle, 'new' => $title];
			}
			$oldVersion = self::plain($before['version_id'] ?? null);
			$newVersion = self::plain($copy['version_id'] ?? null);
			if ($oldVersion !== $newVersion) {
				$changes[] = ['field' => 'version', 'old' => $oldVersion, 'new' => $newVersion];
			}
			if ($changes !== []) {
				$changed[] = ['shelfmark' => self::shelfmarkOf($copy), 'title' => $title, 'changes' => $changes];
			}
		}
		foreach ($a['copies'] as $shelfmark => $copy) {
			if (!isset($b['copies'][$shelfmark])) {
				$removed[] = ['shelfmark' => self::shelfmarkOf($copy), 'title' => self::title($a, $copy)];
			}
		}

		$rights = [];
		foreach ($b['versions'] as $id => $version) {
			$before = $a['versions'][$id] ?? null;
			if ($before === null) {
				continue;
			}
			$changes = self::changes($before, $version, self::RIGHTS_FIELDS);
			if ($changes !== []) {
				$rights[] = ['shelfmark' => implode(', ', $b['shelfmarks'][$id] ?? []), 'title' => self::versionTitle($b, $id), 'changes' => $changes];
			}
		}

		$licenses = [];
		foreach ($b['licenses'] as $id => $license) {
			$before = $a['licenses'][$id] ?? null;
			$versionId = (int)($license['version_id'] ?? 0);
			$row = ['shelfmark' => implode(', ', $b['shelfmarks'][$versionId] ?? []), 'title' => self::versionTitle($b, $versionId),
				'holder' => self::text($license['holder'] ?? null), 'uses' => self::text($license['uses'] ?? null)];
			if ($before === null) {
				$licenses[] = $row + ['change' => 'added', 'changes' => []];
				continue;
			}
			$changes = self::changes($before, $license, self::LICENSE_FIELDS);
			if ($changes !== []) {
				$licenses[] = $row + ['change' => 'changed', 'changes' => $changes];
			}
		}
		foreach ($a['licenses'] as $id => $license) {
			if (!isset($b['licenses'][$id])) {
				$versionId = (int)($license['version_id'] ?? 0);
				$licenses[] = ['shelfmark' => implode(', ', $a['shelfmarks'][$versionId] ?? []), 'title' => self::versionTitle($a, $versionId),
					'holder' => self::text($license['holder'] ?? null), 'uses' => self::text($license['uses'] ?? null), 'change' => 'removed', 'changes' => []];
			}
		}

		$files = ['added' => [], 'removed' => [], 'changed' => [], 'compared' => $oldManifest !== null && $newManifest !== null];
		if ($oldManifest !== null && $newManifest !== null) {
			foreach ($newManifest as $path => $entry) {
				$before = $oldManifest[$path] ?? null;
				if ($before === null) {
					$files['added'][] = ['path' => $entry['path'], 'size' => $entry['size']];
				} elseif ($before['sha256'] !== $entry['sha256']) {
					$files['changed'][] = ['path' => $entry['path'], 'size' => $entry['size']];
				}
			}
			foreach ($oldManifest as $path => $entry) {
				if (!isset($newManifest[$path])) {
					$files['removed'][] = ['path' => $entry['path'], 'size' => $entry['size']];
				}
			}
		}

		return [
			'copies' => ['added' => self::inShelfOrder($added), 'removed' => self::inShelfOrder($removed), 'changed' => self::inShelfOrder($changed)],
			'rights' => self::inShelfOrder($rights),
			'licenses' => self::inShelfOrder($licenses),
			'files' => $files,
		];
	}

	/**
	 * Parses a manifest (CSV with the columns sha256, size, path).
	 *
	 * @return array<string, ManifestEntry> path => entry
	 */
	public static function parseManifest(string $content): array {
		$parsed = CatalogCsv::parse($content);
		$columns = array_flip($parsed['header']);
		if (!isset($columns['sha256'], $columns['path'])) {
			throw new InvalidInputException('The manifest of a snapshot cannot be read. Choose another snapshot.');
		}
		$entries = [];
		foreach ($parsed['rows'] as $row) {
			$path = $row[$columns['path']] ?? '';
			if ($path === '') {
				continue;
			}
			$size = isset($columns['size']) ? ($row[$columns['size']] ?? '') : '';
			$entries[$path] = ['path' => $path, 'sha256' => strtolower($row[$columns['sha256']] ?? ''), 'size' => ctype_digit($size) ? (int)$size : null];
		}
		return $entries;
	}

	public function isAuto(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::AUTO_KEY, true);
	}

	public function setAuto(bool $auto, string $uid): bool {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$old = $this->isAuto();
		if ($old !== $auto) {
			$this->appConfig->setValueBool(Application::APP_ID, self::AUTO_KEY, $auto);
			$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'changed', self::AUTO_KEY, $old ? '1' : '0', $auto ? '1' : '0');
		}
		return $auto;
	}

	/**
	 * Whether a snapshot of a year exists already (for the automatic one).
	 */
	public function hasYear(int $year): bool {
		return array_filter($this->snapshots(), fn (array $s): bool => $s['year'] === $year) !== [];
	}

	/**
	 * @param list<string> $parameters
	 */
	public function setLastError(?string $message, array $parameters = []): void {
		if ($message === null) {
			$this->appConfig->deleteKey(Application::APP_ID, self::ERROR_KEY);
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::ERROR_KEY,
			json_encode(['message' => $message, 'parameters' => $parameters], JSON_THROW_ON_ERROR), true);
	}

	/**
	 * @return array{message: string, parameters: list<string>}|null
	 */
	public function lastError(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::ERROR_KEY, '', true);
		$data = $raw === '' ? null : json_decode($raw, true);
		$message = is_array($data) ? ($data['message'] ?? null) : null;
		if (!is_array($data) || !is_string($message)) {
			return null;
		}
		$parameters = isset($data['parameters']) && is_array($data['parameters'])
			? array_values(array_map(fn (mixed $value): string => is_scalar($value) ? (string)$value : '', $data['parameters']))
			: [];
		return ['message' => $message, 'parameters' => $parameters];
	}

	/**
	 * @return list<Snapshot> newest first
	 */
	private function snapshots(): array {
		$folder = $this->existingFolder();
		if ($folder === null) {
			return [];
		}
		$names = [];
		$result = [];
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!$node instanceof Folder || preg_match(self::NAME_PATTERN, $name, $m) !== 1) {
				continue;
			}
			$complete = true;
			foreach (self::FILES as $file) {
				$complete = $complete && $node->nodeExists($file);
			}
			$names[] = [$name];
			$result[] = ['name' => $name, 'year' => (int)$m[1],
				'created' => $m[2] . '-' . $m[3] . '-' . $m[4] . ' ' . $m[5] . ':' . $m[6] . ':' . $m[7], 'complete' => $complete];
		}
		return array_reverse(Sort::byKeys($result, $names));
	}

	/**
	 * The folder of the snapshots in the backup folder, created when needed
	 * (for writing).
	 */
	private function writableFolder(): Folder {
		$backups = $this->settings->backupFolder();
		try {
			$folder = $backups->nodeExists(self::FOLDER) ? $backups->get(self::FOLDER) : $backups->newFolder(self::FOLDER);
		} catch (NotPermittedException) {
			throw new InvalidInputException('The account %s may not write into the backup folder.', [$this->settings->archiveInfo()['user']]);
		}
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The backup folder contains a file named %s.', [self::FOLDER]);
		}
		return $folder;
	}

	/**
	 * The folder of the snapshots, or null when there is none yet.
	 */
	private function existingFolder(): ?Folder {
		$backups = $this->settings->backupFolder();
		if (!$backups->nodeExists(self::FOLDER)) {
			return null;
		}
		$folder = $backups->get(self::FOLDER);
		return $folder instanceof Folder ? $folder : null;
	}

	/**
	 * Path of the snapshots in the files of the account that set the
	 * archive folder.
	 */
	private function folderPath(): string {
		return ltrim(($this->settings->backupFolderPath() ?? '') . '/' . self::FOLDER, '/');
	}

	/**
	 * @return array{backup: Backup, manifest: ?array<string, ManifestEntry>}
	 */
	private function read(Folder $parent, string $name): array {
		$unreadable = new InvalidInputException('The snapshot %s is incomplete or cannot be read. Choose another one.', [$name]);
		try {
			$folder = $parent->get($name);
			$json = $folder instanceof Folder ? $folder->get(self::CATALOG_JSON) : null;
			$manifest = $folder instanceof Folder ? $folder->get(self::MANIFEST) : null;
		} catch (FileNotFoundException) {
			throw $unreadable;
		}
		if (!$json instanceof File || !$manifest instanceof File) {
			throw $unreadable;
		}
		$backup = FullBackupService::parse(UserFiles::content($json, FullBackupService::MAX_BYTES, $unreadable));
		try {
			$entries = self::parseManifest(UserFiles::content($manifest, FullBackupService::MAX_BYTES, $unreadable));
		} catch (InvalidInputException) {
			$entries = null;
		}
		return ['backup' => $backup, 'manifest' => $entries];
	}

	/**
	 * The manifest of the archive files the file sync knows: SHA-256 of the
	 * current content, size and path, in the order of the paths.
	 *
	 * @return array{0: string, 1: array{files: int, bytes: int, missing: int}}
	 */
	private function manifest(Folder $archive): array {
		$rows = [];
		$totals = ['files' => 0, 'bytes' => 0, 'missing' => 0];
		foreach ($this->files->findFound() as $file) {
			$node = $archive->getFirstNodeById($file->getFileId());
			if (!$node instanceof File) {
				$totals['missing']++;
				continue;
			}
			try {
				// The checksum of the checksum job while the file is unchanged
				// since then (same ETag and size), else from the content.
				$stored = $file->getSha256();
				$hash = $stored !== null && $file->getSha256Mismatch() !== true && $file->getEtag() === $node->getEtag()
					&& $file->getSize() === (int)$node->getSize() && $file->getMtime() === $node->getMTime()
					? $stored : ChecksumService::hash($node);
			} catch (\Throwable) {
				$totals['missing']++;
				continue;
			}
			$size = (int)$node->getSize();
			$rows[$file->getPath()] = [$hash, (string)$size, $file->getPath()];
			$totals['files']++;
			$totals['bytes'] += $size;
		}
		ksort($rows, SORT_STRING);
		return [CatalogCsv::writeForPeople(['sha256', 'size', 'path'], array_values($rows)), $totals];
	}

	private function currentYear(string $uid): int {
		return (int)substr($this->rights->today($uid), 0, 4);
	}

	/**
	 * Copies by shelf mark, versions and licenses by id, works by id and the
	 * shelf marks of every version, from a backup.
	 *
	 * @param Backup $backup
	 * @return Index
	 */
	private static function index(array $backup): array {
		$byId = function (string $table) use ($backup): array {
			$rows = [];
			foreach ($backup['tables'][$table] ?? [] as $row) {
				$rows[(int)($row['id'] ?? 0)] = $row;
			}
			return $rows;
		};
		$copies = [];
		$shelfmarks = [];
		foreach ($backup['tables']['notarch_copies'] ?? [] as $row) {
			$shelfmark = (string)($row['shelfmark'] ?? '');
			$copies[$shelfmark] = $row;
			$shelfmarks[(int)($row['version_id'] ?? 0)][] = $shelfmark;
		}
		return ['copies' => $copies, 'versions' => $byId('notarch_versions'), 'works' => $byId('notarch_works'),
			'licenses' => $byId('notarch_licenses'), 'shelfmarks' => $shelfmarks];
	}

	/**
	 * @param Row $copy
	 */
	private static function shelfmarkOf(array $copy): string {
		return self::text($copy['shelfmark'] ?? null) ?? '';
	}

	/**
	 * @param Index $index
	 * @param Row $copy
	 */
	private static function title(array $index, array $copy): ?string {
		return self::versionTitle($index, (int)($copy['version_id'] ?? 0));
	}

	/**
	 * @param Index $index
	 */
	private static function versionTitle(array $index, int $versionId): ?string {
		$version = $index['versions'][$versionId] ?? null;
		$work = $version === null ? null : ($index['works'][(int)($version['work_id'] ?? 0)] ?? null);
		return self::text($work['title'] ?? null);
	}

	/**
	 * Changed values of the given fields. Values are compared as the backup
	 * stores them; booleans also as 0/1.
	 *
	 * @param array<string, string|int|float|bool|null> $old
	 * @param array<string, string|int|float|bool|null> $new
	 * @param list<string> $fields
	 * @return list<Change>
	 */
	private static function changes(array $old, array $new, array $fields): array {
		$changes = [];
		foreach ($fields as $field) {
			$before = self::plain($old[$field] ?? null);
			$after = self::plain($new[$field] ?? null);
			if ($before !== $after) {
				$changes[] = ['field' => $field, 'old' => $before, 'new' => $after];
			}
		}
		return $changes;
	}

	private static function plain(string|int|float|bool|null $value): string|int|null {
		return match (true) {
			$value === null || $value === '' => null,
			is_bool($value) => (int)$value,
			is_float($value) => (string)$value,
			is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 => (int)$value,
			default => $value,
		};
	}

	private static function text(string|int|float|bool|null $value): ?string {
		return $value === null || is_bool($value) || $value === '' ? null : (string)$value;
	}

	/**
	 * @return list<int|string> binder and number for sorting ("5-12, 5-13" by its first shelf mark)
	 */
	private static function shelfmarkKey(string $shelfmarks): array {
		return preg_match('/^(\d+)-(\d+)/', $shelfmarks, $m) === 1 ? [0, (int)$m[1], (int)$m[2]] : [1, 0, 0, $shelfmarks];
	}

	/**
	 * Rows in shelf order (rows of a version by its first shelf mark).
	 *
	 * @template T of array{shelfmark: string, ...}
	 * @param list<T> $rows
	 * @return list<T>
	 */
	private static function inShelfOrder(array $rows): array {
		return Sort::byKeys($rows, array_map(fn (array $row): array => self::shelfmarkKey($row['shelfmark']), $rows));
	}
}
