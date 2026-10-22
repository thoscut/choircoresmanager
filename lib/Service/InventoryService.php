<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\IDBConnection;

/**
 * Inventory per archive binder (milestone 0.5): the list for counting at the
 * shelf, printable in A4, and recording the counts.
 *
 * Counts are written through the catalog with its field rules (catalog or
 * operations librarian) and its audit log: originals, copies and the date of
 * the last count.
 */
class InventoryService {
	/** Counted copies recorded in one request */
	public const MAX_COUNTS = 2000;

	public function __construct(
		private IDBConnection $db,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private CatalogService $catalog,
		private RightsService $rights,
		private Roles $roles,
	) {
	}

	/**
	 * Overview of all binders and the copies of one binder in shelf order.
	 * Without `$all` only active copies are listed (those expected on the
	 * shelf).
	 *
	 * @return array{binder: ?int, binders: list<array{binder: int, active: int, total: int, oldestCount: ?string, uncounted: int}>, rows: list<array<string, mixed>>}
	 */
	public function list(?int $binder, bool $all): array {
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$overview = [];
		$rows = [];
		$keys = [];
		$copies = $this->copies->findAll();
		$binder ??= $copies === [] ? null : min(array_map(fn (Copy $c): int => $c->getBinder(), $copies));
		foreach ($copies as $copy) {
			$b = $copy->getBinder();
			$overview[$b] ??= ['binder' => $b, 'active' => 0, 'total' => 0, 'oldestCount' => null, 'uncounted' => 0];
			$overview[$b]['total']++;
			$active = $copy->getStatus() === 'active';
			if ($active) {
				$overview[$b]['active']++;
				$counted = $copy->getLastCounted();
				if ($counted === null) {
					$overview[$b]['uncounted']++;
				} elseif ($overview[$b]['oldestCount'] === null || $counted < $overview[$b]['oldestCount']) {
					$overview[$b]['oldestCount'] = $counted;
				}
			}
			if ($b !== $binder || (!$all && !$active)) {
				continue;
			}
			$version = $versions[$copy->getVersionId()] ?? null;
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$rows[] = [
				'copyId' => $copy->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'binder' => $b,
				'number' => $copy->getNumber(),
				'status' => $copy->getStatus(),
				'status_reason' => $copy->getStatusReason(),
				'kind' => $copy->getKind(),
				'originals_count' => $copy->getOriginalsCount(),
				'copies_count' => $copy->getCopiesCount(),
				'last_counted' => $copy->getLastCounted(),
				'pages' => $copy->getPages(),
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'arranger_text' => $version?->getArrangerText(),
				'preservation_copy' => $version?->getPreservationCopy() === true,
			];
			$keys[] = [$copy->getNumber()];
		}
		ksort($overview);
		return ['binder' => $binder, 'binders' => array_values($overview), 'rows' => Sort::byKeys($rows, $keys)];
	}

	/**
	 * Records counted copies: sets the date of the last count and, where
	 * given, the number of originals and copies. All or nothing.
	 *
	 * @param array<array-key, mixed> $counts entries `{ copyId, originals?, copies? }` (request data)
	 * @return int number of copies recorded
	 */
	public function recordCounts(array $counts, ?string $date, string $uid): int {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN, Roles::OPERATIONS]);
		$today = $this->rights->today($uid);
		$date = $date === null || $date === '' ? $today : $date;
		if (!FieldValue::isDate($date)) {
			throw new InvalidInputException('"%s" needs a valid date (YYYY-MM-DD).', ['Last counted']);
		}
		if ($date > $today) {
			throw new InvalidInputException('The date of the count must not lie in the future.');
		}
		if ($counts === []) {
			throw new InvalidInputException('Nothing to record.');
		}
		// One request is one binder or a few; the limit keeps a request from
		// holding the transaction open for a very long time.
		if (count($counts) > self::MAX_COUNTS) {
			throw new InvalidInputException('Too many entries at once (at most %s).', [self::MAX_COUNTS]);
		}
		$this->db->beginTransaction();
		try {
			foreach (array_keys($counts) as $index) {
				[$copyId, $fields] = self::countEntry($counts[$index]);
				$this->catalog->update(Fields::COPY, $copyId, ['last_counted' => $date] + $fields, $uid, 'inventory');
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return count($counts);
	}

	/**
	 * One counted copy from the request: copy id and the counts given.
	 *
	 * @return array{0: int, 1: array<string, string|int|float|bool|null>}
	 */
	private static function countEntry(mixed $entry): array {
		$copyId = is_array($entry) ? filter_var($entry['copyId'] ?? null, FILTER_VALIDATE_INT) : false;
		if (!is_array($entry) || $copyId === false) {
			throw new InvalidInputException('Nothing to record.');
		}
		$fields = [];
		if (array_key_exists('originals', $entry)) {
			$fields['originals_count'] = Input::value($entry['originals'], 'Number of originals');
		}
		if (array_key_exists('copies', $entry)) {
			$fields['copies_count'] = Input::value($entry['copies'], 'Number of copies');
		}
		return [$copyId, $fields];
	}
}
