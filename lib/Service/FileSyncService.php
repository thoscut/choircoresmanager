<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;

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
 */
class FileSyncService {
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
	) {
	}

	/**
	 * @return array<string, mixed> report
	 */
	public function sync(string $uid): array {
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

		$report = ['files' => 0, 'assigned' => 0, 'new' => 0, 'changed' => 0, 'missing' => 0, 'ignored' => 0, 'linkedManually' => 0,
			'unassignable' => [], 'byType' => [], 'copiesWithoutFile' => [], 'skippedFolders' => $skipped];
		$copiesWithFile = [];
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

				if ($manual) {
					$report['linkedManually']++;
					$copy = $copiesById[(int)$known[$node->getId()]->getCopyId()] ?? null;
				} else {
					$report['files']++;
					$report['byType'][$type] = ($report['byType'][$type] ?? 0) + 1;
					$copy = $copies[(int)$parts[1] . '-' . (int)$parts[2]] ?? null;
					if ($copy === null) {
						$report['unassignable'][] = $path;
					} else {
						$report['assigned']++;
					}
				}
				if ($copy !== null) {
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
				$file->setRole(self::ROLE_BY_TYPE[$type] ?? 'other');
				$file->setSize($size);
				$file->setMtime($node->getMTime());
				$file->setEtag($node->getEtag());
				$file->setListed($listed);
				$file->setMusicxmlValid($version !== null && $version->getMusicxmlValid() === $name);
				$file->setFound(true);
				$file->setSyncedAt($now);

				if ($isNew) {
					$this->files->insert($file);
					$report['new']++;
				} else {
					if (array_diff(array_keys($file->getUpdatedFields()), ['syncedAt']) !== []) {
						$report['changed']++;
					}
					$this->files->update($file);
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
		return $report;
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
