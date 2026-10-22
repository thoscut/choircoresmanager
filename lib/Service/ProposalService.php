<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The proposals inbox. Tools and transcription rounds (role contributor) do
 * not write the catalog; they propose changes, and the catalog librarian
 * accepts or rejects them. Accepting applies the change with the librarian's
 * own rights and records the proposal in the audit log.
 */
class ProposalService {
	public function __construct(
		private ProposalMapper $proposals,
		private CatalogService $catalog,
		private AuditLog $audit,
		private Roles $roles,
		private NotificationService $notifications,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Creates one proposal per field. Only fields the catalog librarian may
	 * write can be proposed.
	 *
	 * @param array<array-key, mixed> $fields field => proposed value (request data)
	 * @return list<Proposal>
	 */
	public function propose(string $level, int $objectId, array $fields, string $uid, ?string $reason, ?string $source): array {
		$this->roles->requireRole($uid, [Roles::CONTRIBUTOR, Roles::LIBRARIAN]);
		$registry = Fields::all()[$level] ?? null;
		if ($registry === null) {
			throw new InvalidInputException('Unknown level: %s', [$level]);
		}
		if ($fields === []) {
			throw new InvalidInputException('The proposal contains no changes.');
		}
		$this->catalog->entityExists($level, $objectId);
		$created = [];
		foreach (Input::values($fields) as $field => $value) {
			$info = $registry[$field] ?? null;
			if ($info === null || !in_array(Roles::LIBRARIAN, $info['write'], true)) {
				throw new InvalidInputException('"%s" cannot be proposed.', [$field]);
			}
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

	public function accept(int $proposalId, string $uid, ?string $reason): void {
		$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
		$proposal = $this->openProposal($proposalId);
		$source = $proposal->getSource();
		$proposalReason = $proposal->getReason();
		$note = trim('Proposal #' . $proposalId . ($source !== null ? ' (' . $source . ')' : '')
			. ($reason !== null && trim($reason) !== '' ? ': ' . $reason : ($proposalReason !== null ? ': ' . $proposalReason : '')));
		$this->catalog->update($proposal->getObjectType(), $proposal->getObjectId(), [$proposal->getField() => $proposal->value()], $uid, $note);
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
			throw new InvalidInputException('Not found.');
		}
		if ($proposal->getStatus() !== Proposal::OPEN) {
			throw new InvalidInputException('This proposal has already been decided.');
		}
		return $proposal;
	}
}
