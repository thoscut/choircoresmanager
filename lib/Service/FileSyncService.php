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
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\TranscriptionReportMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Matches the files in the archive folder against the catalog.
 *
 * Files are assigned by the shelf mark at the start of the file name; leading
 * zeros do not count (`6-08_…` belongs to 6-8). Folders whose name starts with
 * `_` or `.` (tools, exports, duplicate scans) are skipped.
 *
 * The app only reads: no file is modified, moved or deleted. Files that have
 * disappeared stay registered as "not found".
 *
 * Files the librarian linked to a copy by hand (`linked_manually`) keep their
 * copy whatever their name, also outside the scanned folders. When size or
 * modification time of a file changed, its stored checksum is cleared,
 * because it belongs to the old content.
 *
 * Reports of transcriptions (`…bericht.json`, milestone 0.6) are registered
 * with the role `transcription_report` and counted apart from the score
 * files. While the module "transcription_review" is active, new and changed
 * reports are read into review points after the sync (ReviewService).
 *
 * While the module "version_states" is active, the score file of each copy
 * becomes a new revision of its version after the sync when it is new or
 * changed (StateService); the first sync records the first revisions.
 *
 * Only rows whose file changed are written. A sync never runs twice at the
 * same time (RunLock); the counts of the last one are kept for the status.
 *
 * @psalm-type LastSync = array{time: int, by: string|null, files: int, new: int, changed: int, missing: int}
 */
class FileSyncService {
	public const ROLE_REPORT = 'transcription_report';
	public const LAST_SYNC_KEY = 'last_file_sync';

	public const ROLE_BY_TYPE = [
		'pdf' => 'score_pdf',
		'mxl' => 'musicxml',
		'musicxml' => 'musicxml',
		'xml' => 'musicxml',
		'mscz' => 'musescore',
		'md' => 'analysis',
		'capx' => 'source',
		'cap' => 'source',
		'sib' => 'source',
		'mp3' => 'audio',
		'wav' => 'audio',
		'm4a' => 'audio',
		'jpg' => 'image',
		'jpeg' => 'image',
		'png' => 'image',
		'bmp' => 'image',
		'tif' => 'image',
	];

