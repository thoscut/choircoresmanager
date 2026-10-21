<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Service;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Policy\PolicyInterface;
use OCA\OrchestraScoresManager\Policy\ScorePolicy;
use OCA\OrchestraScoresManager\Utility\FileNameHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;

/**
 * Stores and serves PDF files (e.g. scanned sheet music) attached to scores.
 *
 * Files are kept in the app data folder under a name derived from the score ID,
 * never under the uploaded file name.
 */
class PdfService {
	public const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;
	public const MIME_TYPE = 'application/pdf';

	private const FOLDER_NAME = 'pdf';
	private const PDF_MAGIC = '%PDF-';

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
	 * Attach a PDF file to a score, replacing an existing one.
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

		if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
			throw new \InvalidArgumentException($this->l->t('Only PDF files are allowed'));
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
		// The PDF header may be preceded by a few bytes of garbage, which readers accept
		$headerPosition = strpos(substr($content, 0, 1024), self::PDF_MAGIC);
		if ($headerPosition === false) {
			throw new \InvalidArgumentException($this->l->t('The uploaded file is not a valid PDF file'));
		}

		$folder = $this->getFolder();
		$fileName = $this->getFileName($scoreId);
		try {
			$folder->getFile($fileName)->putContent($content);
		} catch (NotFoundException) {
			$folder->newFile($fileName, $content);
		}

		$score->setPdfFile(FileNameHelper::sanitizeDisplayName($originalName, 'score.pdf'));
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Remove the PDF file of a score.
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
		$score->setPdfFile(null);
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Get the PDF file of a score for an authenticated user.
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
	 * Read the PDF file of a score without any authorization check.
	 *
	 * Callers are responsible for making sure the score may be served.
	 *
	 * @param Score $score
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws \InvalidArgumentException if the file does not exist
	 */
	public function readFile(Score $score): array {
		if ($score->getPdfFile() === null) {
			throw new \InvalidArgumentException($this->l->t('No PDF file available for this score'));
		}
		try {
			$content = $this->getFolder()->getFile($this->getFileName($score->getId()))->getContent();
		} catch (NotFoundException) {
			throw new \InvalidArgumentException($this->l->t('No PDF file available for this score'));
		}

		return [
			'content' => $content,
			'mimeType' => self::MIME_TYPE,
			'fileName' => 'score-' . $score->getId() . '.pdf',
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
