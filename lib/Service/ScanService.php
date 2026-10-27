<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/**
 * The scan of a copy beside the score (milestone 0.6, module
 * "score_viewer"): a linked PDF, read through the account's own view of
 * Nextcloud and only when the account may view the version's score
 * (rights policy, preservation copies).
 */
class ScanService {
	public function __construct(
		private ArchiveFileMapper $files,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private RightsPolicy $policy,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @throws InvalidInputException|AccessDeniedException
	 */
	public function scan(int $archiveFileId, string $uid): File {
		$notScan = new InvalidInputException('This file is not a PDF scan.');
		try {
			$file = $this->files->find($archiveFileId);
			$copy = $this->copies->find($file->getCopyId() ?? 0);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($file->getRole() !== 'score_pdf') {
			throw $notScan;
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			throw new AccessDeniedException('The file is not accessible for this account.');
		}
		if (!self::isPdf($node)) {
			throw $notScan;
		}
		return $node;
	}

	/**
	 * Whether the file starts like a PDF document.
	 */
	public static function isPdf(File $node): bool {
		try {
			$handle = $node->fopen('r');
		} catch (NotPermittedException|LockedException) {
			return false;
		}
		if ($handle === false) {
			return false;
		}
		try {
			$start = fread($handle, 1024);
		} finally {
			fclose($handle);
		}
		// The header may follow a few bytes of junk, as PDF readers accept it.
		return is_string($start) && str_contains($start, '%PDF-');
	}
}
