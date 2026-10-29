<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Exception\InvalidInputException;
use OCA\Cantoria\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The proposals inbox. Tools and transcription rounds (role contributor) do
 * not write the catalog; they propose changes, and the catalog librarian
 * accepts or rejects them. Accepting applies the change with the librarian's
 * own rights and records the proposal in the audit log.
 */
class ProposalService {
	/** Roles that may propose changes */
	public const PROPOSERS = [Roles::CONTRIBUTOR, Roles::LIBRARIAN];
	/**
	 * The field of a version that only the comparison with the choir system
	 * proposes (milestone 0.12): a date records the version as in the
	 * singers' portal since then, null as removed from it. Accepting goes
	 * through RightsService, which checks the release again.
	 */
	public const PORTAL_FIELD = 'portal_since';

	public function __construct(
		private ProposalMapper $proposals,
		private CatalogService $catalog,
		private AuditLog $audit,
		private Roles $roles,
		private NotificationService $notifications,
		private ITimeFactory $time,
		private RightsService $rights,
	) {
	}

	/**
	 * Fields that can be proposed, per level: those the catalog librarian may
	 * write (the librarian applies an accepted proposal with their own
	 * rights). Read-only fields, such as a rights confirmation or the valid
	 * version, can never be proposed.
	 *
	 * @return array<string, list<string>> level => fields in the order of the registry
	 */
	public static function proposableFields(): array {
		$fields = [];
		foreach (Fields::all() as $level => $registry) {
			$fields[$level] = array_keys(array_filter($registry, fn (array $info): bool => in_array(Roles::LIBRARIAN, $info['write'], true)));
		}
		return $fields;
	}

	/**
	 * Creates one proposal per field. Only fields the catalog librarian may
	 * write can be proposed. All fields are checked before the first proposal
	 * is stored, so that a request is taken as a whole or not at all.
	 *
	 * @param array<array-key, mixed> $fields field => proposed value (request data)
	 * @return list<Proposal>
	 */
	public function propose(string $level, int $objectId, array $fields, string $uid, ?string $reason, ?string $source): array {
		$this->roles->requireRole($uid, self::PROPOSERS);
		if (!isset(Fields::all()[$level])) {
			throw new InvalidInputException('Unknown level: %s', [$level]);
		}
		if ($fields === []) {
			throw new InvalidInputException('The proposal contains no changes.');
		}
		$this->catalog->entityExists($level, $objectId);
		$values = Input::values($fields);
		foreach (array_keys($values) as $field) {
			if (!in_array($field, self::proposableFields()[$level] ?? [], true)) {
				throw new InvalidInputException('"%s" cannot be proposed.', [$field]);
			}
		}
		$created = [];
		foreach ($values as $field => $value) {
			$proposal = new Proposal();
			$proposal->setObjectType($level);
			$proposal->setObjectId($objectId);
			$proposal->setField($field);
			// Never `?: null` here: json_encode(0) is "0", which is falsy.
			$proposal->setNewValue(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			$proposal->setReason($reason === null || trim($reason) === '' ? null : $reason);
			$proposal->setSource($source === null || trim($source) === '' ? null : mb_substr($source, 0, 255));
			$proposal->setStatus(Proposal::OPEN);
			$proposal->setCreatedBy($uid);
			$proposal->setCreated($this->time->getTime());
			$created[] = $this->proposals->insert($proposal);
		}
		$this->notifications->proposalsWaiting($this->proposals->countOpen());
		return $created;
	}

	/**
	 * A proposal to record a version as in the singers' portal since a day
	 * (`$since`) or as removed from it (null), unless one is open already.
	 */
	public function proposePortal(int $versionId, ?string $since, string $uid, string $reason, string $source): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		if ($this->hasOpenPortalProposal($versionId, $since !== null)) {
			return;
		}
		$proposal = new Proposal();
		$proposal->setObjectType(Fields::VERSION);
		$proposal->setObjectId($versionId);
		$proposal->setField(self::PORTAL_FIELD);
		$proposal->setNewValue(json_encode($since, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$proposal->setReason(trim($reason) === '' ? null : mb_substr($reason, 0, 2000));
		$proposal->setSource(mb_substr($source, 0, 255));
		$proposal->setStatus(Proposal::OPEN);
		$proposal->setCreatedBy($uid);
		$proposal->setCreated($this->time->getTime());
		$this->proposals->insert($proposal);
		$this->notifications->proposalsWaiting($this->proposals->countOpen());
	}

	/**
	 * Whether a proposal to add the version to the singers' portal
	 * (`$adding`) or to remove it is open.
	 */
	public function hasOpenPortalProposal(int $versionId, bool $adding): bool {
		foreach ($this->proposals->findOpenForObject(Fields::VERSION, $versionId) as $open) {
			if ($open->getField() === self::PORTAL_FIELD && ($open->value() !== null) === $adding) {
				return true;
			}
		}
		return false;
	}

	public function accept(int $proposalId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$proposal = $this->openProposal($proposalId);
		$source = $proposal->getSource();
		$proposalReason = $proposal->getReason();
		$note = trim('Proposal #' . $proposalId . ($source !== null ? ' (' . $source . ')' : '')
			. ($reason !== null && trim($reason) !== '' ? ': ' . $reason : ($proposalReason !== null ? ': ' . $proposalReason : '')));
		if ($proposal->getObjectType() === Fields::VERSION && $proposal->getField() === self::PORTAL_FIELD) {
			$since = $proposal->value();
			if (is_string($since)) {
				$this->rights->addToPortal($proposal->getObjectId(), $uid, $since);
			} else {
				$this->rights->removeFromPortal($proposal->getObjectId(), $uid, $note);
			}
		} else {
			$this->catalog->update($proposal->getObjectType(), $proposal->getObjectId(), [$proposal->getField() => $proposal->value()], $uid, $note);
		}
		$this->decide($proposal, Proposal::ACCEPTED, $uid, $reason);
	}

	public function reject(int $proposalId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$this->decide($this->openProposal($proposalId), Proposal::REJECTED, $uid, $reason);
	}

	/**
	 * @return list<Proposal>
	 */
	public function open(): array {
		return $this->proposals->findByStatus(Proposal::OPEN);
	}

	/**
	 * The proposals an account made, oldest first, optionally of one status.
	 *
	 * @return list<Proposal>
	 * @throws InvalidInputException for an unknown status
	 */
	public function own(string $uid, ?string $status): array {
		$status = $status === '' ? null : $status;
		if ($status !== null && !in_array($status, [Proposal::OPEN, Proposal::ACCEPTED, Proposal::REJECTED], true)) {
			throw new InvalidInputException('"%s": value not allowed.', ['Status']);
		}
		return $this->proposals->findByCreator($uid, $status);
	}

	private function decide(Proposal $proposal, string $status, string $uid, ?string $reason): void {
		$proposal->setStatus($status);
		$proposal->setDecidedBy($uid);
		$proposal->setDecided($this->time->getTime());
		$proposal->setDecisionReason($reason === null || trim($reason) === '' ? null : $reason);
		$this->proposals->update($proposal);
		$this->audit->record($this->audit->newOperation(), $uid, 'proposal', $proposal->getId(), $status, $proposal->getField(), null, $proposal->getNewValue(), $reason);
		$this->notifications->proposalsWaiting($this->proposals->countOpen());
	}

	private function openProposal(int $proposalId): Proposal {
		try {
			$proposal = $this->proposals->find($proposalId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Not found.');
		}
		if ($proposal->getStatus() !== Proposal::OPEN) {
			throw new InvalidInputException('This proposal has already been decided.');
		}
		return $proposal;
	}
}
