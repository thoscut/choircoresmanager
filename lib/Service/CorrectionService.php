<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\Lock\LockedException;

/**
 * Small corrections of a MusicXML score in the app (module "corrections";
 * ROADMAP, guideline 4, the one exception to "files are only read").
 *
 * The catalog librarian or the conductor changes pitches, accidentals and
 * syllables in the score view. The server compares the result with the
 * stored file (MusicXmlCorrection), writes the stored document with only
 * these changes as a NEW file next to the old one, with the account's own
 * Nextcloud permissions, and makes it the MusicXML of the copy. The old file
 * stays unchanged and linked; the changes are recorded in the audit log and
 * the review points the correction settles are set to corrected. With the
 * module "version_states" the corrected file becomes a new revision of the
 * version (StateService).
 *
 * @psalm-import-type Change from MusicXmlCorrection
 */
class CorrectionService {
	public const MODULE = 'corrections';
	/** Roles that correct in the app */
	public const CORRECTORS = [Roles::LIBRARIAN, Roles::CONDUCTOR];
	/** Most review points settled by one correction */
	private const MAX_POINTS = 200;

	public function __construct(
		private IDBConnection $db,
		private ArchiveFileMapper $files,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private ScoreService $scores,
		private RightsPolicy $policy,
		private Roles $roles,
		private Modules $modules,
		private AuditLog $audit,
		private ReviewService $review,
		private StateService $states,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private IL10N $l,
	) {
	}

	/**
	 * Saves a corrected score of a version as a new file.
	 *
	 * @param int $archiveFileId the score file shown (notarch_files.id)
	 * @param string $content the whole changed MusicXML
	 * @param array<array-key, mixed> $pointIds review points the correction settles (request data)
	 * @return array{file: ArchiveFile, changes: list<Change>, points: list<int>, state: ?int} `state`: number of the new revision, if recorded
	 * @throws InvalidInputException|AccessDeniedException
	 */
	public function correct(int $versionId, int $archiveFileId, string $content, array $pointIds, ?string $reason, string $uid): array {
		$this->modules->requireEnabled(self::MODULE);
		$this->roles->requireRole($uid, self::CORRECTORS);
		$points = self::pointIds($pointIds);
		$reason = $reason === null || trim($reason) === '' ? null : trim($reason);
		if ($reason !== null && mb_strlen($reason) > 10000) {
			throw new InvalidInputException('"%s" is too long.', ['Reason']);
		}

		$wrongFile = new InvalidInputException('Please correct the score file shown in the score view.');
		try {
			$file = $this->files->find($archiveFileId);
			$copy = $this->copies->find($file->getCopyId() ?? 0);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw $wrongFile;
		}
		if ($version->getId() !== $versionId || $file->getRole() !== 'musicxml' || !isset(ScoreService::MIME_TYPES[(string)$file->getType()])
			|| $file->getFound() === false) {
			throw $wrongFile;
		}
		if ($version->getPreservationCopy() === true) {
			throw new InvalidInputException('Internal preservation copies cannot be corrected in the app.');
		}
		$denial = $this->policy->viewDenial($version, $uid);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		if (strlen($content) > ScoreService::MAX_BYTES) {
			throw UserFiles::tooLarge(ScoreService::MAX_BYTES);
		}
		if (str_starts_with($content, "PK\x03\x04") || !$this->scores->isScore($content)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}

		$notAccessible = new AccessDeniedException('The file is not accessible for this account.');
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($file->getFileId());
		if (!$node instanceof File) {
			throw $notAccessible;
		}
		$oldContent = UserFiles::content($node, ScoreService::MAX_BYTES, $notAccessible);
		if (!$this->scores->isScore($oldContent)) {
			throw new InvalidInputException('This file is not a MusicXML score.');
		}
		$score = $this->scores->scoreText($oldContent);
		$result = MusicXmlCorrection::apply($score['xml'], $content);
		$bytes = $score['entry'] === null ? $result['xml'] : $this->repack($oldContent, $score['entry'], $result['xml']);
		if (!$this->scores->isScore($bytes)) {
			throw new \RuntimeException('The corrected score is no MusicXML score');
		}

		$folder = $node->getParent();
		$noPermission = new InvalidInputException('You have no permission to save a new file in the folder of this score.');
		if (!$folder->isCreatable()) {
			throw $noPermission;
		}
		$today = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
		$name = self::newFileName($node->getName(), $today, $this->l->t('corrected'), fn (string $candidate): bool => $folder->nodeExists($candidate));
		try {
			$newNode = $folder->newFile($name, $bytes);
		} catch (NotPermittedException|LockedException|GenericFileException) {
			throw $noPermission;
		}

		$now = $this->time->getTime();
		$operation = $this->audit->newOperation();
		$note = $this->l->t('Corrected in the app: %s', [$name]) . ($reason !== null ? ' — ' . $reason : '');
		$this->db->beginTransaction();
		try {
			$new = $this->link($newNode, $file, $name, $bytes, $now);
			foreach ($this->files->findByCopy($copy->getId()) as $other) {
				if ($other->getId() !== $new->getId() && $other->getMusicxmlValid() === true) {
					$other->setMusicxmlValid(false);
					$this->files->update($other);
				}
			}
			foreach (['musicxml_valid' => $name, 'musicxml_date' => $today] as $field => $value) {
				$old = $version->getValue($field);
				if ($old !== $value) {
					$version->setValue($field, $value);
					$this->audit->record($operation, $uid, Fields::VERSION, $version->getId(), 'changed', $field, $old, $value, $note);
				}
			}
			$version->setModified($now);
			$this->versions->update($version);
			$this->audit->record($operation, $uid, 'file', $new->getId(), 'corrected', 'changes', $file->getName(), $result['changes'], $reason);
			$settled = $this->review->markCorrected($points, $version->getId(), $uid, $note, $operation);
			$state = $this->states->recordCorrection($copy, $version, $file, hash('sha256', $oldContent), $new, (string)$new->getSha256(),
				$result['changes'], $settled, $reason, $uid, $operation);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			try {
				$newNode->delete();
			} catch (\Throwable) {
				// The file stays; it is linked by the next file sync.
			}
			throw $e;
		}
		$this->review->notifyCorrection($copy, $version, 'app', $uid);
		return ['file' => $new, 'changes' => $result['changes'], 'points' => $settled, 'state' => $state?->getNumber()];
	}

