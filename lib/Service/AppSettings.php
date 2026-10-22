<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;

/**
 * App settings: the archive folder with the score files and the sub folder for
 * exports and snapshots.
 */
class AppSettings {
	public const EXPORT_FOLDER = '_notenarchiv-app';

	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return array{id: int, path: string, user: string}
	 */
	public function archiveInfo(): array {
		return [
			'id' => $this->appConfig->getValueInt(Application::APP_ID, 'archive_folder_id', 0),
			'path' => $this->appConfig->getValueString(Application::APP_ID, 'archive_folder_path', ''),
			'user' => $this->appConfig->getValueString(Application::APP_ID, 'archive_user', ''),
		];
	}

	/**
	 * Sets the archive folder as seen by the user who configures it.
	 */
	public function setArchiveFolder(string $uid, int $folderId): string {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $userFolder->getFirstNodeById($folderId);
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The selected archive folder is not a folder or not accessible.');
		}
		$path = $userFolder->getRelativePath($folder->getPath()) ?? $folder->getPath();
		$this->appConfig->setValueInt(Application::APP_ID, 'archive_folder_id', $folderId);
		$this->appConfig->setValueString(Application::APP_ID, 'archive_folder_path', $path);
		$this->appConfig->setValueString(Application::APP_ID, 'archive_user', $uid);
		return $path;
	}

	/**
	 * The archive folder as seen by a user; the user must have access to it.
	 */
	public function archiveFolder(string $uid): Folder {
		$info = $this->archiveInfo();
		if ($info['id'] === 0) {
			throw new InvalidInputException('No archive folder has been set yet.');
		}
		$folder = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($info['id']);
		if (!$folder instanceof Folder) {
			throw new InvalidInputException('The archive folder is not accessible for this account.');
		}
		return $folder;
	}

	/**
	 * Sub folder for exports and snapshots; created when needed.
	 */
	public function exportFolder(string $uid): Folder {
		$archive = $this->archiveFolder($uid);
		if ($archive->nodeExists(self::EXPORT_FOLDER)) {
			$folder = $archive->get(self::EXPORT_FOLDER);
			if ($folder instanceof Folder) {
				return $folder;
			}
			throw new InvalidInputException('The archive folder contains a file named %s.', [self::EXPORT_FOLDER]);
		}
		try {
			return $archive->newFolder(self::EXPORT_FOLDER);
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
	}
}
