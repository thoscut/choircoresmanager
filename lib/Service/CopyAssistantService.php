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
use OCA\Notenarchiv\Db\ContributionMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\PersonMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * New accessions: the next free shelf mark, a new work with version and
 * copy in one step, manual links to existing files, and the data for shelf
 * labels (the print layout is done by the UI).
 *
 * Shelf mark numbers are never reused, not even those of retired copies, and
 * gaps are never filled: the next number is always one above the highest
 * number ever used in the binder, by a copy or by a file name in the archive
 * folder. New copies go only into the binders the librarian has opened for
 * accessions (setting `open_binders`, default: the highest existing binder).
 *
 * A manually linked file keeps its copy: the file sync never moves it.
 *
 * @psalm-import-type FieldInfo from Fields
 */
class CopyAssistantService {
	public const OPEN_BINDERS_KEY = 'open_binders';
	private const MAX_BINDER = 999;
	private const MAX_NUMBER = 99999;

	public function __construct(
		private IDBConnection $db,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ArchiveFileMapper $files,
		private ContributionMapper $contributions,
		private PersonMapper $persons,
		private AppSettings $settings,
		private AuditLog $audit,
		private Roles $roles,
		private RightsService $rights,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Binders open for new copies, ascending.
	 *
	 * @return list<int>
	 */
	public function openBinders(): array {
		$stored = array_values(array_filter(array_map('intval', $this->appConfig->getValueArray(Application::APP_ID, self::OPEN_BINDERS_KEY, [])),
			fn (int $b): bool => $b >= 1 && $b <= self::MAX_BINDER));
		if ($stored !== []) {
			sort($stored);
			return array_values(array_unique($stored));
		}
		$highest = $this->highestNumbers();
		return [$highest === [] ? 1 : max(array_keys($highest))];
	}

	/**
	 * @param array<array-key, mixed> $binders binder numbers (request data)
	 * @return list<int>
	 */
	public function setOpenBinders(array $binders, string $uid): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$clean = [];
		foreach (array_keys($binders) as $index) {
			$value = filter_var($binders[$index], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_BINDER]]);
			if ($value === false) {
				throw new InvalidInputException('"%s" must be a whole number.', ['Binder']);
			}
			$clean[$value] = $value;
		}
		if ($clean === []) {
			throw new InvalidInputException('At least one binder must be open for new copies.');
		}
		ksort($clean);
		$old = $this->openBinders();
		$this->appConfig->setValueArray(Application::APP_ID, self::OPEN_BINDERS_KEY, array_values($clean));
		$this->audit->record($this->audit->newOperation(), $uid, 'settings', null, 'changed', self::OPEN_BINDERS_KEY,
			implode(',', $old), implode(',', $clean));
		return array_values($clean);
	}

	/**
	 * The proposed next shelf mark, plus the next number of every open binder.
	 *
	 * @return array{proposal: array{binder: int, number: int, shelfmark: string}, binders: list<array{binder: int, highest: int, next: int}>, openBinders: list<int>}
	 */
	public function next(?int $binder = null): array {
		$open = $this->openBinders();
		$highest = $this->highestNumbers();
		$binders = array_map(fn (int $b): array => ['binder' => $b, 'highest' => $highest[$b] ?? 0, 'next' => ($highest[$b] ?? 0) + 1], $open);
		$chosen = $binder ?? $open[0];
		if (!in_array($chosen, $open, true)) {
			throw new InvalidInputException('Binder %s is not open for new copies.', [$chosen]);
		}
		$number = ($highest[$chosen] ?? 0) + 1;
		return ['proposal' => ['binder' => $chosen, 'number' => $number, 'shelfmark' => $chosen . '-' . $number],
			'binders' => $binders, 'openBinders' => $open];
	}

	/**
	 * Creates work, version and copy of a new accession, optionally linked to
	 * a file in the archive folder.
	 *
	 * Required: title, composer, title_source, accession_date, origin. The
	 * rights status defaults to `open`; accession date and origin are written
	 * into the notes of the version.
	 *
	 * @param array<array-key, mixed> $data field => value (request data)
	 * @return array<string, mixed> ids, shelf mark and label data
	 */
	public function create(array $data, ?int $fileId, string $uid): array {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$data = Input::values($data);
		$allowed = ['binder', 'number', 'title', 'composer', 'arranger_text', 'title_source', 'accession_date', 'origin', 'rights_status',
			'version_kind', 'kind', 'pages', 'originals_count', 'copies_count', 'notes', 'publisher'];
		foreach (array_keys($data) as $field) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		$open = $this->openBinders();
		$binder = self::int($data['binder'] ?? null, 'Binder', 1, self::MAX_BINDER) ?? $open[0];
		if (!in_array($binder, $open, true)) {
			throw new InvalidInputException('Binder %s is not open for new copies.', [$binder]);
		}
		$highest = $this->highestNumbers()[$binder] ?? 0;
		$number = self::int($data['number'] ?? null, 'No.', 1, self::MAX_NUMBER) ?? $highest + 1;
		if ($number <= $highest) {
			throw new InvalidInputException('Shelf mark numbers are never reused: the next free number in binder %1$s is %2$s.', [$binder, $highest + 1]);
		}

		$work = Fields::all()[Fields::WORK];
		$version = Fields::all()[Fields::VERSION];
		$copy = Fields::all()[Fields::COPY];
		$title = self::text($data['title'] ?? null, $work['title']);
		$composer = self::text($data['composer'] ?? null, $work['composer']);
		$titleSource = self::text($data['title_source'] ?? null, $copy['title_source']);
		$origin = self::text($data['origin'] ?? null, ['label' => 'Origin', 'max' => 1000]);
		$accession = Input::text($data['accession_date'] ?? null, 'Accession date');
		$required = ['Title' => $title, 'Composer' => $composer, 'Title source' => $titleSource, 'Accession date' => $accession, 'Origin' => $origin];
		foreach ($required as $label => $value) {
			if ($value === null || $value === '') {
				throw new InvalidInputException('Please fill in "%s".', [$label]);
			}
		}
		if ($accession === null || !FieldValue::isDate($accession)) {
			throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Accession date']);
		}
		if ($accession > $this->rights->today($uid)) {
			throw new InvalidInputException('The accession date must not lie in the future.');
		}
		$rightsStatus = Input::text($data['rights_status'] ?? null, 'Rights status') ?? 'open';
		if (!isset(Fields::RIGHTS_STATUS[$rightsStatus])) {
			throw new InvalidInputException('"%s": value not allowed.', ['Rights status']);
		}
		$versionKind = self::choice($data['version_kind'] ?? null, $version['kind']);
		$copyKind = self::choice($data['kind'] ?? null, $copy['kind']);
		$arranger = self::text($data['arranger_text'] ?? null, $version['arranger_text']);
		$publisher = self::text($data['publisher'] ?? null, $version['publisher']);
		$pages = self::text($data['pages'] ?? null, $copy['pages']);
		$originals = self::int($data['originals_count'] ?? null, 'Number of originals', 0, 100000);
		$copiesCount = self::int($data['copies_count'] ?? null, 'Number of copies', 0, 100000);
		$extraNotes = self::text($data['notes'] ?? null, ['label' => 'Notes', 'max' => 10000]);

		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($this->userManager->get($uid)));
		$notes = $l->t('Accession on %1$s from: %2$s', [$accession, str_replace('%', '%%', (string)$origin)])
			. ($extraNotes === null ? '' : "\n" . $extraNotes);

		$now = $this->time->getTime();
		$shelfmark = $binder . '-' . $number;
		$sortOrder = 0;
		foreach ($this->copies->findAll() as $existing) {
			$sortOrder = max($sortOrder, $existing->getSortOrder());
		}
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$newWork = new Work();
			$newWork->setTitle($title);
			$newWork->setComposer($composer);
			$newWork->setCreated($now);
			$newWork->setModified($now);
			$newWork = $this->works->insert($newWork);

			$newVersion = new WorkVersion();
			$newVersion->setWorkId($newWork->getId());
			$newVersion->setValue('kind', $versionKind);
			$newVersion->setValue('arranger_text', $arranger);
			$newVersion->setValue('publisher', $publisher);
			$newVersion->setValue('rights_status', $rightsStatus);
			$newVersion->setValue('preservation_copy', false);
			$newVersion->setValue('notes', $notes);
			$newVersion->setCreated($now);
			$newVersion->setModified($now);
			$newVersion = $this->versions->insert($newVersion);

			$newCopy = new Copy();
			$newCopy->setVersionId($newVersion->getId());
			$newCopy->setBinder($binder);
			$newCopy->setNumber($number);
			$newCopy->setShelfmark($shelfmark);
			$newCopy->setSortOrder($sortOrder + 1);
			$newCopy->setStatus('active');
			$newCopy->setValue('status_since', $accession);
			$newCopy->setValue('kind', $copyKind);
			$newCopy->setValue('title_source', $titleSource);
			$newCopy->setValue('pages', $pages);
			$newCopy->setValue('originals_count', $originals);
			$newCopy->setValue('copies_count', $copiesCount);
			$newCopy->setCreated($now);
			$newCopy->setModified($now);
			$newCopy = $this->copies->insert($newCopy);

			$this->audit->record($operation, $uid, Fields::WORK, $newWork->getId(), 'created', 'title', null, $title, 'accession');
			$this->audit->record($operation, $uid, Fields::VERSION, $newVersion->getId(), 'created', 'rights_status', null, $rightsStatus, 'accession');
			$this->audit->record($operation, $uid, Fields::COPY, $newCopy->getId(), 'created', 'shelfmark', null, $shelfmark, 'accession');
			if ($fileId !== null) {
				$this->link($newCopy, $fileId, $uid, $operation);
			}
			$this->db->commit();
		} catch (DbException $e) {
			$this->db->rollBack();
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new InvalidInputException('Shelf mark %s is already taken.', [$shelfmark]);
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['copyId' => $newCopy->getId(), 'versionId' => $newVersion->getId(), 'workId' => $newWork->getId(),
			'shelfmark' => $shelfmark, 'label' => $this->labels([$newCopy->getId()])[0] ?? null];
	}

	/**
	 * Links a file of the archive folder to a copy by hand. The file sync will
	 * not move it to another copy.
	 */
	public function linkFile(int $copyId, int $fileId, string $uid): ArchiveFile {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$copy = $this->copies->find($copyId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		return $this->link($copy, $fileId, $uid, $this->audit->newOperation());
	}

	/**
	 * Removes a manual link. The next file sync assigns the file by its name
	 * again, if the name starts with a shelf mark.
	 */
	public function unlinkFile(int $archiveFileId, string $uid): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		try {
			$file = $this->files->find($archiveFileId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		if ($file->getLinkedManually() !== true) {
			throw new InvalidInputException('Only files linked by hand can be unlinked; the others follow their file name.');
		}
		$old = $file->getCopyId();
		$file->setLinkedManually(false);
		$file->setCopyId(null);
		$this->files->update($file);
		$this->audit->record($this->audit->newOperation(), $uid, 'file', $file->getId(), 'unlinked', 'copy_id', $old, null);
	}

	/**
	 * Data for shelf labels, e.g. Avery 3653: shelf mark as "9 / 47", title,
	 * composer and arrangers. The layout is up to the UI.
	 *
	 * @param list<int> $copyIds
	 * @return list<array<string, mixed>>
	 */
	public function labels(array $copyIds): array {
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$persons = $this->persons->findAllById();
		$contributions = $this->contributions->findAllByVersion();
		$rows = [];
		foreach ($copyIds as $copyId) {
			try {
				$copy = $this->copies->find($copyId);
			} catch (DoesNotExistException) {
				continue;
			}
			$version = $versions[$copy->getVersionId()] ?? null;
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$arrangers = [];
			foreach ($contributions[$copy->getVersionId()] ?? [] as $contribution) {
				if ($contribution->getRole() === 'arranger' && isset($persons[$contribution->getPersonId()])) {
					$arrangers[] = $persons[$contribution->getPersonId()]->getName();
				}
			}
			$rows[] = [
				'copyId' => $copy->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'binder' => $copy->getBinder(),
				'number' => $copy->getNumber(),
				'label' => $copy->getBinder() . ' / ' . $copy->getNumber(),
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'arranger_text' => $version?->getValue('arranger_text'),
				'arrangers' => array_values(array_unique($arrangers)),
			];
		}
		return $rows;
	}

	/**
	 * Highest number ever used per binder: by any copy (whatever its status)
	 * or by a file name in the archive folder starting with a shelf mark.
	 *
	 * @return array<int, int> binder => highest number
	 */
	public function highestNumbers(): array {
		$highest = [];
		foreach ($this->copies->findAll() as $copy) {
			$highest[$copy->getBinder()] = max($highest[$copy->getBinder()] ?? 0, $copy->getNumber());
		}
		foreach ($this->files->findAllByFileId() as $file) {
			if (preg_match('/^(\d+)-(\d+)(?!\d)/', $file->getName(), $m)) {
				$highest[(int)$m[1]] = max($highest[(int)$m[1]] ?? 0, (int)$m[2]);
			}
		}
		ksort($highest);
		return $highest;
	}

	private function link(Copy $copy, int $fileId, string $uid, string $operation): ArchiveFile {
		$archive = $this->settings->archiveFolder($uid);
		$node = $archive->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			throw new InvalidInputException('The selected file is not in the archive folder or not accessible.');
		}
		try {
			$file = $this->files->findByFileId($fileId);
			$isNew = false;
		} catch (DoesNotExistException) {
			$file = new ArchiveFile();
			$file->setFileId($fileId);
			$file->setListed(false);
			$isNew = true;
		}
		$old = $isNew ? null : $file->getCopyId();
		$name = $node->getName();
		$type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$version = $this->versions->find($copy->getVersionId());
		$file->setCopyId($copy->getId());
		$file->setLinkedManually(true);
		$file->setPath(ltrim($archive->getRelativePath($node->getPath()) ?? $name, '/'));
		$file->setName($name);
		$file->setType($type === '' ? null : $type);
		$file->setRole(FileSyncService::roleOf($name));
		$file->setSize((int)$node->getSize());
		$file->setMtime($node->getMTime());
		$file->setEtag($node->getEtag());
		$file->setMusicxmlValid($version->getMusicxmlValid() === $name);
		$file->setFound(true);
		$file->setSyncedAt($this->time->getTime());
		$file = $isNew ? $this->files->insert($file) : $this->files->update($file);
		if ($old !== $copy->getId()) {
			$this->audit->record($operation, $uid, 'file', $file->getId(), 'linked', 'copy_id', $old, $copy->getId(), $file->getPath());
		}
		return $file;
	}

	/**
	 * @param FieldInfo|array{label: string, max: int} $info
	 */
	private static function text(string|int|float|bool|null $value, array $info): ?string {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidInputException('"%s" must be text.', [$info['label']]);
		}
		$value = trim($value);
		if (isset($info['max']) && mb_strlen($value) > $info['max']) {
			throw new InvalidInputException('"%s" is too long.', [$info['label']]);
		}
		return $value;
	}

	/**
	 * @param FieldInfo $info
	 */
	private static function choice(string|int|float|bool|null $value, array $info): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value) || !isset($info['options'][$value])) {
			throw new InvalidInputException('"%s": value not allowed.', [$info['label']]);
		}
		return $value;
	}

	private static function int(string|int|float|bool|null $value, string $label, int $min, int $max): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
		if ($int === false) {
			throw new InvalidInputException('"%s" must be a whole number.', [$label]);
		}
		return $int;
	}
}
