<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItem;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\EventMapper;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;

/**
 * The concert folder of the conductor (milestone 0.15, module
 * "concert_folder"): the programmes still to be sung, in their order, with
 * the score and the scan of each item, to view, play and practise and to
 * keep on the device for the rehearsal and the concert without a network.
 * Only reads: nothing is changed from the folder. For the conductor and the
 * catalog librarian; the rights policy decides about every piece as in the
 * score view (RightsPolicy::viewDenial, playDenial), and an internal
 * preservation copy is never kept on a device.
 *
 * @psalm-type FolderItem = array{id: int, position: int, title: string, composer: string, shelfmark: ?string,
 *     participation: ?string, note: ?string, encore: bool, duration: ?int, score: bool, scan: bool, canPlay: bool,
 *     reason: ?string, offline: bool, offlineReason: ?string, practiceKey: ?string}
 * @psalm-type FolderConcert = array{id: int, kind: string, title: string, date: ?string, startTime: ?string,
 *     location: ?string, status: string, items: list<FolderItem>}
 */
class ConcertFolderService {
	/** Roles that open the concert folder */
	public const ROLES = [Roles::CONDUCTOR, Roles::LIBRARIAN];

	/** Why a preservation copy stays off the device (translated by the controller) */
	public const PRESERVATION_COPY = 'Internal preservation copy: it is not kept on a device.';

	public function __construct(
		private Roles $roles,
		private EventMapper $events,
		private EventItemMapper $items,
		private CopyMapper $copies,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private ArchiveFileMapper $files,
		private RightsPolicy $policy,
		private ScoreService $scores,
		private ScanService $scans,
		private Modules $modules,
	) {
	}

	/**
	 * Whether the account opens the concert folder.
	 */
	public function mayOpen(string $uid): bool {
		return $this->roles->hasAnyRole($uid, self::ROLES);
	}

	/**
	 * The programmes still to be sung (draft or fixed), the next first, each
	 * with its items in order; struck items are left out. Reasons are English
	 * source strings.
	 *
	 * @return list<FolderConcert>
	 * @throws UserFacingException
	 */
	public function concerts(string $uid): array {
		$this->requireRole($uid);
		$events = array_values(array_filter($this->events->findByKind(null),
			fn (Event $event): bool => in_array($event->getStatus(), ['draft', 'fixed'], true)));
		usort($events, fn (Event $a, Event $b): int => [$a->getEventDate() === null, $a->getEventDate(), $a->getStartTime(), $a->getId()]
			<=> [$b->getEventDate() === null, $b->getEventDate(), $b->getStartTime(), $b->getId()]);
		$concerts = [];
		foreach ($events as $event) {
			$items = [];
			foreach ($this->items->findByEvent($event->getId()) as $item) {
				if ($item->getStruck() !== true) {
					$items[] = $this->describe($item, $uid);
				}
			}
			$concerts[] = [
				'id' => $event->getId(),
				'kind' => $event->getKind(),
				'title' => (string)$event->getTitle(),
				'date' => $event->getEventDate(),
				'startTime' => $event->getStartTime(),
				'location' => $event->getLocation(),
				'status' => $event->getStatus(),
				'items' => $items,
			];
		}
		return $concerts;
	}

	/**
	 * The MusicXML of an item, after the same checks as the score view; a
	 * compressed score (.mxl) unpacked, as the shared pages read it.
	 *
	 * @throws UserFacingException
	 */
	public function score(int $itemId, string $uid): string {
		['score' => $score] = $this->filesOf($this->open($itemId, $uid));
		if ($score === null) {
			throw new NotFoundException('This programme item has no score.');
		}
		$content = $this->scores->content($score->getId(), $uid)['content'];
		return MusicXmlExcerpt::whole($this->scores->scoreText($content)['xml']);
	}

	/**
	 * The scan (PDF) of an item, after the same checks as the score view.
	 *
	 * @throws UserFacingException
	 */
	public function scan(int $itemId, string $uid): File {
		['scan' => $scan] = $this->filesOf($this->open($itemId, $uid));
		if ($scan === null) {
			throw new NotFoundException('This programme item has no scan.');
		}
		return $this->scans->scan($scan->getId(), $uid);
	}

	/**
	 * Why a version may not be kept on a device, or null. As the score
	 * view, and never an internal preservation copy: it may be viewed, but
	 * it is no copy to take along.
	 */
	public function keepDenial(WorkVersion $version, string $uid, ?Copy $copy): ?string {
		return $this->policy->viewDenial($version, $uid, $copy)
			?? (RightsPolicy::isPreservationCopy($version, $copy) ? self::PRESERVATION_COPY : null);
	}

