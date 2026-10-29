<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Service;

use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\FolderLoan;
use OCA\Cantoria\Db\FolderLoanMapper;
use OCA\Cantoria\Db\FolderMapper;
use OCA\Cantoria\Db\PersonMapper;

/**
 * Access and erasure for one person (milestone 0.11, Art. 15 and 17 GDPR),
 * by Nextcloud account or by name: the loans of the folder book, the audit
 * entries made by them, and persons of the same name in the register of
 * persons. For self-hosted instances via `occ cantoria:privacy-report`
 * and `cantoria:privacy-erase`; a deleted Nextcloud account is handled
 * automatically (forgetAccount).
 *
 * Erasure removes the personal data of the loans (also from their audit
 * entries; an open loan stays open, without the holder) and the account and
 * name from the audit entries made by the person; what was changed stays.
 * Persons of the register (composers, lyricists, …) are only reported: their
 * names are needed for the rights; life dates can be removed in the app.
 *
 * @psalm-type PrivacyReport = array{query: string, loans: list<array<string, mixed>>, auditEntries: list<array<string, mixed>>, persons: list<array<string, mixed>>}
 */
class PrivacyService {
	/** The name in the audit log after an erasure */
	public const ERASED = '(anonymised)';

	public function __construct(
		private FolderLoanMapper $loans,
		private FolderMapper $folders,
		private FolderBookService $folderBook,
		private AuditEntryMapper $auditEntries,
		private PersonMapper $persons,
		private AuditLog $audit,
	) {
	}

	/**
	 * Everything the app stores about an account or a name.
	 *
	 * @return PrivacyReport
	 */
	public function report(string $who): array {
		$labels = [];
		foreach ($this->folders->findAll() as $folder) {
			$labels[$folder->getId()] = $folder->getLabel();
		}
		$loans = [];
		foreach ($this->loansOf($who) as $loan) {
			$loans[] = ['folder' => $labels[$loan->getFolderId()] ?? null] + $loan->jsonSerialize();
		}
		$entries = array_map(fn ($entry): array => $entry->jsonSerialize(), $this->auditEntries->findByActor($who, $who));
		$persons = array_values(array_map(fn ($person): array => $person->jsonSerialize(),
			array_filter($this->persons->findAll(), fn ($person): bool => self::same($person->getName(), $who))));
		return ['query' => $who, 'loans' => $loans, 'auditEntries' => $entries, 'persons' => $persons];
	}

	/**
	 * Erases the personal data of an account or a name; records the erasure
	 * without the name.
	 *
	 * @return array{loans: int, auditEntries: int, persons: int}
	 */
	public function erase(string $who): array {
		$loans = $this->loansOf($who);
		foreach ($loans as $loan) {
			$this->folderBook->anonymizeLoan($loan, 'erased on request');
		}
		$entries = $this->auditEntries->removeAccount($who, self::ERASED) + $this->auditEntries->replaceName($who, self::ERASED);
		$persons = count(array_filter($this->persons->findAll(), fn ($person): bool => self::same($person->getName(), $who)));
		$this->audit->record($this->audit->newOperation(), null, 'privacy', null, 'erased', null, null,
			['loans' => count($loans), 'auditEntries' => $entries]);
		return ['loans' => count($loans), 'auditEntries' => $entries, 'persons' => $persons];
	}

	/**
	 * A Nextcloud account was deleted: returned loans of the account lose
	 * their personal data; an open loan keeps the name of the holder (the
	 * folder is still out; the display name if no name was written) but no
	 * longer the account, until its own term ends. Audit entries lose the
	 * account and keep the display name of who made a change (the record of
	 * the function holders).
	 */
	public function forgetAccount(string $uid, string $displayName): void {
		foreach ($this->loans->findAll() as $loan) {
			if ($loan->getHolderUid() !== $uid || $loan->getAnonymized() !== null) {
				continue;
			}
			if ($loan->getReturnedOn() !== null) {
				$this->folderBook->anonymizeLoan($loan, 'account deleted');
				continue;
			}
			$loan->setHolderUid(null);
			if (trim((string)$loan->getHolderName()) === '') {
				$loan->setHolderName($displayName);
			}
			$this->loans->update($loan);
			$this->auditEntries->anonymizeFields(FolderBookService::OBJECT_LOAN, $loan->getId(), ['holder_uid'], FolderBookService::ANONYMIZED);
			$this->audit->record($this->audit->newOperation(), null, FolderBookService::OBJECT_LOAN, $loan->getId(), 'changed', 'holder_uid',
				FolderBookService::ANONYMIZED, null, 'account deleted');
		}
		$this->auditEntries->removeAccount($uid, null);
	}

	/**
	 * Loans with personal data of an account or a name.
	 *
	 * @return list<FolderLoan>
	 */
	private function loansOf(string $who): array {
		return array_values(array_filter($this->loans->findAll(), fn (FolderLoan $loan): bool => $loan->getAnonymized() === null
			&& ($loan->getHolderUid() === $who || self::same($loan->getHolderName(), $who))));
	}

	private static function same(?string $name, string $who): bool {
		return $name !== null && mb_strtolower(trim($name)) === mb_strtolower(trim($who));
	}
}