	/**
	 * Name of the new file: the old name with "(corrected YYYY-MM-DD)" before
	 * the extension, in the account's language, a number added if it exists.
	 * An earlier such suffix is replaced, not repeated.
	 *
	 * @param callable(string): bool $exists whether a file of this name exists in the folder
	 */
	public static function newFileName(string $oldName, string $date, string $word, callable $exists): string {
		$extension = '';
		$base = $oldName;
		if (preg_match('/^(.+)\.([^.\/\\\\]{1,10})$/u', $oldName, $m) === 1) {
			[$base, $extension] = [$m[1], '.' . $m[2]];
		}
		$words = array_unique([preg_quote($word, '/'), 'corrected', 'korrigiert']);
		$base = (string)preg_replace('/ \((?:' . implode('|', $words) . ') \d{4}-\d{2}-\d{2}(?:, \d+)?\)$/u', '', $base);
		for ($n = 1; $n <= 999; $n++) {
			$suffix = $n === 1 ? sprintf(' (%s %s)', $word, $date) : sprintf(' (%s %s, %d)', $word, $date, $n);
			// Nextcloud allows 255 bytes per name.
			$room = 250 - strlen($suffix . $extension);
			$name = (strlen($base) > $room ? mb_strcut($base, 0, max(1, $room)) : $base) . $suffix . $extension;
			if (!$exists($name)) {
				return $name;
			}
		}
		throw new \RuntimeException('No free file name for the correction of ' . $oldName);
	}

	/**
	 * Registers the new file for the copy of the old one, as its MusicXML.
	 */
	private function link(File $node, ArchiveFile $old, string $name, string $bytes, int $now): ArchiveFile {
		$directory = dirname($old->getPath());
		$type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$file = new ArchiveFile();
		$file->setFileId($node->getId());
		$file->setCopyId($old->getCopyId());
		$file->setPath($directory === '.' || $directory === '' ? $name : $directory . '/' . $name);
		$file->setName($name);
		$file->setType($type === '' ? null : $type);
		$file->setRole('musicxml');
		$file->setSize((int)$node->getSize());
		$file->setMtime($node->getMTime());
		$file->setEtag($node->getEtag());
		$file->setSha256(hash('sha256', $bytes));
		$file->setSha256At($now);
		$file->setSha256Mismatch(false);
		$file->setListed(true);
		$file->setMusicxmlValid(true);
		$file->setFound(true);
		$file->setSyncedAt($now);
		// A file linked by hand may lie outside the folders the file sync reads.
		$file->setLinkedManually($old->getLinkedManually() === true);
		return $this->files->insert($file);
	}

	/**
	 * A compressed score (.mxl) with the root file replaced; every other
	 * entry is copied unchanged, `mimetype` stays uncompressed.
	 */
	private function repack(string $oldContent, string $entry, string $xml): string {
		$damaged = new InvalidInputException('This file is not a MusicXML score.');
		$source = BoundedZip::open($this->tempManager, $oldContent, 1000, ScoreService::MAX_BYTES, 2 * ScoreService::MAX_BYTES,
			UserFiles::tooLarge(ScoreService::MAX_BYTES), $damaged);
		if ($source === null) {
			throw $damaged;
		}
		$path = $this->tempManager->getTemporaryFile('.mxl');
		if ($path === false) {
			$source->close();
			throw new \RuntimeException('No temporary file');
		}
		$zip = new \ZipArchive();
		try {
			if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('The archive could not be written');
			}
			foreach ($source->names() as $name) {
				if (str_ends_with($name, '/')) {
					$zip->addEmptyDir(rtrim($name, '/'));
					continue;
				}
				$data = $name === $entry ? $xml : ($source->part($name) ?? '');
				$zip->addFromString($name, $data);
				if ($name === 'mimetype') {
					$zip->setCompressionName($name, \ZipArchive::CM_STORE);
				}
			}
			if (!$zip->close()) {
				throw new \RuntimeException('The archive could not be written');
			}
			$bytes = file_get_contents($path);
			if ($bytes === false) {
				throw new \RuntimeException('The archive could not be read');
			}
			return $bytes;
		} finally {
			$source->close();
			@unlink($path);
		}
	}

	/**
	 * @param array<array-key, mixed> $ids
	 * @return list<int>
	 * @throws InvalidInputException
	 */
	private static function pointIds(array $ids): array {
		if (count($ids) > self::MAX_POINTS) {
			throw new InvalidInputException('Too many entries at once (at most %s).', [self::MAX_POINTS]);
		}
		$result = [];
		foreach (array_keys($ids) as $key) {
			$value = filter_var($ids[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($value === false) {
				throw new InvalidInputException('"%s": value not allowed.', ['Review points']);
			}
			$result[] = $value;
		}
		return $result;
	}
}
