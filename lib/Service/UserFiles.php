<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/**
 * Reads files chosen by a user through the user's own view of Nextcloud and
 * refuses files above a size limit, without ever loading more than the limit
 * into memory.
 */
class UserFiles {
	/** Largest file accepted for an import (catalog, persons, performances) */
	public const MAX_IMPORT_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * A file the user chose for an import or a restore.
	 *
	 * @param UserFacingException $notAccessible thrown when the file is missing, no file or cannot be read
	 * @return array{content: string, name: string}
	 * @throws UserFacingException
	 */
	public function read(string $uid, int $fileId, int $maxBytes, UserFacingException $notAccessible): array {
		$file = $fileId > 0 ? $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) : null;
		if (!$file instanceof File) {
			throw $notAccessible;
		}
		return ['content' => self::content($file, $maxBytes, $notAccessible), 'name' => $file->getName()];
	}

	/**
	 * Content of a file, at most $maxBytes long.
	 *
	 * @param UserFacingException $notAccessible thrown when the file cannot be read
	 * @throws UserFacingException
	 */
	public static function content(File $file, int $maxBytes, UserFacingException $notAccessible): string {
		if ($file->getSize() > $maxBytes) {
			throw self::tooLarge($maxBytes);
		}
		try {
			$handle = $file->fopen('r');
		} catch (NotPermittedException|LockedException) {
			$handle = false;
		}
		if ($handle === false) {
			throw $notAccessible;
		}
		try {
			// One byte more than allowed: the size in the file cache may lag
			// behind the content.
			$content = stream_get_contents($handle, $maxBytes + 1);
		} finally {
			fclose($handle);
		}
		if ($content === false) {
			throw $notAccessible;
		}
		if (strlen($content) > $maxBytes) {
			throw self::tooLarge($maxBytes);
		}
		return $content;
	}

	public static function tooLarge(int $maxBytes): InvalidInputException {
		return new InvalidInputException('The file is too large (at most %s MB).', [intdiv($maxBytes, 1024 * 1024)]);
	}
}
