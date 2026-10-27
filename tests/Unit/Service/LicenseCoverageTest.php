<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\License;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Service\LicenseCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Photocopies, scans and transcriptions of a version that is not confirmed
 * public domain are warned about without a license that covers them
 * (milestone 0.11).
 */
#[CoversClass(LicenseCoverage::class)]
final class LicenseCoverageTest extends TestCase {
	private const TODAY = '2026-10-27';

	/**
	 * @param array<string, mixed> $values
	 */
	private static function version(array $values): WorkVersion {
		$version = new WorkVersion();
		$version->setId(10);
		foreach ($values as $column => $value) {
			$version->setValue($column, $value);
		}
		return $version;
	}

	private static function copy(?string $kind, ?int $copies, string $status = 'active'): Copy {
		$copy = new Copy();
		$copy->setVersionId(10);
		$copy->setKind($kind);
		$copy->setCopiesCount($copies);
		$copy->setStatus($status);
		return $copy;
	}

	private static function license(string $uses, ?int $quantity = null, ?string $until = null): License {
		return License::fromParams(['versionId' => 10, 'uses' => $uses, 'quantity' => $quantity, 'validUntil' => $until]);
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: list<Copy>, 2: list<License>, 3: list<string>}>
	 */
	public static function copyCases(): iterable {
		$protected = ['rights_status' => 'protected'];
		$confirmed = ['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01'];
		yield 'originals only' => [$protected, [self::copy('original', null)], [], []];
		yield 'a photocopy without a license' => [$protected, [self::copy('photocopy', null)], [], [LicenseCoverage::PHOTOCOPIES]];
		yield 'copies of an original without a license' => [$protected, [self::copy('original', 20)], [], [LicenseCoverage::PHOTOCOPIES]];
		yield 'a license for the binders' => [$protected, [self::copy('photocopy', 20)], [self::license('binder')], []];
		yield 'a license for the binders that ended' => [$protected, [self::copy('photocopy', 20)], [self::license('binder', null, '2026-10-01')],
			[LicenseCoverage::PHOTOCOPIES]];
		yield 'a license for the portal only' => [$protected, [self::copy('photocopy', 20)], [self::license('portal')], [LicenseCoverage::PHOTOCOPIES]];
		yield 'within the licensed quantity' => [$protected, [self::copy('photocopy', 12), self::copy('original', 8)],
			[self::license('binder', 15), self::license('binder', 5)], []];
		yield 'more copies than licensed' => [$protected, [self::copy('photocopy', 12), self::copy('original', 9)],
			[self::license('binder', 15), self::license('binder', 5)], [LicenseCoverage::TOO_MANY]];
		yield 'a license without quantity covers any number' => [$protected, [self::copy('photocopy', 99)],
			[self::license('binder', 15), self::license('binder')], []];
		yield 'retired copies do not count' => [$protected, [self::copy('photocopy', 30, 'retired')], [], []];
		yield 'open rights' => [['rights_status' => 'open'], [self::copy('photocopy', 3)], [], [LicenseCoverage::PHOTOCOPIES]];
		yield 'unconfirmed public domain' => [['rights_status' => 'public_domain'], [self::copy('photocopy', 3)], [], [LicenseCoverage::PHOTOCOPIES]];
		yield 'confirmed public domain' => [$confirmed, [self::copy('photocopy', 30)], [], []];
	}

	/**
	 * @param array<string, mixed> $values
	 * @param list<Copy> $copies
	 * @param list<License> $licenses
	 * @param list<string> $expected
	 */
	#[DataProvider('copyCases')]
	public function testPhotocopiesNeedALicenseForTheBinders(array $values, array $copies, array $licenses, array $expected): void {
		self::assertSame($expected, LicenseCoverage::copies(self::version($values), $copies, $licenses, self::TODAY));
	}

	public function testScansAndTranscriptionsNeedALicenseForDigitisation(): void {
		$protected = self::version(['rights_status' => 'protected']);
		self::assertSame(LicenseCoverage::DIGITISED, LicenseCoverage::digitisation($protected, ['pdf' => 1], [], self::TODAY));
		self::assertSame(LicenseCoverage::DIGITISED, LicenseCoverage::digitisation($protected, ['mxl' => 1], [self::license('portal')], self::TODAY));
		self::assertNull(LicenseCoverage::digitisation($protected, ['pdf' => 2, 'musicxml' => 1], [self::license('portal,digitisation')], self::TODAY));
		self::assertNull(LicenseCoverage::digitisation($protected, ['mp3' => 1, 'md' => 1], [], self::TODAY), 'recordings and analyses are no copies of the notes');
		self::assertNull(LicenseCoverage::digitisation($protected, [], [], self::TODAY));
		$confirmed = self::version(['rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']);
		self::assertNull(LicenseCoverage::digitisation($confirmed, ['pdf' => 1], [], self::TODAY));
	}
}
