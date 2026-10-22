<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\Task;
use OCA\Notenarchiv\Db\TaskMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Tasks about copies: questions to the conductor, checks and error reports,
 * each with an optional location in the score.
 *
 * - Catalog librarian, conductor, operations and contributors (transcription
 *   rounds) create tasks.
 * - Only the addressed role answers.
 * - Only the catalog librarian closes a task (applied or discarded), because
 *   only the librarian writes the catalog; a check addressed to operations
 *   may also be closed by operations.
 */
class TaskService {
	public const ADDRESSEES = [Roles::CONDUCTOR, Roles::LIBRARIAN, Roles::OPERATIONS, Roles::READER];
	public const CATEGORIES = ['reading', 'structure', 'origin', 'shelf', 'rights', 'other'];
	/** Roles that may create tasks */
	public const CREATORS = [Roles::LIBRARIAN, Roles::CONDUCTOR, Roles::OPERATIONS, Roles::CONTRIBUTOR];
	/** Fields a request may set when creating a task */
	private const FIELDS = ['kind', 'addressee', 'category', 'page', 'verse', 'measure_from', 'measure_to', 'voice', 'origin', 'question', 'due'];

	public function __construct(
		private TaskMapper $tasks,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private AuditLog $audit,
		private Roles $roles,
		private NotificationService $notifications,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(int $copyId, array $data, string $uid): Task {
		$this->roles->requireRole($uid, self::CREATORS);
		foreach (array_keys($data) as $field) {
			if (!in_array($field, self::FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
		}
		$copy = $this->loadCopy($copyId);
		$task = new Task();
		$task->setCopyId($copy->getId());
		$task->setValue('kind', self::choice($data['kind'] ?? 'question', Task::KINDS, 'Kind of task'));
		$task->setAddressee(self::choice($data['addressee'] ?? null, self::ADDRESSEES, 'Addressee'));
		$task->setValue('category', isset($data['category']) && $data['category'] !== '' ? self::choice($data['category'], self::CATEGORIES, 'Category') : null);
		$task->setValue('page', self::positive($data['page'] ?? null, 'Page'));
		$task->setValue('verse', self::positive($data['verse'] ?? null, 'Verse'));
		$task->setValue('measure_from', self::measure($data['measure_from'] ?? null));
		$task->setValue('measure_to', self::measure($data['measure_to'] ?? null));
		$task->setValue('voice', self::text($data['voice'] ?? null, 100, 'Voice'));
		$task->setValue('origin', self::text($data['origin'] ?? null, 255, 'Origin'));
		$question = self::text($data['question'] ?? null, 10000, 'Question');
		if ($question === null) {
			throw new InvalidInputException('Please describe the question or task.');
		}
		$task->setValue('question', $question);
		$due = $data['due'] ?? null;
		if ($due !== null && $due !== '' && (!is_string($due) || !FieldValue::isDate($due))) {
			throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Due date']);
		}
		$task->setValue('due', $due === '' ? null : $due);
		$task->setStatus(Task::OPEN);
		$task->setCreatedBy($uid);
		$task->setCreated($this->time->getTime());
		$task = $this->tasks->insert($task);

		$this->audit->record($this->audit->newOperation(), $uid, 'task', $task->getId(), 'created', 'question', null, $question);
		$this->notifications->notifyRole($task->getAddressee(), NotificationService::TASK_NEW, 'task', (string)$task->getId(),
			$this->describe($task));
		return $task;
	}

	public function answer(int $taskId, string $answer, string $uid): void {
		$task = $this->load($taskId);
		if (!$this->roles->hasAnyRole($uid, [$task->getAddressee()])) {
			throw new AccessDeniedException('Only the addressed role can answer this task.');
		}
		if ($task->getStatus() !== Task::OPEN) {
			throw new InvalidInputException('This task is not open any more.');
		}
		if (trim($answer) === '') {
			throw new InvalidInputException('Please enter an answer.');
		}
		$task->setAnswer($answer);
		$task->setAnsweredBy($uid);
		$task->setAnsweredByName($this->userManager->getDisplayName($uid) ?? $uid);
		$task->setAnswered($this->time->getTime());
		$task->setStatus(Task::ANSWERED);
		$this->tasks->update($task);

		$this->audit->record($this->audit->newOperation(), $uid, 'task', $taskId, 'answered', 'answer', null, $answer);
		$this->notifications->withdraw(NotificationService::TASK_NEW, 'task', (string)$taskId);
		$this->notifications->notifyRole(Roles::LIBRARIAN, NotificationService::TASK_ANSWERED, 'task', (string)$taskId, $this->describe($task));
	}

	public function close(int $taskId, string $status, ?string $note, string $uid): void {
		$task = $this->load($taskId);
		$mayClose = $this->roles->hasAnyRole($uid, [Roles::LIBRARIAN])
			|| ($task->getKind() === 'check' && $this->roles->hasAnyRole($uid, [$task->getAddressee()]));
		if (!$mayClose) {
			throw new AccessDeniedException('Only the catalog librarian can close this task.');
		}
		if (!in_array($status, [Task::APPLIED, Task::DISCARDED], true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Status']);
		}
		if (in_array($task->getStatus(), [Task::APPLIED, Task::DISCARDED], true)) {
			throw new InvalidInputException('This task is already closed.');
		}
		$task->setStatus($status);
		$task->setClosedBy($uid);
		$task->setClosed($this->time->getTime());
		$task->setClosingNote($note === null || trim($note) === '' ? null : $note);
		$this->tasks->update($task);

		$this->audit->record($this->audit->newOperation(), $uid, 'task', $taskId, $status, 'status', null, $status, $note);
		$this->notifications->withdraw(NotificationService::TASK_NEW, 'task', (string)$taskId);
		$this->notifications->withdraw(NotificationService::TASK_ANSWERED, 'task', (string)$taskId);
	}

	/**
	 * Tasks that need the user: open tasks addressed to one of the user's
	 * roles and, for the catalog librarian, answered tasks to apply.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forUser(string $uid): array {
		$roles = $this->roles->rolesOf($uid);
		$result = [];
		foreach ($this->tasks->findByStatus([Task::OPEN], $roles === [] ? ['-'] : $roles) as $task) {
			$result[$task->getId()] = $task;
		}
		if (in_array(Roles::LIBRARIAN, $roles, true)) {
			foreach ($this->tasks->findByStatus([Task::ANSWERED]) as $task) {
				$result[$task->getId()] = $task;
			}
		}
		return $this->withContext(array_values($result));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(bool $includeClosed): array {
		$statuses = $includeClosed ? [Task::OPEN, Task::ANSWERED, Task::APPLIED, Task::DISCARDED] : [Task::OPEN, Task::ANSWERED];
		return $this->withContext($this->tasks->findByStatus($statuses));
	}

	/**
	 * @return list<Task>
	 */
	public function forCopy(int $copyId): array {
		return $this->tasks->findByCopy($copyId);
	}

	/**
	 * One task (no role check: every archive role reads the tasks).
	 *
	 * @throws NotFoundException
	 */
	public function find(int $taskId): Task {
		return $this->load($taskId);
	}

	/**
	 * Adds shelf mark, binder and title to tasks, sorted by binder and number
	 * so that a printed list follows the shelf.
	 *
	 * @param list<Task> $tasks
	 * @return list<array<string, mixed>>
	 */
	public function withContext(array $tasks): array {
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$rows = [];
		foreach ($tasks as $task) {
			$copy = $copies[$task->getCopyId()] ?? null;
			$version = $copy === null ? null : ($versions[$copy->getVersionId()] ?? null);
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$rows[] = $task->jsonSerialize() + [
				'shelfmark' => $copy?->getShelfmark(),
				'binder' => $copy?->getBinder(),
				'number' => $copy?->getNumber(),
				'title' => $work?->getTitle(),
				'repertoire' => $work?->getValue('repertoire_import'),
			];
		}
		usort($rows, fn (array $a, array $b): int => [$a['binder'] ?? 0, $a['number'] ?? 0, $a['id']] <=> [$b['binder'] ?? 0, $b['number'] ?? 0, $b['id']]);
		return $rows;
	}

	/**
	 * @return array<string, scalar|null>
	 */
	private function describe(Task $task): array {
		$copy = $this->copies->find($task->getCopyId());
		$version = $this->versions->find($copy->getVersionId());
		return [
			'copyId' => $copy->getId(),
			'taskId' => $task->getId(),
			'shelfmark' => $copy->getShelfmark(),
			'title' => (string)$this->works->find($version->getWorkId())->getTitle(),
		];
	}

	private function load(int $taskId): Task {
		try {
			return $this->tasks->find($taskId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	private function loadCopy(int $copyId): \OCA\Notenarchiv\Db\Copy {
		try {
			return $this->copies->find($copyId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
	}

	/**
	 * @param list<string> $allowed
	 */
	private static function choice(mixed $value, array $allowed, string $label): string {
		if (!is_string($value) || !in_array($value, $allowed, true)) {
			throw new InvalidInputException('"%s": value not allowed.', [$label]);
		}
		return $value;
	}

	private static function positive(mixed $value, string $label): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($int === false) {
			throw new InvalidInputException('"%s" must be a whole number.', [$label]);
		}
		return $int;
	}

	/**
	 * Printed measure numbers as in the score, e.g. "0" (upbeat), "17", "34X".
	 */
	private static function measure(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_scalar($value) || !preg_match('/^[0-9A-Za-z.]{1,16}$/', (string)$value)) {
			throw new InvalidInputException('Measure numbers may only contain digits, letters and dots.');
		}
		return (string)$value;
	}

	private static function text(mixed $value, int $max, string $label): ?string {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidInputException('"%s" must be text.', [$label]);
		}
		if (mb_strlen($value) > $max) {
			throw new InvalidInputException('"%s" is too long.', [$label]);
		}
		return $value;
	}
}
