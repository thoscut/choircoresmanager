<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use ZipArchive;

/**
 * Stores and serves MusicXML files attached to scores.
 *
 * Files are kept in the app data folder under a name derived from the score ID,
 * never under the uploaded file name. Uploaded content is validated without
 * parsing the XML or extracting compressed archives on the server.
 */
class MusicXmlService {
	public const MAX_UPLOAD_SIZE = 20 * 1024 * 1024;
	/** Limit for the summed uncompressed size of all entries of a compressed (.mxl) file */
	public const MAX_UNCOMPRESSED_SIZE = 100 * 1024 * 1024;
	public const MAX_ZIP_ENTRIES = 200;
	public const MIME_TYPE_XML = 'application/vnd.recordare.musicxml+xml';
	public const MIME_TYPE_COMPRESSED = 'application/vnd.recordare.musicxml';

	private const FOLDER_NAME = 'musicxml';
	private const ALLOWED_EXTENSIONS = ['musicxml', 'xml', 'mxl'];
	private const ZIP_MAGIC = "PK\x03\x04";

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly IAppData $appData,
		private readonly ScoreMapper $scoreMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly ScorePolicy $scorePolicy,
		private readonly PermissionService $permissionService,
		private IL10N $l,
	) {
	}

	/**
	 * Attach a MusicXML file to a score, replacing an existing one.
	 *
	 * @param int $scoreId
	 * @param string $originalName The name of the uploaded file, only used for display
	 * @param string $tmpPath Path to the uploaded temporary file
	 * @return Score The updated score
	 * @throws \InvalidArgumentException if the score does not exist or the file is invalid
	 * @throws Exceptions\PermissionDeniedException
	 * @throws Exception
	 */
	public function uploadForScore(int $scoreId, string $originalName, string $tmpPath): Score {
		$score = $this->findScore($scoreId);
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			throw new \InvalidArgumentException($this->l->t('Only MusicXML files (.musicxml, .xml, .mxl) are allowed'));
		}

		$size = @filesize($tmpPath);
		if ($size === false || $size === 0) {
			throw new \InvalidArgumentException($this->l->t('The uploaded file is empty'));
		}
		if ($size > self::MAX_UPLOAD_SIZE) {
			throw new \InvalidArgumentException($this->l->t('The uploaded file is too large (maximum %d MB)', [self::MAX_UPLOAD_SIZE / 1024 / 1024]));
		}

		$content = file_get_contents($tmpPath);
		if ($content === false) {
			throw new \InvalidArgumentException($this->l->t('The uploaded file could not be read'));
		}

		if (str_starts_with($content, self::ZIP_MAGIC)) {
			$this->validateCompressed($tmpPath);
		} else {
			$this->validateUncompressed($content);
		}

		$folder = $this->getFolder();
		$fileName = $this->getFileName($scoreId);
		try {
			$folder->getFile($fileName)->putContent($content);
		} catch (NotFoundException) {
			$folder->newFile($fileName, $content);
		}

		$score->setMusicxmlFile($this->sanitizeDisplayName($originalName));
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Remove the MusicXML file of a score.
	 *
	 * @param int $scoreId
	 * @return Score The updated score
	 * @throws \InvalidArgumentException if the score does not exist
	 * @throws Exceptions\PermissionDeniedException
	 * @throws Exception
	 */
	public function deleteForScore(int $scoreId): Score {
		$score = $this->findScore($scoreId);
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$this->removeFile($scoreId);
		$score->setMusicxmlFile(null);
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Get the MusicXML file of a score for an authenticated user.
	 *
	 * Files of internal preservation copies are only served to editors.
	 *
	 * @param int $scoreId
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the score or file does not exist
	 * @throws Exceptions\PermissionDeniedException
	 */
	public function getForScore(int $scoreId): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);
		$score = $this->findScore($scoreId);
		if (!$this->permissionService->canAccessFiles($score)) {
			throw new Exceptions\PermissionDeniedException($this->l->t('This score is an internal preservation copy'));
		}
		return $this->readFile($score);
	}

	/**
	 * Read the MusicXML file of a score without any authorization check.
	 *
	 * Callers are responsible for making sure the score may be served.
	 *
	 * @param Score $score
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the file does not exist
	 */
	public function readFile(Score $score): array {
		if ($score->getMusicxmlFile() === null) {
			throw new \InvalidArgumentException($this->l->t('No MusicXML file available for this score'));
		}
		try {
			$content = $this->getFolder()->getFile($this->getFileName($score->getId()))->getContent();
		} catch (NotFoundException) {
			throw new \InvalidArgumentException($this->l->t('No MusicXML file available for this score'));
		}

		$compressed = str_starts_with($content, self::ZIP_MAGIC);
		return [
			'content' => $content,
			'mimeType' => $compressed ? self::MIME_TYPE_COMPRESSED : self::MIME_TYPE_XML,
			'fileName' => 'score-' . $score->getId() . ($compressed ? '.mxl' : '.musicxml'),
		];
	}

	/**
	 * Remove the stored file of a score, if any. Does not touch the score itself.
	 *
	 * @param int $scoreId
	 */
	public function removeFile(int $scoreId): void {
		try {
			$this->getFolder()->getFile($this->getFileName($scoreId))->delete();
		} catch (NotFoundException) {
			// nothing to delete
		}
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
			throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid MusicXML file'));
		}
		if (stripos($content, '<!ENTITY') !== false) {
			throw new \InvalidArgumentException($this->l->t('MusicXML files with entity declarations are not supported'));
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
			throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid compressed MusicXML file'));
		}

		try {
			if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
				throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid compressed MusicXML file'));
			}

			$totalSize = 0;
			$hasScore = false;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid compressed MusicXML file'));
				}
				$totalSize += (int)$stat['size'];
				$entryName = (string)$stat['name'];
				$extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
				if ($entryName === 'META-INF/container.xml' || $extension === 'musicxml' || $extension === 'xml') {
					$hasScore = true;
				}
			}

			if ($totalSize > self::MAX_UNCOMPRESSED_SIZE) {
				throw new \InvalidArgumentException($this->l->t('The uploaded file is too large when uncompressed'));
			}
			if (!$hasScore) {
				throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid compressed MusicXML file'));
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Turn an uploaded file name into a safe name for display.
	 */
	private function sanitizeDisplayName(string $name): string {
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = mb_substr(trim($name), 0, 255);
		return $name === '' ? 'score.musicxml' : $name;
	}

	private function getFileName(int $scoreId): string {
		return 'score-' . $scoreId;
	}

	private function getFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER_NAME);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER_NAME);
		}
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
