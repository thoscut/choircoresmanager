<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\Utility\FileNameHelper;
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
use OCP\IUserSession;
use OCP\Lock\LockedException;

/**
 * Links PDF files (e.g. scanned sheet music) from Nextcloud Files to scores and serves their content.
 *
 * Works like MusicXmlService: the files stay where the club keeps them, a score only
 * stores the file id and the user id of the person who linked the file, and the file
 * is always read with that user's access. The content is checked when linking and
 * again whenever it is served, because the file can change in Files at any time.
 */
class PdfService {
	public const MAX_FILE_SIZE = 50 * 1024 * 1024;
	public const MIME_TYPE = 'application/pdf';

	private const PDF_MAGIC = '%PDF-';

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ScoreMapper $scoreMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly ScorePolicy $scorePolicy,
		private IL10N $l,
	) {
	}

	/**
	 * Link a PDF file from the current user's files to a score, replacing an existing link.
	 *
	 * @param int $scoreId
	 * @param int $fileId The Nextcloud file id of the PDF file
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

		$score->setPdfFile(FileNameHelper::sanitizeDisplayName($file->getName(), 'score.pdf'));
		$score->setPdfFileId($fileId);
		$score->setPdfLinkedBy($user->getUID());
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Remove the link to the PDF file of a score. The file itself is not touched.
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

		$score->setPdfFile(null);
		$score->setPdfFileId(null);
		$score->setPdfLinkedBy(null);
		$this->scoreMapper->update($score);
		return $this->scoreMapper->find($scoreId);
	}

	/**
	 * Get the PDF file of a score for an authenticated user.
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
		$this->authorizationService->authorizePolicy($this->scorePolicy, ScorePolicy::ACTION_READ_PDF, $score);
		return $this->readFile($score);
	}

	/**
	 * Read the linked PDF file of a score without any authorization check.
	 *
	 * Callers are responsible for making sure the score may be served.
	 *
	 * @param Score $score
	 * @return array{content: string, mimeType: string, fileName: string}
	 * @throws Exceptions\NotFoundException if no file is linked or the linked file is missing, not accessible or invalid
	 */
	public function readFile(Score $score): array {
		$fileId = $score->getPdfFileId();
		$userId = $score->getPdfLinkedBy();
		$file = $fileId === null || $userId === null || $userId === '' ? null : $this->findReadableFile($userId, $fileId);
		if ($file === null) {
			throw new Exceptions\NotFoundException($fileId === null
				? $this->l->t('No PDF file is linked to this score')
				: $this->l->t('The linked PDF file is missing or no longer accessible.'));
		}

		try {
			$content = $this->readValidatedContent($file);
		} catch (\InvalidArgumentException $e) {
			throw new Exceptions\NotFoundException($e->getMessage(), 0, $e);
		}

		return [
			'content' => $content,
			'mimeType' => self::MIME_TYPE,
			'fileName' => 'score-' . $score->getId() . '.pdf',
		];
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
	 * Read a file and check that it is a PDF file.
	 *
	 * @param File $file
	 * @return string The content
	 * @throws \InvalidArgumentException if the file cannot be read or is not a valid PDF file
	 */
	private function readValidatedContent(File $file): string {
		if (strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) !== 'pdf') {
			throw new \InvalidArgumentException($this->l->t('Only PDF files are allowed'));
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
		// The PDF header may be preceded by a few bytes of garbage, which readers accept
		if (!str_contains(substr($content, 0, 1024), self::PDF_MAGIC)) {
			throw new \InvalidArgumentException($this->l->t('The file is not a valid PDF file'));
		}
		return $content;
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
