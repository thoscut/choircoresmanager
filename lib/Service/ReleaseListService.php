<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;

/**
 * Target/actual lists for the systems outside the archive: the singers' portal
 * (e.g. Singste) and a print station. The app never changes those systems; it
 * tells the librarian what should be there and what must be removed.
 *
 * Status per version:
 * - `ok`: on the portal and released
 * - `remove`: on the portal but not released (any more)
 * - `add`: released, valid for the choir, not on the portal yet
 * - `not_decided`: released, but the conductor has not chosen a valid version
 *   of this work yet
 * - `blocked`: neither released nor on the portal (only listed on request)
 *
 * @psalm-type ReleaseRow = array{versionId: int, copies: list<array{id: int, shelfmark: string}>, title: ?string, composer: ?string,
 *     status: string, reason: ?string, portalSince: ?string, validSince: ?string, rightsStatus: ?string, confirmed: bool}
 * @psalm-type LicenseRow = array{licenseId: int, versionId: int, copies: list<array{id: int, shelfmark: string}>, title: ?string,
 *     composer: ?string, holder: ?string, uses: list<string>, validUntil: string, ended: bool}
 */
class ReleaseListService {
	public function __construct(
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private LicenseMapper $licenses,
		private RightsService $rights,
	) {
	}

	/**
	 * @return list<ReleaseRow>
	 */
	public function portal(string $uid, bool $includeBlocked = false): array {
		return $this->build($uid, 'portal', $includeBlocked);
	}

	/**
	 * @return list<ReleaseRow>
	 */
	public function printStation(string $uid, bool $includeBlocked = false): array {
		return $this->build($uid, 'print_station', $includeBlocked);
	}

	/**
	 * Licenses that end within the given number of days or have ended, the
	 * soonest first; the list behind the start page card and the daily
	 * reminder (both use 30 days).
	 *
	 * @return list<LicenseRow>
	 */
	public function licensesEnding(string $uid, int $days = 30): array {
		$day = $this->rights->today($uid);
		$limit = (new \DateTimeImmutable($day))->modify('+' . $days . ' days')->format('Y-m-d');
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$shelfmarks = $this->activeShelfmarks();
		$rows = [];
		$keys = [];
		foreach ($this->licenses->findAllByVersion() as $versionId => $list) {
			foreach ($list as $license) {
				$end = $license->getValidUntil();
				if ($end === null || $end > $limit) {
					continue;
				}
				$version = $versions[$versionId] ?? null;
				$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
				$copies = $shelfmarks[$versionId] ?? [];
				$keys[] = [$end, $copies[0]['shelfmark'] ?? ''];
				$rows[] = [
					'licenseId' => $license->getId(),
					'versionId' => $versionId,
					'copies' => $copies,
					'title' => $work?->getTitle(),
					'composer' => $work?->getComposer(),
					'holder' => $license->getHolder(),
					'uses' => $license->useList(),
					'validUntil' => $end,
					'ended' => $end < $day,
				];
			}
		}
		return Sort::byKeys($rows, $keys);
	}

	/**
	 * Status of a version in the list of the singers' portal (`portal`) or
	 * the print station (`print_station`), see the class comment, and why it
	 * is not released (null when it is). The print station has no "present"
	 * state, so it knows no `ok` and no `remove`.
	 *
	 * @param list<License> $licenses licenses of the version
	 * @param ?int $validVersionId the valid version of the work, if one is chosen
	 * @return array{0: string, 1: ?string} status and reason (English source string)
	 */
	public function status(WorkVersion $version, array $licenses, string $day, string $target, ?int $validVersionId): array {
		$denial = $target === 'portal'
			? $this->rights->portalDenial($version, $licenses, $day)
			: $this->rights->printStationDenial($version, $licenses, $day);
		$present = $target === 'portal' && $this->rights->isOnPortal($version);
		$status = match (true) {
			$present && $denial === null => 'ok',
			$present => 'remove',
			$denial !== null => 'blocked',
			$validVersionId === null => 'not_decided',
			$validVersionId === $version->getId() => 'add',
			default => 'other_version_valid',
		};
		return [$status, $denial];
	}

	/**
	 * @return array<int, list<array{id: int, shelfmark: string}>> version id => active copies
	 */
	private function activeShelfmarks(): array {
		$shelfmarks = [];
		foreach ($this->copies->findAll() as $copy) {
			if ($copy->getStatus() === 'active') {
				$shelfmarks[$copy->getVersionId()][] = ['id' => $copy->getId(), 'shelfmark' => $copy->getShelfmark()];
			}
		}
		return $shelfmarks;
	}

	/**
	 * @return list<ReleaseRow>
	 */
	private function build(string $uid, string $target, bool $includeBlocked): array {
		$day = $this->rights->today($uid);
		$works = $this->works->findAllById();
		$licenses = $this->licenses->findAllByVersion();
		$shelfmarks = $this->activeShelfmarks();
		$validPerWork = [];
		$allVersions = $this->versions->findAllById();
		foreach ($allVersions as $version) {
			if ($version->getValidSince() !== null) {
				$validPerWork[$version->getWorkId()] = $version->getId();
			}
		}

		$order = ['remove' => 0, 'add' => 1, 'not_decided' => 2, 'ok' => 3, 'other_version_valid' => 4, 'blocked' => 5];
		$rows = [];
		$keys = [];
		foreach ($allVersions as $version) {
			[$status, $denial] = $this->status($version, $licenses[$version->getId()] ?? [], $day, $target,
				$validPerWork[$version->getWorkId()] ?? null);
			if (!$includeBlocked && in_array($status, ['blocked', 'other_version_valid'], true)) {
				continue;
			}
			$work = $works[$version->getWorkId()] ?? null;
			$copies = $shelfmarks[$version->getId()] ?? [];
			$keys[] = [$order[$status], $copies[0]['shelfmark'] ?? ''];
			$rows[] = [
				'versionId' => $version->getId(),
				'copies' => $copies,
				'title' => $work?->getTitle(),
				'composer' => $work?->getComposer(),
				'status' => $status,
				'reason' => $denial,
				'portalSince' => $version->getPortalSince(),
				'validSince' => $version->getValidSince(),
				'rightsStatus' => $version->getRightsStatus(),
				'confirmed' => $version->isConfirmed(),
			];
		}
		return Sort::byKeys($rows, $keys);
	}
}
