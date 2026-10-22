<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use ZipArchive;

/**
 * Links MusicXML files from Nextcloud Files to scores and serves their content.
 *
 * The files stay where the club keeps them (e.g. a group folder), so backups and
 * checksums of that folder cover them. A score only stores the file id and the
 * user id of the person who linked the file; the file is always read with that
 * user's access, also for anonymous visitors of the public page.
 *
 * The content is validated when linking and again whenever it is served, because
 * the file can change in Files at any time. The XML is never parsed and compressed
 * archives are never extracted on the server.
 */
class MusicXmlService {
	public const MAX_FILE_SIZE = 20 * 1024 * 1024;
	/** Limit for the summed uncompressed size of all entries of a compressed (.mxl) file */
	public const MAX_UNCOMPRESSED_SIZE = 100 * 1024 * 1024;
	public const MAX_ZIP_ENTRIES = 200;
	public const MIME_TYPE_XML = 'application/vnd.recordare.musicxml+xml';
	public const MIME_TYPE_COMPRESSED = 'application/vnd.recordare.musicxml';

	private const ALLOWED_EXTENSIONS = ['musicxml', 'xml', 'mxl'];
	private const ZIP_MAGIC = "PK\x03\x04";

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ITempManager $tempManager,
		private readonly ScoreMapper $scoreMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly ScorePolicy $scorePolicy,
		private IL10N $l,
	) {
	}

	/**
	 * Link a MusicXML file from the current user's files to a score, replacing an existing link.
	 *
	 * @param int $scoreId
	 * @param int $fileId The Nextcloud file id of the MusicXML file
	 * @return Score The updated score
	 * @throws \InvalidArgumentException if the score does not exist, the file cannot be read or is invalid
	 * @throws Exceptions\PermissionDeniedException
	 * @throws Exception
	 */
	public function linkForScore(int $scoreId, int $fileId): Score {
		$score = $this->findScore($scoreId);
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exceptions\PermissionDeniedException($this->l->t('Insufficient permissions'));
		}

		$file = $this->findReadableFile($user->getUID(), $fileId);
		if ($file === null) {
			throw new \InvalidArgumentException($this->l->t('The selected file does not exist or you are not allowed to read it'));
		}
		$this->readValidatedContent($file);

		$score->setMusicxmlFile($this->sanitizeDisplayName($file->getName()));
		$score->setMusicxmlFileId($fileId);
		$score->setMusicxmlLinkedBy($user->getUID());
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Remove the link to the MusicXML file of a score. The file itself is not touched.
	 *
	 * @param int $scoreId
	 * @return Score The updated score
	 * @throws \InvalidArgumentException if the score does not exist
	 * @throws Exceptions\PermissionDeniedException
	 * @throws Exception
	 */
	public function unlinkForScore(int $scoreId): Score {
		$score = $this->findScore($scoreId);
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$score->setMusicxmlFile(null);
		$score->setMusicxmlFileId(null);
		$score->setMusicxmlLinkedBy(null);
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Get the MusicXML file of a score for an authenticated user.
	 *
	 * The file of an internal preservation copy is only served to editors.
	 *
	 * @param int $scoreId
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the score does not exist
	 * @throws Exceptions\NotFoundException if no file is linked or the linked file is missing, not accessible or invalid
	 * @throws Exceptions\PermissionDeniedException
	 */
	public function getForScore(int $scoreId): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);
		$score = $this->findScore($scoreId);
		$this->authorizationService->authorizePolicy($this->scorePolicy, ScorePolicy::ACTION_READ_MUSICXML, $score);
		return $this->readFile($score);
	}

	/**
	 * Read the linked MusicXML file of a score without any authorization check.
	 *
	 * Callers are responsible for making sure the score may be served.
	 *
	 * @param Score $score
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws Exceptions\NotFoundException if no file is linked or the linked file is missing, not accessible or invalid
	 */
	public function readFile(Score $score): array {
		$file = $this->findLinkedFile($score);
		if ($file === null) {
			throw new Exceptions\NotFoundException($score->getMusicxmlFileId() === null
				? $this->l->t('No MusicXML file is linked to this score')
				: $this->l->t('The linked MusicXML file is missing or no longer accessible.'));
		}

		try {
			$content = $this->readValidatedContent($file);
		} catch (\InvalidArgumentException $e) {
			throw new Exceptions\NotFoundException($e->getMessage(), 0, $e);
		}

		$compressed = str_starts_with($content, self::ZIP_MAGIC);
		return [
			'content' => $content,
			'mimeType' => $compressed ? self::MIME_TYPE_COMPRESSED : self::MIME_TYPE_XML,
			'fileName' => 'score-' . $score->getId() . ($compressed ? '.mxl' : '.musicxml'),
		];
	}

	/**
	 * Get the time the linked MusicXML file of a score was last modified, without any authorization check.
	 *
	 * Only the file's metadata is checked, its content is not read.
	 *
	 * @param Score $score
	 * @return int|null Unix timestamp, or null if the score has no readable MusicXML file
	 */
	public function getFileMTime(Score $score): ?int {
		$file = $this->findLinkedFile($score);
		if ($file === null) {
			return null;
		}
		try {
			$size = $file->getSize();
			if (!$this->hasAllowedExtension($file->getName()) || $size <= 0 || $size > self::MAX_FILE_SIZE) {
				return null;
			}
			return $file->getMTime();
		} catch (InvalidPathException|FileNotFoundException) {
			return null;
		}
	}

	/**
	 * Find the linked file of a score through the user who linked it.
	 *
	 * @param Score $score
	 * @return File|null The file, or null if no file is linked or it is missing or not accessible
	 */
	private function findLinkedFile(Score $score): ?File {
		$fileId = $score->getMusicxmlFileId();
		$userId = $score->getMusicxmlLinkedBy();
		if ($fileId === null || $userId === null || $userId === '') {
			return null;
		}
		return $this->findReadableFile($userId, $fileId);
	}

	/**
	 * Find a file by id that the given user can read.
	 *
	 * @param string $userId
	 * @param int $fileId
	 * @return File|null The file, or null if the user cannot reach a readable file with this id
	 */
	private function findReadableFile(string $userId, int $fileId): ?File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Exception) {
			// The user does not exist (anymore) or has no files
			return null;
		}

		// A file can be reachable several times (e.g. shared and in a group folder) with different permissions
		foreach ($userFolder->getById($fileId) as $node) {
			try {
				if ($node instanceof File && $node->isReadable()) {
					return $node;
				}
			} catch (InvalidPathException|FileNotFoundException) {
				continue;
			}
		}
		return null;
	}

	/**
	 * Read a file and check that it is a MusicXML file.
	 *
	 * @param File $file
	 * @return string The content
	 * @throws \InvalidArgumentException if the file cannot be read or is not a valid MusicXML file
	 */
	private function readValidatedContent(File $file): string {
		if (!$this->hasAllowedExtension($file->getName())) {
			throw new \InvalidArgumentException($this->l->t('Only MusicXML files (.musicxml, .xml, .mxl) are allowed'));
		}

		try {
			$size = $file->getSize();
		} catch (InvalidPathException|FileNotFoundException) {
			throw new \InvalidArgumentException($this->l->t('The file could not be read'));
		}
		if ($size > self::MAX_FILE_SIZE) {
			throw new \InvalidArgumentException($this->l->t('The file is too large (maximum %d MB)', [self::MAX_FILE_SIZE / 1024 / 1024]));
		}

		try {
			$content = $file->getContent();
		} catch (NotPermittedException|GenericFileException|LockedException|FileNotFoundException) {
			throw new \InvalidArgumentException($this->l->t('The file could not be read'));
		}

		if ($content === '') {
			throw new \InvalidArgumentException($this->l->t('The file is empty'));
		}
		// the size in the file cache may be outdated
		if (strlen($content) > self::MAX_FILE_SIZE) {
			throw new \InvalidArgumentException($this->l->t('The file is too large (maximum %d MB)', [self::MAX_FILE_SIZE / 1024 / 1024]));
		}

		if (str_starts_with($content, self::ZIP_MAGIC)) {
			$this->validateCompressedContent($content);
		} else {
			$this->validateUncompressed($content);
		}
		return $content;
	}

	private function hasAllowedExtension(string $name): bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true);
	}

	/**
	 * Check that uncompressed content looks like a MusicXML document.
	 *
	 * The XML is deliberately not parsed on the server. Documents declaring
	 * entities are rejected as MusicXML does not need them.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateUncompressed(string $content): void {
		$head = substr($content, 0, 8192);
		if (!str_contains($head, '<score-partwise') && !str_contains($head, '<score-timewise')) {
			throw new \InvalidArgumentException($this->l->t('The file is not a valid MusicXML file'));
		}
		if (stripos($content, '<!ENTITY') !== false) {
			throw new \InvalidArgumentException($this->l->t('MusicXML files with entity declarations are not supported'));
		}
	}

	/**
	 * Check compressed content via a temporary file, as ZipArchive needs a file on disk.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateCompressedContent(string $content): void {
		$path = $this->tempManager->getTemporaryFile('.mxl');
		if ($path === false || file_put_contents($path, $content) === false) {
			throw new \InvalidArgumentException($this->l->t('The file could not be read'));
		}
		try {
			$this->validateCompressed($path);
		} finally {
			@unlink($path);
		}
	}

	/**
	 * Check that a compressed MusicXML (.mxl) file is a well-formed and reasonably sized ZIP archive.
	 *
	 * Only the archive's directory is inspected, nothing is extracted.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateCompressed(string $path): void {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new \InvalidArgumentException($this->l->t('The file is not a valid compressed MusicXML file'));
		}

		try {
			if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
				throw new \InvalidArgumentException($this->l->t('The file is not a valid compressed MusicXML file'));
			}

			$totalSize = 0;
			$hasScore = false;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					throw new \InvalidArgumentException($this->l->t('The file is not a valid compressed MusicXML file'));
				}
				$totalSize += (int)$stat['size'];
				$entryName = (string)$stat['name'];
				$extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
				if ($entryName === 'META-INF/container.xml' || $extension === 'musicxml' || $extension === 'xml') {
					$hasScore = true;
				}
			}

			if ($totalSize > self::MAX_UNCOMPRESSED_SIZE) {
				throw new \InvalidArgumentException($this->l->t('The file is too large when uncompressed'));
			}
			if (!$hasScore) {
				throw new \InvalidArgumentException($this->l->t('The file is not a valid compressed MusicXML file'));
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Turn a file name into a safe name for display.
	 */
	private function sanitizeDisplayName(string $name): string {
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = mb_substr(trim($name), 0, 255);
		return $name === '' ? 'score.musicxml' : $name;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function findScore(int $scoreId): Score {
		try {
			return $this->scoreMapper->find($scoreId);
		} catch (MultipleObjectsReturnedException|DoesNotExistException|Exception) {
			throw new \InvalidArgumentException($this->l->t('Score not found'));
		}
	}
}
