<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\WorkVersion;

/**
 * Whether the copies and files of a version are covered by its licenses
 * (milestone 0.11). Only warnings: nothing is blocked (guideline 1), the
 * catalog librarian decides.
 *
 * - Photocopies of sheet music need the consent of the rights holder, also
 *   for the choir's own use (§ 53 (4) a UrhG): a version that is not
 *   confirmed public domain with copies of the kind "Photocopy" or with a
 *   number of copies needs a license for copies for the binders; with
 *   quantities on all those licenses, no more copies than they allow.
 * - Scans and MusicXML transcriptions of such a version are copies too
 *   (§ 16 UrhG); a digital preservation copy rarely falls under § 53 (2):
 *   they need a license for digitisation.
 *
 * Messages are English source strings; callers translate them.
 */
final class LicenseCoverage {
	public const PHOTOCOPIES = 'Photocopies of a version that is not confirmed public domain, without a license for copies for the binders.';
	public const TOO_MANY = 'More copies than the licenses for the binders allow.';
	public const DIGITISED = 'Scans or transcriptions of a version that is not confirmed public domain, without a license for digitisation.';
	/** Types of files that are copies of the notes */
	private const DIGITAL_COPIES = ['pdf', 'mxl', 'musicxml', 'xml', 'mscz', 'capx', 'cap', 'sib'];

	/**
	 * Warnings about the photocopies of a version.
	 *
	 * @param list<Copy> $copies the copies of the version
	 * @param list<License> $licenses the licenses of the version
	 * @param string $day today (YYYY-MM-DD), for the validity of licenses
	 * @return list<string>
	 */
	public static function copies(WorkVersion $version, array $copies, array $licenses, string $day): array {
		if (self::isFree($version)) {
			return [];
		}
		$photocopies = 0;
		$hasPhotocopy = false;
		foreach ($copies as $copy) {
			if ($copy->getStatus() !== 'active') {
				continue;
			}
			$count = $copy->getCopiesCount() ?? 0;
			$photocopies += $count;
			$hasPhotocopy = $hasPhotocopy || $count > 0 || $copy->getKind() === 'photocopy';
		}
		if (!$hasPhotocopy) {
			return [];
		}
		$binder = array_values(array_filter($licenses, fn (License $license): bool => $license->covers('binder', $day)));
		if ($binder === []) {
			return [self::PHOTOCOPIES];
		}
		$quantities = array_map(fn (License $license): ?int => $license->getQuantity(), $binder);
		if (!in_array(null, $quantities, true) && $photocopies > array_sum($quantities)) {
			return [self::TOO_MANY];
		}
		return [];
	}

	/**
	 * The warning about the digital files of a version, or null.
	 *
	 * @param array<string, int> $fileCounts type => number of found files of the version's copies
	 * @param list<License> $licenses
	 */
	public static function digitisation(WorkVersion $version, array $fileCounts, array $licenses, string $day): ?string {
		if (self::isFree($version)) {
			return null;
		}
		$digital = array_sum(array_intersect_key($fileCounts, array_flip(self::DIGITAL_COPIES)));
		if ($digital === 0) {
			return null;
		}
		foreach ($licenses as $license) {
			if ($license->covers('digitisation', $day)) {
				return null;
			}
		}
		return self::DIGITISED;
	}

	private static function isFree(WorkVersion $version): bool {
		return $version->getRightsStatus() === 'public_domain' && $version->isConfirmed();
	}
}