	/**
	 * @return FolderItem
	 */
	private function describe(EventItem $item, string $uid): array {
		$copy = $this->copyOf($item);
		$version = $this->versionOf($item, $copy);
		$work = null;
		$workId = $version?->getWorkId() ?? $item->getWorkId();
		try {
			$work = $workId === null ? null : $this->works->find($workId);
		} catch (DoesNotExistException) {
		}
		$files = $copy === null ? ['score' => null, 'scan' => null] : $this->filesOf($copy);
		$viewDenial = $version === null ? null : $this->policy->viewDenial($version, $uid, $copy);
		$playDenial = $version === null ? null : ($this->policy->playDenial($version, $uid, $copy)
			?? ($this->modules->isEnabled('playback') ? null : Modules::PLAYBACK_OFF));
		$score = $files['score'] !== null && $viewDenial === null;
		$scan = $files['scan'] !== null && $viewDenial === null;
		$keepDenial = $version === null ? null : $this->keepDenial($version, $uid, $copy);
		return [
			'id' => $item->getId(),
			'position' => $item->getPosition(),
			'title' => (string)($work?->getTitle() ?? $item->getTitleText()),
			'composer' => (string)($work?->getComposer() ?? $item->getComposerText()),
			'shelfmark' => $copy?->getShelfmark(),
			'participation' => $item->getParticipation(),
			'note' => $item->getNote(),
			'encore' => $item->getEncore() === true,
			'duration' => $item->getDuration(),
			'score' => $score,
			'scan' => $scan,
			'canPlay' => $score && $playDenial === null,
			'reason' => $viewDenial ?? ($score ? $playDenial : null),
			'offline' => ($score || $scan) && $keepDenial === null,
			'offlineReason' => ($score || $scan) ? $keepDenial : null,
			'practiceKey' => $version === null ? null : SharedLinkService::practiceKey($version),
		];
	}

	/**
	 * An item of a programme still to be sung, for an account of the folder.
	 *
	 * @throws UserFacingException
	 */
	private function open(int $itemId, string $uid): Copy {
		$this->requireRole($uid);
		try {
			$item = $this->items->find($itemId);
			$event = $this->events->find($item->getEventId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if (!in_array($event->getStatus(), ['draft', 'fixed'], true) || $item->getStruck() === true) {
			throw new NotFoundException('This programme item is no longer in the concert folder.');
		}
		$copy = $this->copyOf($item);
		$version = $this->versionOf($item, $copy);
		if ($copy === null || $version === null) {
			throw new NotFoundException('This programme item has no score.');
		}
		$denial = $this->policy->viewDenial($version, $uid, $copy);
		if ($denial !== null) {
			throw new AccessDeniedException($denial);
		}
		return $copy;
	}

	/**
	 * @throws AccessDeniedException
	 */
	private function requireRole(string $uid): void {
		if (!$this->mayOpen($uid)) {
			throw new AccessDeniedException('The concert folder is for the conductor and the catalog librarian.');
		}
	}

	/**
	 * The copy of an item: the one of the programme, otherwise the first copy
	 * of its version that has a score.
	 */
	private function copyOf(EventItem $item): ?Copy {
		$copyId = $item->getCopyId();
		try {
			if ($copyId !== null) {
				return $this->copies->find($copyId);
			}
		} catch (DoesNotExistException) {
			return null;
		}
		$versionId = $item->getVersionId();
		if ($versionId === null) {
			return null;
		}
		$copies = $this->copies->findByVersion($versionId);
		foreach ($copies as $copy) {
			if (ScoreService::pick($this->files->findByCopy($copy->getId())) !== null) {
				return $copy;
			}
		}
		return $copies[0] ?? null;
	}

	private function versionOf(EventItem $item, ?Copy $copy): ?WorkVersion {
		$versionId = $copy?->getVersionId() ?? $item->getVersionId();
		try {
			return $versionId === null ? null : $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The score and the scan of a copy: the score as the score view picks it,
	 * the first PDF scan by name.
	 *
	 * @return array{score: ?ArchiveFile, scan: ?ArchiveFile}
	 */
	private function filesOf(Copy $copy): array {
		$files = $this->files->findByCopy($copy->getId());
		$scans = array_values(array_filter($files, fn (ArchiveFile $f): bool => $f->getRole() === 'score_pdf' && $f->getFound() !== false));
		usort($scans, fn (ArchiveFile $a, ArchiveFile $b): int => [$a->getName(), $a->getId()] <=> [$b->getName(), $b->getId()]);
		return ['score' => ScoreService::pick($files), 'scan' => $scans[0] ?? null];
	}
}