	public function __construct(
		private IDBConnection $db,
		private AppSettings $settings,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private ArchiveFileMapper $files,
		private AuditLog $audit,
		private ITimeFactory $time,
		private Modules $modules,
		private ReviewService $review,
		private TranscriptionReportMapper $reports,
		private IL10N $l,
		private LoggerInterface $logger,
		private RightsNoticeService $rightsNotices,
		private StateService $states,
		private RunLock $runLock,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Whether a file name is that of a transcription report: it ends with
	 * "bericht.json" (case-insensitive), e.g. "5-01_Titel - Bericht.json".
	 */
	public static function isReportName(string $name): bool {
		return preg_match('/bericht\.json$/i', $name) === 1;
	}

	/**
	 * The role of a file by its name.
	 */
	public static function roleOf(string $name): string {
		if (self::isReportName($name)) {
			return self::ROLE_REPORT;
		}
		return self::ROLE_BY_TYPE[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'other';
	}

	/**
	 * @return array<string, mixed> report
	 * @throws \OCA\Notenarchiv\Exception\RunInProgressException while another sync runs
	 */
	public function sync(string $uid): array {
		return $this->runLock->run(RunLock::FILE_SYNC, $uid, function () use ($uid): array {
			$report = $this->syncNow($uid);
			$this->appConfig->setValueString(Application::APP_ID, self::LAST_SYNC_KEY, json_encode([
				'time' => $this->time->getTime(), 'by' => $uid, 'files' => $report['files'],
				'new' => $report['new'], 'changed' => $report['changed'], 'missing' => $report['missing'],
			], JSON_THROW_ON_ERROR));
			return $report;
		});
	}

	/**
	 * The sync in progress and the counts of the last one.
	 *
	 * @return array{running: array{since: int, by: string|null}|null, last: LastSync|null}
	 */
	public function status(): array {
		return [
			'running' => $this->runLock->state(RunLock::FILE_SYNC),
			'last' => self::lastSync(json_decode($this->appConfig->getValueString(Application::APP_ID, self::LAST_SYNC_KEY), true)),
		];
	}

	/**
	 * @return LastSync|null
	 */
	private static function lastSync(mixed $data): ?array {
		if (!is_array($data) || !isset($data['time']) || !is_int($data['time'])) {
			return null;
		}
		$count = fn (string $key): int => isset($data[$key]) && is_int($data[$key]) ? $data[$key] : 0;
		return [
			'time' => $data['time'],
			'by' => isset($data['by']) && is_string($data['by']) ? $data['by'] : null,
			'files' => $count('files'),
			'new' => $count('new'),
			'changed' => $count('changed'),
			'missing' => $count('missing'),
		];
	}

	/**
	 * @return array{files: int, new: int, changed: int, missing: int, ...<string, mixed>}
	 */
	private function syncNow(string $uid): array {
		$archive = $this->settings->archiveFolder($uid);
		$now = $this->time->getTime();
		$copies = $this->copies->findAllByShelfmark();
		$copiesById = [];
		foreach ($copies as $copy) {
			$copiesById[$copy->getId()] = $copy;
		}
		$versions = $this->versions->findAllById();
		$known = $this->files->findAllByFileId();
		// Fallback by path: file ids are only valid within one instance (e.g.
		// after restoring a backup elsewhere) and change when a file is replaced.
		$byPath = [];
		foreach ($known as $row) {
			$byPath[$row->getPath()] = $row;
		}

		$found = [];
		$skipped = [];
		$this->collect($archive, $found, $skipped);

		$reviewActive = $this->modules->isEnabled(ReviewService::MODULE);
		$report = ['files' => 0, 'assigned' => 0, 'new' => 0, 'changed' => 0, 'missing' => 0, 'ignored' => 0, 'linkedManually' => 0,
			'unassignable' => [], 'byType' => [], 'copiesWithoutFile' => [], 'skippedFolders' => $skipped,
			'reports' => ['files' => 0, 'assigned' => 0, 'imported' => 0, 'unchanged' => 0, 'failed' => [], 'switchedOff' => !$reviewActive]];
		$copiesWithFile = [];
		/** @var list<array{file: ArchiveFile, node: File, copy: ?Copy, path: string, changed: bool}> $reportFiles */
		$reportFiles = [];
		/** @var list<array{file: ArchiveFile, node: File, version: WorkVersion}> $unreadNotices MusicXML files whose rights notice is not read yet */
		$unreadNotices = [];
		/** @var list<int> $changedScores MusicXML files found new or changed (notarch_files.id) */
		$changedScores = [];
		$operation = $this->audit->newOperation();

		$this->db->beginTransaction();
		try {
			foreach ($found as $node) {
				$name = $node->getName();
				$path = ltrim($archive->getRelativePath($node->getPath()) ?? $name, '/');
				$existing = $known[$node->getId()] ?? null;
				if ($existing === null && isset($byPath[$path])) {
					$existing = $byPath[$path];
					unset($known[$existing->getFileId()]);
					$known[$node->getId()] = $existing;
				}
				// A file the librarian linked by hand keeps its copy, whatever its name.
				$manual = $existing?->getLinkedManually() === true;
				$parts = [];
				$named = preg_match('/^(\d+)-(\d+)(?!\d)/', $name, $parts) === 1;
				// Only files named after a shelf mark belong to the catalog; others
				// (instructions, lists, the catalog CSV itself) are just counted.
				if (!$manual && !$named) {
					$report['ignored']++;
					continue;
				}
				$type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
				$role = self::roleOf($name);
				$isReport = $role === self::ROLE_REPORT;

				if ($manual) {
					$report['linkedManually']++;
					$copy = $copiesById[(int)$known[$node->getId()]->getCopyId()] ?? null;
				} else {
					// Reports are counted apart from the score and scan files.
					if ($isReport) {
						$report['reports']['files']++;
					} else {
						$report['files']++;
						$report['byType'][$type] = ($report['byType'][$type] ?? 0) + 1;
					}
					$copy = $copies[(int)$parts[1] . '-' . (int)$parts[2]] ?? null;
					if ($copy === null) {
						$report['unassignable'][] = $path;
					} elseif ($isReport) {
						$report['reports']['assigned']++;
					} else {
						$report['assigned']++;
					}
				}
				if ($copy !== null && !$isReport) {
					$copiesWithFile[$copy->getShelfmark()] = true;
				}

				$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
				$listed = $copy !== null && (
					self::listContains($copy->getPdfFiles(), $name)
					|| self::listContains($version?->getMusicxmlFile(), $name)
					|| self::listContains($version?->getMusicxmlValid(), $name)
				);

				$existing = $known[$node->getId()] ?? null;
				$isNew = $existing === null;
				$file = $existing ?? new ArchiveFile();
				$size = (int)$node->getSize();
				if (!$isNew && ($file->getSize() !== $size || $file->getMtime() !== $node->getMTime())) {
					// The file was changed: its stored checksum belongs to the old content.
					$file->setSha256(null);
					$file->setSha256At(null);
				}
				$file->setFileId($node->getId());
				$file->setCopyId($copy?->getId());
				$file->setPath($path);
				$file->setName($name);
				$file->setType($type === '' ? null : $type);
				$file->setRole($role);
				$file->setSize($size);
				$file->setMtime($node->getMTime());
				$file->setEtag($node->getEtag());
				$file->setListed($listed);
				$file->setMusicxmlValid($version !== null && $version->getMusicxmlValid() === $name);
				$file->setFound(true);

				$contentChanged = $isNew || array_intersect(array_keys($file->getUpdatedFields()), ['size', 'mtime', 'etag', 'fileId']) !== [];
				if ($contentChanged && !$isNew && $file->getRightsReadAt() !== null) {
					// The rights notice belongs to the old content: read it again.
					$file->setRightsNotice(null);
					$file->setRightsReadAt(null);
				}
				// Only rows that changed are written: synced_at says when the row
				// last changed.
				if ($isNew) {
					$file->setSyncedAt($now);
					$file = $this->files->insert($file);
					$report['new']++;
				} elseif ($file->getUpdatedFields() !== []) {
					$file->setSyncedAt($now);
					$file = $this->files->update($file);
					$report['changed']++;
				}
				if ($isReport) {
					$reportFiles[] = ['file' => $file, 'node' => $node, 'copy' => $copy, 'path' => $path, 'changed' => $contentChanged];
				}
				if ($role === 'musicxml' && $contentChanged) {
					$changedScores[] = $file->getId();
				}
				if ($role === 'musicxml' && $version !== null && $file->getRightsReadAt() === null) {
					$unreadNotices[] = ['file' => $file, 'node' => $node, 'version' => $version];
				}
				unset($known[$node->getId()]);
			}

			foreach ($known as $gone) {
				// A file linked by hand may lie outside the scanned folders.
				$node = $gone->getLinkedManually() === true ? $archive->getFirstNodeById($gone->getFileId()) : null;
				if ($node instanceof File) {
					$report['linkedManually']++;
					$gone->setPath(ltrim($archive->getRelativePath($node->getPath()) ?? $node->getName(), '/'));
					$gone->setName($node->getName());
					$size = (int)$node->getSize();
					if ($gone->getSize() !== $size || $gone->getMtime() !== $node->getMTime()) {
						$gone->setSha256(null);
						$gone->setSha256At(null);
					}
					$gone->setSize($size);
					$gone->setMtime($node->getMTime());
					$gone->setEtag($node->getEtag());
					$gone->setFound(true);
					$gone->setSyncedAt($now);
					$this->files->update($gone);
					continue;
				}
				if ($gone->getFound() !== false) {
					$gone->setFound(false);
					$gone->setSyncedAt($now);
					$this->files->update($gone);
					$report['missing']++;
					$this->audit->record($operation, $uid, 'file', $gone->getId(), 'not_found', 'path', $gone->getPath(), null);
				}
			}

			$this->audit->record($operation, $uid, 'file_sync', null, 'file_sync', null, null,
				sprintf('%d files, %d new, %d changed, %d missing', $report['files'], $report['new'], $report['changed'], $report['missing']));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		foreach ($copies as $shelfmark => $copy) {
			if (!isset($copiesWithFile[$shelfmark]) && $copy->getStatus() === 'active') {
				$report['copiesWithoutFile'][] = $shelfmark;
			}
		}
		arsort($report['byType']);
		if ($reviewActive) {
			$report['reports'] = $this->readReports($reportFiles, $report['reports'], $uid);
		}
		// Rights notices of MusicXML files (milestone 0.8), part of the module "rights"
		$report['rightsNotices'] = $this->modules->isEnabled('rights')
			? $this->rightsNotices->readAll($unreadNotices, $uid)
			: ['read' => 0, 'found' => 0, 'proposals' => 0, 'failed' => 0, 'pending' => count($unreadNotices), 'switchedOff' => true];
		// Revisions of the versions (milestone 0.8), after the reports, which may name the change
		$report['states'] = $this->states->recordSync($changedScores, $now, $uid);
		return $report;
	}

	/**
	 * Reads the report files that are new, changed or not read yet into
	 * review points; one failed report does not stop the others.
	 *
	 * @param list<array{file: ArchiveFile, node: File, copy: ?Copy, path: string, changed: bool}> $reportFiles
	 * @param array{files: int, assigned: int, imported: int, unchanged: int, failed: list<array{path: string, error: string}>, switchedOff: bool} $counts
	 * @return array{files: int, assigned: int, imported: int, unchanged: int, failed: list<array{path: string, error: string}>, switchedOff: bool}
	 */
	private function readReports(array $reportFiles, array $counts, string $uid): array {
		foreach ($reportFiles as ['file' => $file, 'node' => $node, 'copy' => $copy, 'path' => $path, 'changed' => $changed]) {
			if ($copy === null) {
				continue;
			}
			if (!$changed && $this->reports->findByReportFile($file->getId()) !== []) {
				$counts['unchanged']++;
				continue;
			}
			try {
				$raw = UserFiles::content($node, TranscriptionReportParser::MAX_BYTES,
					new InvalidInputException('The report file cannot be read.'));
				$result = $this->review->ingestFile($file, $copy, $raw, $uid);
				$counts[$result['unchanged'] ? 'unchanged' : 'imported']++;
			} catch (UserFacingException $e) {
				$counts['failed'][] = ['path' => $path, 'error' => $this->translate($e->getMessage(), $e->getParameters())];
			} catch (\Throwable $e) {
				$this->logger->error('Notenarchiv: reading the report ' . $path . ' failed: ' . $e->getMessage(), ['exception' => $e]);
				$counts['failed'][] = ['path' => $path, 'error' => $this->l->t('Internal error. Details are in the Nextcloud log.')];
			}
		}
		return $counts;
	}

	/**
	 * A message for users with its parameters, translated (as the
	 * controllers do it).
	 *
	 * @param list<string|int> $parameters
	 */
	private function translate(string $message, array $parameters): string {
		return $this->l->t($message, array_map(fn (string|int $p): string => $this->l->t(str_replace('%', '%%', (string)$p)), $parameters));
	}

	/**
	 * Whether a file name is one entry of a legacy file list. Entries are
	 * separated by "|" or ","; because file names may themselves contain
	 * commas, the name must be bounded by a separator or the list ends.
	 */
	private static function listContains(?string $list, string $name): bool {
		if ($list === null || $list === '') {
			return false;
		}
		return preg_match('/(^|[|,])\s*' . preg_quote($name, '/') . '\s*($|[|,])/u', $list) === 1;
	}

	/**
	 * @param list<File> $files
	 * @param list<string> $skipped
	 */
	private function collect(Folder $folder, array &$files, array &$skipped): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				if (str_starts_with($node->getName(), '_') || str_starts_with($node->getName(), '.')) {
					$skipped[] = $node->getName();
					continue;
				}
				$this->collect($node, $files, $skipped);
			} elseif ($node instanceof File) {
				$files[] = $node;
			}
		}
	}
}
