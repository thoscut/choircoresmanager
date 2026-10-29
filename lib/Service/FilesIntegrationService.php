<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Opening archive files from the Files app (module "files_integration").
 *
 * The script in the Files app shows the action "Open in Cantoria" only
 * for files below the archive folder whose extension the file sync knows; it
 * learns the folder from the initial state built here. Which copy a file
 * belongs to is decided here, when the action runs. A Nextcloud file id is
 * only answered for a file that the account can see in its own files, so
 * that the answer tells nothing about files of others: an unknown, a foreign
 * and an unlisted file all get the same 404.
 *
 * Opening a file grants nothing: the app checks the rights of the copy and
 * of its score (preservation copies included) as always.
 *
 * @psalm-type ClientState = array{archivePath: string, extensions: list<string>, scoreExtensions: list<string>, scoreView: bool}
 * @psalm-type CopyOfFile = array{copyId: int, shelfmark: string, role: string, score: bool}
 */
class FilesIntegrationService {
	/** Extensions whose click in the Files app opens the score view (default action) */
	public const SCORE_EXTENSIONS = ['musicxml', 'mxl'];

	public function __construct(
		private AppSettings $settings,
		private ArchiveFileMapper $files,
		private CopyMapper $copies,
		private Modules $modules,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * What the script in the Files app needs: the archive folder as the
	 * account sees it (relative to its files, e.g. "/Notenarchiv"), the
	 * extensions of archive files, those that open the score view, and
	 * whether the score view is on. Null when no archive folder is set or
	 * the account cannot see it: then the action would never apply.
	 *
	 * @return ClientState|null
	 */
	public function clientState(string $uid): ?array {
		$folderId = $this->settings->archiveInfo()['id'];
		if ($folderId === 0) {
			return null;
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $userFolder->getFirstNodeById($folderId);
		if (!$folder instanceof Folder) {
			return null;
		}
		$path = $userFolder->getRelativePath($folder->getPath());
		if ($path === null || $path === '') {
			return null;
		}
		return [
			'archivePath' => $path,
			'extensions' => array_keys(FileSyncService::ROLE_BY_TYPE),
			'scoreExtensions' => self::SCORE_EXTENSIONS,
			'scoreView' => $this->modules->isEnabled('score_viewer'),
		];
	}

	/**
	 * The copy a Nextcloud file belongs to, its shelf mark and the role of
	 * the file. `score` tells whether the app should open the score view
	 * (a MusicXML file the viewer reads, score view on) or the copy.
	 *
	 * @param int $fileId Nextcloud file id (not the id of the archive file)
	 * @return CopyOfFile
	 * @throws NotFoundException when the account cannot see the file or the file belongs to no copy
	 */
	public function copyOf(int $fileId, string $uid): array {
		// First the account's own view: a file it cannot see is unknown,
		// whether it is in the archive or not.
		if (!$this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) instanceof File) {
			throw self::notListed();
		}
		try {
			$file = $this->files->findByFileId($fileId);
			$copyId = $file->getCopyId();
			if ($copyId === null) {
				throw self::notListed();
			}
			$copy = $this->copies->find($copyId);
		} catch (DoesNotExistException) {
			throw self::notListed();
		}
		$role = $file->getRole() ?? 'other';
		$type = (string)$file->getType();
		return [
			'copyId' => $copy->getId(),
			'shelfmark' => $copy->getShelfmark(),
			'role' => $role,
			'score' => $role === 'musicxml' && isset(ScoreService::MIME_TYPES[$type]) && $this->modules->isEnabled('score_viewer'),
		];
	}

	private static function notListed(): NotFoundException {
		return new NotFoundException('This file is not listed in the music archive.');
	}
}
