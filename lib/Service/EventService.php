<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventItem;
use OCA\Notenarchiv\Db\EventItemMapper;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Concert programmes and song lists.
 *
 * Conductor and catalog librarian plan; everybody with access reads. A
 * concert goes draft → fixed → sung. Confirming "sung" (with struck pieces
 * and encores) creates the documented performances; the repertoire figures are
 * derived from them. Imported programmes are history and cannot be edited.
 *
 * For each item the rights situation is checked and shown as warnings; the
 * app warns, people decide.
 *
 * @psalm-type Line = array{item: EventItem, copy: ?Copy, version: ?WorkVersion, work: ?Work, warnings: list<string>}
 */
class EventService {
	private const PLANNERS = [Roles::CONDUCTOR, Roles::LIBRARIAN];
	private const EVENT_FIELDS = ['title', 'event_date', 'location', 'period', 'is_public', 'board_agreed', 'notes'];
	private const ITEM_FIELDS = ['duration', 'note', 'encore', 'struck'];

	public function __construct(
		private IDBConnection $db,
		private EventMapper $events,
		private EventItemMapper $items,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private LicenseMapper $licenses,
		private RightsService $rights,
		private AuditLog $audit,
		private Roles $roles,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function create(string $kind, array $data, string $uid): Event {
		$this->roles->requireRole($uid, self::PLANNERS);
		if (!in_array($kind, Event::KINDS, true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Kind']);
		}
		$event = new Event();
		$event->setKind($kind);
		$event->setStatus('draft');
		$event->setImported(false);
		$event->setCreatedBy($uid);
		$event->setCreated($this->time->getTime());
		$event->setModified($this->time->getTime());
		$this->assign($event, $data);
		self::requireTitle($event);
		$event = $this->events->insert($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'created', 'title', null, $event->getTitle());
		return $event;
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function update(int $eventId, array $data, string $uid): Event {
		$event = $this->editable($eventId, $uid);
		$before = $event->jsonSerialize();
		$this->assign($event, $data);
		self::requireTitle($event);
		$operation = $this->audit->newOperation();
		foreach (self::EVENT_FIELDS as $field) {
			if ($before[$field] !== $event->getValue($field)) {
				$this->audit->record($operation, $uid, 'event', $eventId, 'changed', $field, $before[$field], $event->getValue($field));
			}
		}
		$event->setModified($this->time->getTime());
		return $this->events->update($event);
	}

	/**
	 * Adds a copy to the programme. The version is the copy's version; a
	 * warning shows if the conductor has chosen another valid version.
	 */
	public function addItem(int $eventId, int $copyId, string $uid): EventItem {
		$event = $this->editable($eventId, $uid);
		try {
			$copy = $this->copies->find($copyId);
			$version = $this->versions->find($copy->getVersionId());
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		$position = count($this->items->findByEvent($eventId)) + 1;
		$item = new EventItem();
		$item->setEventId($eventId);
		$item->setPosition($position);
		$item->setValue('copy_id', $copy->getId());
		$item->setValue('version_id', $version->getId());
		$item->setValue('work_id', $version->getWorkId());
		$item->setDuration($version->getDuration());
		$item->setAssignment(EventItem::CERTAIN);
		$item->setCounts(false);
		$item = $this->items->insert($item);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'item_added', 'copy_id', null, $copy->getShelfmark());
		return $item;
	}

	/**
	 * @param array<array-key, mixed> $data field => value (request data)
	 */
	public function updateItem(int $itemId, array $data, string $uid): EventItem {
		$item = $this->loadItem($itemId);
		$this->editable($item->getEventId(), $uid);
		foreach (Input::values($data) as $field => $value) {
			if (!in_array($field, self::ITEM_FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			$item->setValue($field, match ($field) {
				'duration' => $value === null || $value === '' ? null : self::int($value, 'Duration (seconds)', 0, 36000),
				'encore', 'struck' => Input::bool($value, $field),
				default => Input::text($value, $field),
			});
		}
		return $this->items->update($item);
	}

	public function removeItem(int $itemId, string $uid): void {
		$item = $this->loadItem($itemId);
		$event = $this->editable($item->getEventId(), $uid);
		$this->items->delete($item);
		$this->renumber($event->getId());
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $event->getId(), 'item_removed', 'copy_id', $item->getCopyId(), null);
	}

	/**
	 * @param list<int> $itemIds new order
	 */
	public function reorder(int $eventId, array $itemIds, string $uid): void {
		$this->editable($eventId, $uid);
		$items = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			$items[$item->getId()] = $item;
		}
		if (count($itemIds) !== count($items) || array_diff($itemIds, array_keys($items)) !== []) {
			throw new InvalidInputException('The new order must contain every item exactly once.');
		}
		foreach ($itemIds as $index => $id) {
			$items[$id]->setPosition($index + 1);
			$this->items->update($items[$id]);
		}
	}

	public function setFixed(int $eventId, string $uid): void {
		$event = $this->editable($eventId, $uid);
		$event->setStatus('fixed');
		$event->setModified($this->time->getTime());
		$this->events->update($event);
		$this->audit->record($this->audit->newOperation(), $uid, 'event', $eventId, 'fixed', 'status', 'draft', 'fixed');
	}

	/**
	 * Confirms the concert as sung. Items listed in $struck were not sung;
	 * every other item counts as a documented performance.
	 *
	 * @param list<int> $struck item ids that were not sung
	 */
	public function confirmSung(int $eventId, array $struck, string $uid): void {
		$event = $this->editable($eventId, $uid);
		if ($event->getKind() !== Event::CONCERT) {
			throw new InvalidInputException('Only concerts can be confirmed as sung.');
		}
		if ($event->getEventDate() === null || $event->getEventDate() > $this->rights->today($uid)) {
			throw new InvalidInputException('A concert can only be confirmed on or after its date.');
		}
		$operation = $this->audit->newOperation();
		$this->db->beginTransaction();
		try {
			foreach ($this->items->findByEvent($eventId) as $item) {
				$wasStruck = in_array($item->getId(), $struck, true);
				$item->setValue('struck', $wasStruck);
				$item->setValue('sung', !$wasStruck);
				$item->setCounts(!$wasStruck && $item->getWorkId() !== null);
				$this->items->update($item);
			}
			$event->setStatus('sung');
			$event->setModified($this->time->getTime());
			$this->events->update($event);
			$this->audit->record($operation, $uid, 'event', $eventId, 'sung', 'status', null, 'sung');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Resolves an ambiguous or unassigned programme line by choosing the
	 * copy (or confirming it is not in the catalog).
	 */
	public function resolveItem(int $itemId, ?int $copyId, string $uid): void {
		$this->roles->requireRole($uid, self::PLANNERS);
		$item = $this->loadItem($itemId);
		if ($copyId === null) {
			$item->setAssignment(EventItem::NOT_IN_CATALOG);
			$item->setValue('copy_id', null);
			$item->setValue('version_id', null);
			$item->setValue('work_id', null);
			$item->setCounts(false);
		} else {
			try {
				$copy = $this->copies->find($copyId);
				$version = $this->versions->find($copy->getVersionId());
			} catch (DoesNotExistException) {
				throw new InvalidInputException('Not found.');
			}
			$item->setAssignment(EventItem::CERTAIN);
			$item->setValue('copy_id', $copy->getId());
			$item->setValue('version_id', $version->getId());
			$item->setValue('work_id', $version->getWorkId());
			$event = $this->events->find($item->getEventId());
			$item->setCounts($event->getKind() === Event::CONCERT && $event->getStatus() === 'sung'
				&& $item->getStruck() !== true && PerformanceImportService::countsByRule($item->getParticipation(), $item->getState(), null));
		}
		$this->items->update($item);
		$this->audit->record($this->audit->newOperation(), $uid, 'event_item', $itemId, 'resolved', 'copy_id', null, $copyId);
	}

	/**
	 * The event with its items, each with context and warnings (English
	 * source strings).
	 *
	 * @return array{event: Event, items: list<array<string, string|int|bool|null|list<string>>>, totalDuration: int, missingDurations: int}
	 */
	public function detail(int $eventId, string $uid): array {
		['event' => $event, 'lines' => $lines] = $this->lines($eventId, $uid);
		$items = [];
		$total = 0;
		$missingDurations = 0;
		foreach ($lines as ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'warnings' => $warnings]) {
			$duration = $item->getDuration();
			if ($item->getStruck() !== true) {
				if ($duration === null) {
					$missingDurations++;
				} else {
					$total += $duration;
				}
			}
			$items[] = $item->jsonSerialize() + [
				'shelfmark' => $copy?->getShelfmark(),
				'title' => $work?->getTitle() ?? $item->getTitleText(),
				'composer' => $work?->getComposer() ?? $item->getComposerText(),
				'arranger_text' => $version?->getArrangerText(),
				'publisher' => $version?->getPublisher(),
				'warnings' => $warnings,
			];
		}
		return ['event' => $event, 'items' => $items, 'totalDuration' => $total, 'missingDurations' => $missingDurations];
	}

	/**
	 * The items of an event with copy, version, work and rights warnings.
	 *
	 * @return array{event: Event, lines: list<Line>}
	 */
	private function lines(int $eventId, string $uid): array {
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		$day = $this->rights->today($uid);
		$versions = $this->versions->findAllById();
		$works = $this->works->findAllById();
		$validPerWork = [];
		foreach ($versions as $version) {
			if ($version->getValidSince() !== null) {
				$validPerWork[$version->getWorkId()] = $version->getId();
			}
		}
		$copies = [];
		foreach ($this->copies->findAll() as $copy) {
			$copies[$copy->getId()] = $copy;
		}
		$licenses = $this->licenses->findAllByVersion();
		$lines = [];
		foreach ($this->items->findByEvent($eventId) as $item) {
			$versionId = $item->getVersionId();
			$workId = $item->getWorkId();
			$copyId = $item->getCopyId();
			$version = $versionId === null ? null : ($versions[$versionId] ?? null);
			$work = $workId === null ? null : ($works[$workId] ?? null);
			$copy = $copyId === null ? null : ($copies[$copyId] ?? null);
			$warnings = [];
			if ($version !== null && $event->getImported() !== true) {
				if ($version->getPreservationCopy() === true) {
					$warnings[] = 'Internal preservation copy: not a singing copy.';
				}
				$versionLicenses = $licenses[$version->getId()] ?? [];
				if ($version->getRightsStatus() === 'protected' && !$this->rights->hasLicense($versionLicenses, 'binder', $day)
					&& ($copy?->getOriginalsCount() === null)) {
					$warnings[] = 'Protected: check that there are enough original copies or a license.';
				}
				if ($version->getRightsStatus() !== 'public_domain' && $version->getRightsStatus() !== 'protected') {
					$warnings[] = 'Rights not clarified.';
				}
				$valid = $validPerWork[$version->getWorkId()] ?? null;
				if ($valid === null) {
					$warnings[] = 'No valid version chosen for this work.';
				} elseif ($valid !== $version->getId()) {
					$warnings[] = 'Another version of this work is marked as valid.';
				}
			}
			$lines[] = ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work, 'warnings' => $warnings];
		}
		return ['event' => $event, 'lines' => $lines];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(?string $kind): array {
		$counts = [];
		foreach ($this->items->findAll() as $item) {
			$counts[$item->getEventId()] = ($counts[$item->getEventId()] ?? 0) + 1;
		}
		return array_map(fn (Event $e): array => $e->jsonSerialize() + ['items' => $counts[$e->getId()] ?? 0], $this->events->findByKind($kind));
	}

	/**
	 * Song order for the collecting society, programme booklet and binders.
	 */
	public function songOrderCsv(int $eventId, string $uid): string {
		$rows = [];
		foreach ($this->lines($eventId, $uid)['lines'] as ['item' => $item, 'copy' => $copy, 'version' => $version, 'work' => $work]) {
			if ($item->getStruck() === true) {
				continue;
			}
			$duration = $item->getDuration();
			$rows[] = [
				(string)$item->getPosition(), $copy?->getShelfmark() ?? '', $work?->getTitle() ?? $item->getTitleText() ?? '',
				$work?->getComposer() ?? $item->getComposerText() ?? '', $version?->getArrangerText() ?? '', $version?->getPublisher() ?? '',
				$duration === null ? '' : sprintf('%d:%02d', intdiv($duration, 60), $duration % 60),
				$item->getEncore() === true ? 'encore' : '',
			];
		}
		return CatalogCsv::writeForPeople(['Position', 'Shelf mark', 'Title', 'Composer', 'Arranger / lyrics', 'Publisher', 'Duration', 'Note'], $rows);
	}

	private function editable(int $eventId, string $uid): Event {
		$this->roles->requireRole($uid, self::PLANNERS);
		try {
			$event = $this->events->find($eventId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
		if ($event->getImported() === true || $event->getStatus() === 'sung') {
			throw new InvalidInputException('Performed or imported programmes are history and cannot be changed.');
		}
		return $event;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function assign(Event $event, array $data): void {
		foreach (Input::values($data) as $field => $value) {
			if (!in_array($field, self::EVENT_FIELDS, true)) {
				throw new InvalidInputException('Unknown field: %s', [$field]);
			}
			if ($field === 'is_public' || $field === 'board_agreed') {
				$event->setValue($field, Input::bool($value, $field));
				continue;
			}
			$text = Input::text($value, $field);
			if ($field === 'event_date' && $text !== null && !FieldValue::isDate($text)) {
				throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Date']);
			}
			if (in_array($field, ['title', 'location', 'period'], true) && $text !== null && mb_strlen($text) > ($field === 'period' ? 32 : 500)) {
				throw new InvalidInputException('"%s" is too long.', [$field]);
			}
			$event->setValue($field, $text);
		}
	}

	/**
	 * A planned programme needs a title (imported ones may lack it).
	 */
	private static function requireTitle(Event $event): void {
		if (trim($event->getTitle() ?? '') === '') {
			throw new InvalidInputException('Please enter a title.');
		}
	}

	private function renumber(int $eventId): void {
		foreach ($this->items->findByEvent($eventId) as $index => $item) {
			if ($item->getPosition() !== $index + 1) {
				$item->setPosition($index + 1);
				$this->items->update($item);
			}
		}
	}

	private function loadItem(int $itemId): EventItem {
		try {
			return $this->items->find($itemId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Not found.');
		}
	}

	private static function int(mixed $value, string $label, int $min, int $max): int {
		$int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
		if ($int === false) {
			throw new InvalidInputException('"%s" must be a whole number.', [$label]);
		}
		return $int;
	}
}
