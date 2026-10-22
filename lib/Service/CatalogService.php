<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\AuditEntry;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Db\Task;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Reading and maintaining the catalog. Every change is checked against the
 * write roles of each field and recorded in the audit log.
 *
 * @psalm-import-type FieldInfo from Fields
 * @psalm-import-type Proposal from ProtectionTerm as ProtectionProposal
 */
class CatalogService {
	public function __construct(
		private IDBConnection $db,
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private ArchiveFileMapper $files,
		private AuditEntryMapper $auditEntries,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private ScoreService $scores,
		private RightsService $rights,
		private LicenseMapper $licenses,
		private ProposalMapper $proposals,
		private IUserManager $userManager,
		private TaskService $taskService,
		private LinkService $linkService,
		private RepertoireService $repertoire,
		private PersonService $persons,
		private MergeService $merges,
	) {
	}

	/**
	 * The work a version belongs to after merges: follows `merged_into` to
	 * the work that is listed (a merged work has no versions of its own, so
	 * this only matters for data that changed outside the app).
	 *
	 * @param array<int, Work> $works keyed by id
	 */
	public static function listedWork(?Work $work, array $works): ?Work {
		for ($hops = 0; $work !== null && $hops < 20; $hops++) {
			$mergedInto = $work->getMergedInto();
			if ($mergedInto === null) {
				break;
			}
			$work = $works[$mergedInto] ?? $work;
		}
		return $work;
	}

	/** Imported repertoire values of the legacy catalog => class */
	private const LEGACY_REPERTOIRE = ['aktuell' => 'current', 'ruhend' => 'dormant', 'früher' => 'earlier', 'nicht belegt' => 'none'];

	/**
	 * Repertoire class, performance count and last date: derived from
	 * documented performances when there are any, otherwise the imported
	 * values mapped to the same classes.
	 *
	 * @param array<int, array{performances: int, first: ?string, last: ?string, class: string, songLists: list<string>}> $derived
	 * @return array{repertoire_class: ?string, repertoire_source: string, performances: ?int, last_performed_on: ?string, song_lists: list<string>}
	 */
	public static function repertoireFigures(?Work $work, array $derived): array {
		$figures = $work === null ? null : ($derived[$work->getId()] ?? null);
		if ($figures !== null && $figures['performances'] > 0) {
			return ['repertoire_class' => $figures['class'], 'repertoire_source' => 'performances',
				'performances' => $figures['performances'], 'last_performed_on' => $figures['last'], 'song_lists' => $figures['songLists']];
		}
		$imported = $work?->getRepertoireImport();
		$count = $work?->getPerformancesImport();
		return ['repertoire_class' => $imported === null ? null : (self::LEGACY_REPERTOIRE[$imported] ?? null), 'repertoire_source' => 'import',
			'performances' => is_numeric($count) ? (int)$count : null, 'last_performed_on' => $work?->getLastPerformedImport(),
			'song_lists' => $figures['songLists'] ?? []];
	}

	/**
	 * Throws when the entity does not exist.
	 */
	public function entityExists(string $level, int $id): void {
		$this->load($level, $id);
	}

	/**
	 * One row per copy with the data needed for list and filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function entries(): array {
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$fileCounts = $this->files->countPerCopy();
		$derived = $this->repertoire->perWork();

		$rows = [];
		foreach ($this->copies->findAll() as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			$work = self::listedWork($version === null ? null : ($works[$version->getWorkId()] ?? null), $works);
			$counts = $fileCounts[$copy->getId()] ?? [];
			$rows[] = [
				'id' => $copy->getId(),
				'work_id' => $work?->getId(),
				'version_id' => $version?->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'binder' => $copy->getBinder(),
				'number' => $copy->getNumber(),
				'status' => $copy->getStatus(),
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'work_no' => $work?->getWorkNo(),
				'arranger_text' => $version?->getValue('arranger_text'),
				'rights_status' => $version?->getRightsStatus(),
				'rights_confirmed' => $version?->getValue('rights_confirmed'),
				'confirmed_at' => $version?->getValue('confirmed_at'),
				'preservation_copy' => $version?->getPreservationCopy() === true,
				'publication' => $version?->getPublication(),
				'valid_since' => $version?->getValue('valid_since'),
				'repertoire' => $work?->getValue('repertoire_import'),
				'last_performed' => $work?->getValue('last_performed_import'),
				...self::repertoireFigures($work, $derived),
				'has_musicxml' => ($counts['mxl'] ?? 0) + ($counts['musicxml'] ?? 0) > 0 || $version?->getMusicxmlValid() !== null,
				'files' => array_sum($counts),
				'pdfs' => $counts['pdf'] ?? 0,
			];
		}
		return $rows;
	}

	/**
	 * Everything about one copy: work, version, files, other copies of the same
	 * work, persons involved with the protection proposal (display only),
	 * merges that can be undone, audit log.
	 *
	 * @return array{work: Work, version: WorkVersion, copy: Copy, files: list<ArchiveFile>,
	 *     score: ?array{fileId: int, fileName: string, canView: bool, canPlay: bool, reason: ?string}, licenses: list<License>,
	 *     releases: array{portal: ?string, onPortal: bool, printStation: ?string}, tasks: list<Task>, links: list<ExcerptLink>,
	 *     proposals: list<Proposal>, siblings: list<array{id: int, shelfmark: string, version_id: int, status: string}>,
	 *     contributions: list<array<string, string|int|bool|null|array<string, string|int|bool|null>>>, protection: ProtectionProposal,
	 *     merges: list<array<string, mixed>>, audit: list<AuditEntry>}
	 */
	public function entry(int $copyId, string $uid): array {
		$copy = $this->load(Fields::COPY, $copyId);
		assert($copy instanceof Copy);
		$version = $this->versions->find($copy->getVersionId());
		$work = $this->works->find($version->getWorkId());
		if ($work->getMergedInto() !== null) {
			$work = self::listedWork($work, $this->works->findAllById()) ?? $work;
		}

		$siblings = [];
		foreach ($this->versions->findByWork($work->getId()) as $otherVersion) {
			foreach ($this->copies->findByVersion($otherVersion->getId()) as $other) {
				if ($other->getId() !== $copy->getId()) {
					$siblings[] = ['id' => $other->getId(), 'shelfmark' => $other->getShelfmark(),
						'version_id' => $otherVersion->getId(), 'status' => $other->getStatus()];
				}
			}
		}

		$files = $this->files->findByCopy($copy->getId());
		$licenses = $this->licenses->findByVersion($version->getId());
		$day = $this->rights->today($uid);
		return [
			'work' => $work,
			'version' => $version,
			'copy' => $copy,
			'files' => $files,
			'score' => $this->scores->describe($version, $files, $uid),
			'licenses' => $licenses,
			'releases' => [
				'portal' => $this->rights->portalDenial($version, $licenses, $day),
				'onPortal' => $this->rights->isOnPortal($version),
				'printStation' => $this->rights->printStationDenial($version, $licenses, $day),
			],
			'tasks' => $this->taskService->forCopy($copy->getId()),
			'links' => $this->linkService->active($copy->getId(), $uid),
			'proposals' => array_merge(
				$this->proposals->findOpenForObject(Fields::WORK, $work->getId()),
				$this->proposals->findOpenForObject(Fields::VERSION, $version->getId()),
				$this->proposals->findOpenForObject(Fields::COPY, $copy->getId()),
			),
			'siblings' => $siblings,
			'contributions' => $this->persons->forVersion($version->getId()),
			'protection' => $this->persons->protection($version->getId(), (int)substr($day, 0, 4)),
			'merges' => $this->merges->openFor($work->getId(), $copy->getId()),
			'audit' => $this->auditEntries->findForObjects([
				[Fields::WORK, $work->getId()],
				[Fields::VERSION, $version->getId()],
				[Fields::COPY, $copy->getId()],
			]),
		];
	}

