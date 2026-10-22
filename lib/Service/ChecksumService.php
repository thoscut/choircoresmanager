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
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Checksums of the archive files (SHA-256) to detect silent corruption.
 *
 * The files are read (never written) through the archive user, a limited
 * number per run, round by round through the archive. A changed size or
 * modification time means the file was changed on purpose: the new checksum
 * is stored. The same size and modification time with a different checksum
 * means the content changed unnoticed: the file is marked
 * (`sha256_mismatch`), the stored checksum is kept as reference and the
 * catalog librarian is notified once. When the file is restored, the mark
 * and the notification disappear; if the new content is right, the librarian
 * accepts it.
 */
class ChecksumService {
	public const MAX_FILES = 100;
	public const MAX_BYTES = 256 * 1024 * 1024;
	public const CURSOR_KEY = 'checksum_cursor';

	public function __construct(
		private ArchiveFileMapper $files,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private AppSettings $settings,
		private NotificationService $notifications,
		private AuditLog $audit,
		private Roles $roles,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * One run: checks the next files after the stored position, at most
	 * `$maxFiles` files and about `$maxBytes` bytes.
	 *
	 * @return array{checked: int, stored: int, changed: int, mismatches: int, missing: int, bytes: int}
	 */
	public function run(int $maxFiles = self::MAX_FILES, int $maxBytes = self::MAX_BYTES): array {
		$result = ['checked' => 0, 'stored' => 0, 'changed' => 0, 'mismatches' => 0, 'missing' => 0, 'bytes' => 0];
		$uid = $this->settings->archiveInfo()['user'];
		if ($uid === '') {
			return $result;
		}
		try {
			$archive = $this->settings->archiveFolder($uid);
		} catch (InvalidInputException $e) {
			$this->logger->warning('Notenarchiv: checksums skipped: ' . $e->getMessage());
			return $result;
		}
		$cursor = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0, true);
		$batch = $this->files->findFoundAfter($cursor, $maxFiles);
		if ($batch === [] && $cursor > 0) {
			// End of the round: start again from the beginning.
			$cursor = 0;
			$batch = $this->files->findFoundAfter(0, $maxFiles);
		}
		foreach ($batch as $file) {
			if ($result['bytes'] >= $maxBytes) {
				break;
			}
			$cursor = $file->getId();
			try {
				$state = $this->check($file, $archive);
			} catch (\Throwable $e) {
				$this->logger->warning('Notenarchiv: checksum of file ' . $file->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
				continue;
			}
			$result['checked']++;
			$result['bytes'] += (int)$file->getSize();
			match ($state) {
				'stored' => $result['stored']++,
				'changed' => $result['changed']++,
				'mismatch' => $result['mismatches']++,
				'missing' => $result['missing']++,
				default => null,
			};
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $cursor, true);
		return $result;
	}

	/**
	 * Checks one file. Result: `stored` (first checksum), `unchanged`,
	 * `changed` (changed on purpose, new checksum stored), `mismatch` or
	 * `missing`.
	 */
	public function check(ArchiveFile $file, Folder $archive): string {
		$node = $archive->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			return 'missing';
		}
		$hash = self::hash($node);
		$now = $this->time->getTime();
		$stored = $file->getSha256();
		$sameMetadata = $file->getSize() === $node->getSize() && $file->getMtime() === $node->getMTime();
		$wasFlagged = $file->getSha256Mismatch() === true;

		if ($stored !== null && $sameMetadata && !hash_equals($stored, $hash)) {
			$file->setSha256Mismatch(true);
			$file->setSha256At($now);
			$this->files->update($file);
			if (!$wasFlagged) {
				$this->audit->record($this->audit->newOperation(), null, 'file', $file->getId(), 'checksum_mismatch', 'sha256', $stored, $hash);
				$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::CHECKSUM_MISMATCH, 'file', (string)$file->getId(),
					$this->describe($file));
			}
			return 'mismatch';
		}

		$state = $stored === null ? 'stored' : ($sameMetadata ? 'unchanged' : 'changed');
		$file->setSha256($hash);
		$file->setSha256At($now);
		$file->setSha256Mismatch(false);
		$file->setSize((int)$node->getSize());
		$file->setMtime($node->getMTime());
		$file->setEtag($node->getEtag());
		$this->files->update($file);
		if ($wasFlagged) {
			$this->notifications->withdraw(NotificationService::CHECKSUM_MISMATCH, 'file', (string)$file->getId());
		}
		return $state;
	}

	/**
	 * The librarian has checked a marked file and accepts its current
	 * content: the old checksum is dropped and the next run stores a new one.
	 */
	public function accept(int $archiveFileId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$file = $this->files->find($archiveFileId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($file->getSha256Mismatch() !== true) {
			throw new InvalidInputException('There is no checksum difference to accept for this file.');
		}
		$old = $file->getSha256();
		$file->setSha256(null);
		$file->setSha256At(null);
		$file->setSha256Mismatch(false);
		$this->files->update($file);
		$this->audit->record($this->audit->newOperation(), $uid, 'file', $file->getId(), 'checksum_accepted', 'sha256', $old, null,
			$reason === null || trim($reason) === '' ? null : $reason);
		$this->notifications->withdraw(NotificationService::CHECKSUM_MISMATCH, 'file', (string)$file->getId());
	}

	/**
	 * State of the checksum check: how many files have a checksum, when the
	 * oldest check was, and the marked files.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$found = $this->files->findFound();
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$hashed = 0;
		$oldest = null;
		$mismatches = [];
		foreach ($found as $file) {
			if ($file->getSha256() !== null) {
				$hashed++;
				$at = $file->getSha256At();
				if ($at !== null && ($oldest === null || $at < $oldest)) {
					$oldest = $at;
				}
			}
			if ($file->getSha256Mismatch() === true) {
				$copyId = $file->getCopyId();
				$copy = $copyId === null ? null : ($copies[$copyId] ?? null);
				$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
				$mismatches[] = [
					'id' => $file->getId(), 'copyId' => $copy?->getId(), 'shelfmark' => $copy?->getShelfmark(),
					'title' => $version === null ? null : ($works[$version->getWorkId()] ?? null)?->getTitle(),
					'path' => $file->getPath(), 'name' => $file->getName(), 'file_id' => $file->getFileId(),
					'sha256' => $file->getSha256(), 'sha256_at' => $file->getSha256At(),
				];
			}
		}
		return ['files' => count($found), 'hashed' => $hashed, 'pending' => count($found) - $hashed, 'oldestCheck' => $oldest,
			'mismatches' => $mismatches, 'perRun' => self::MAX_FILES];
	}

	public static function hash(File $node): string {
		$handle = $node->fopen('r');
		if ($handle === false) {
			throw new \RuntimeException('File cannot be read: ' . $node->getPath());
		}
		try {
			$context = hash_init('sha256');
			hash_update_stream($context, $handle);
			return hash_final($context);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * Notification parameters: shelf mark, title and file type only, no file
	 * name (it may contain names of persons).
	 *
	 * @return array<string, scalar|null>
	 */
	private function describe(ArchiveFile $file): array {
		$params = ['type' => (string)$file->getType(), 'shelfmark' => '', 'title' => '', 'copyId' => null];
		$copyId = $file->getCopyId();
		if ($copyId === null) {
			return $params;
		}
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
			$params['shelfmark'] = $copy->getShelfmark();
			$params['copyId'] = $copy->getId();
			$params['title'] = (string)$this->works->find($version->getWorkId())->getTitle();
		} catch (DoesNotExistException) {
		}
		return $params;
	}
}