	/**
	 * Changes fields of an entity. Each field is checked against the field
	 * registry and the roles of the user; each change is recorded.
	 *
	 * @param array<array-key, mixed> $fields field => value (request data)
	 */
	public function update(string $level, int $id, array $fields, string $uid, ?string $reason): void {
		$registry = Fields::all()[$level] ?? null;
		if ($registry === null) {
			throw new InvalidInputException('Unknown level: %s', [$level]);
		}
		if ($fields === []) {
			return;
		}
		$entity = $this->load($level, $id);

		$new = [];
		foreach (Input::values($fields) as $field => $value) {
			$info = $registry[$field] ?? null;
			if ($info === null) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			if ($info['write'] === []) {
				throw new InvalidInputException('"%s" is read-only.', [$info['label']]);
			}
			$this->roles->requireRole($uid, $info['write']);
			$new[$field] = self::validate($info, $value);
		}

		if ($entity instanceof WorkVersion && array_key_exists('rights_status', $new) && $new['rights_status'] !== $entity->getRightsStatus()) {
			// A confirmation refers to one status; changing it removes the confirmation.
			$new += RightsService::noConfirmation();
		}
		if ($entity instanceof WorkVersion && array_key_exists('rule1_status', $new) && $new['rule1_status'] !== $entity->getValue('rule1_status')) {
			$new['rule1_checked'] = $this->rights->today($uid) . ' ' . ($this->userManager->getDisplayName($uid) ?? $uid);
		}

		if ($entity instanceof Copy && array_key_exists('status', $new) && $new['status'] !== $entity->getStatus()) {
			if ($new['status'] === null) {
				throw new InvalidInputException('The status must not be empty.');
			}
			$statusReason = $new['status_reason'] ?? null;
			if ($new['status'] !== 'active' && ($statusReason === null || trim((string)$statusReason) === '')) {
				throw new InvalidInputException('A reason is required for this status.');
			}
			$new['status_since'] = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid))->format('Y-m-d');
		}

		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			$changed = false;
			foreach ($new as $field => $value) {
				$old = $entity->getValue($field);
				if ($old === $value) {
					continue;
				}
				$entity->setValue($field, $value);
				$this->audit->record($operation, $uid, $level, $id, 'changed', $field, $old, $value, $reason);
				$changed = true;
			}
			if ($changed) {
				$entity->setModified($this->time->getTime());
				match (true) {
					$entity instanceof Work => $this->works->update($entity),
					$entity instanceof WorkVersion => $this->versions->update($entity),
					$entity instanceof Copy => $this->copies->update($entity),
				};
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function load(string $level, int $id): Work|WorkVersion|Copy {
		try {
			return match ($level) {
				Fields::WORK => $this->works->find($id),
				Fields::VERSION => $this->versions->find($id),
				Fields::COPY => $this->copies->find($id),
				default => throw new InvalidInputException('Unknown level: %s', [$level]),
			};
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
	}

	/**
	 * Normalizes a value from the UI and checks it against the field.
	 *
	 * @param FieldInfo $info
	 */
	private static function validate(array $info, string|int|float|bool|null $value): string|int|bool|null {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if ($info['type'] === Fields::TYPE_NUMBER) {
			$value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value;
		} elseif ($info['type'] === Fields::TYPE_BOOLEAN && !is_bool($value)) {
			$value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value;
		}
		$error = FieldValue::check($info, $value);
		if ($error !== null || is_float($value)) {
			throw new InvalidInputException($error ?? '"%s" must be a whole number.', [$info['label']]);
		}
		return $value;
	}
}
