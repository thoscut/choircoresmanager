<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Person;
use OCA\Notenarchiv\Service\ProtectionTerm;
use OCA\Notenarchiv\Service\Sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectionTerm::class)]
#[UsesClass(Sort::class)]
final class ProtectionTermTest extends TestCase {
	private static int $nextId = 1;

	private static function person(string $name, ?string $died, ?string $diedPrecision, ?string $identification = 'certain'): Person {
		$person = Person::fromParams(['name' => $name, 'died' => $died, 'diedPrecision' => $diedPrecision, 'identification' => $identification]);
		$person->setId(self::$nextId++);
		return $person;
	}

	public function testWithoutPersonsTheVersionCountsAsProtected(): void {
		$proposal = ProtectionTerm::propose([], 2026);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame('no_persons', $proposal['reason']);
		self::assertNull($proposal['until']);
		self::assertSame([], $proposal['blocking']);
	}

	public function testProtectionEndsSeventyYearsAfterTheEndOfTheYearOfDeath(): void {
		$mozart = self::person('Wolfgang Amadeus Mozart', '1791-12-05', 'exact');
		$proposal = ProtectionTerm::propose([$mozart], 2026);
		self::assertSame([
			'status' => ProtectionTerm::FREE,
			'until' => '1861-12-31',
			'freeSince' => '1862-01-01',
			'reason' => 'ended',
			'parameters' => ['Wolfgang Amadeus Mozart', 1791, 1861],
			'decisivePersonId' => $mozart->getId(),
			'blocking' => [],
		], $proposal);
	}

	/**
	 * @return iterable<string, array{0: int, 1: string}>
	 */
	public static function boundary(): iterable {
		// died 1955 => protected until 2025-12-31
		yield 'last year of protection' => [2025, ProtectionTerm::PROTECTED];
		yield 'first free year' => [2026, ProtectionTerm::FREE];
		yield 'long before' => [1990, ProtectionTerm::PROTECTED];
	}

	#[DataProvider('boundary')]
	public function testBoundaryYear(int $currentYear, string $status): void {
		$proposal = ProtectionTerm::propose([self::person('Hugo Distler', '1942', 'year'), self::person('Arranger', '1955', 'year')], $currentYear);
		self::assertSame($status, $proposal['status']);
		self::assertSame('2025-12-31', $proposal['until']);
		self::assertSame($status === ProtectionTerm::FREE ? 'ended' : 'running', $proposal['reason']);
	}

	public function testTheLastPersonToDieDecides(): void {
		$composer = self::person('Composer', '1900', 'year');
		$arranger = self::person('Arranger', '1980', 'year');
		$translator = self::person('Translator', '1950', 'year');
		$proposal = ProtectionTerm::propose([$composer, $arranger, $translator], 2026);
		self::assertSame(ProtectionTerm::PROTECTED, $proposal['status']);
		self::assertSame($arranger->getId(), $proposal['decisivePersonId']);
		self::assertSame('2050-12-31', $proposal['until']);
	}

	/**
	 * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string, 3: string}>
	 */
	public static function blockingPersons(): iterable {
		// died, precision, identification, reason
		yield 'living' => [null, 'living', 'certain', 'living'];
		yield 'year of death unknown' => [null, 'unknown', 'certain', 'death_unknown'];
		yield 'no precision' => ['1900', null, 'certain', 'death_unknown'];
		yield 'approximate year of death' => ['1900', 'circa', 'certain', 'death_circa'];
		yield 'identification uncertain' => ['1900', 'year', 'uncertain', 'identification_uncertain'];
		yield 'identification not recorded' => ['1900', 'year', null, 'identification_uncertain'];
	}

	#[DataProvider('blockingPersons')]
	public function testUnknownMeansProtected(?string $died, ?string $precision, ?string $identification, string $reason): void {
		$blocking = self::person('Somebody', $died, $precision, $identification);
		self::assertSame($reason, ProtectionTerm::blockingReason($blocking));

		// Even next to a person whose protection has long ended, there is no "free" proposal.
		$proposal = ProtectionTerm::propose([self::person('Old master', '1700', 'year'), $blocking], 2026);
		self::assertSame(ProtectionTerm::UNKNOWN, $proposal['status']);
		self::assertSame($reason, $proposal['reason']);
		self::assertSame(['Somebody'], $proposal['parameters']);
		self::assertSame([['personId' => $blocking->getId(), 'name' => 'Somebody', 'reason' => $reason]], $proposal['blocking']);
		self::assertNull($proposal['until']);
	}

	public function testSecuredPersonsDoNotBlock(): void {
		self::assertNull(ProtectionTerm::blockingReason(self::person('A', '1900', 'year', 'certain')));
		self::assertNull(ProtectionTerm::blockingReason(self::person('B', '1900-05-01', 'exact', 'probable')));
	}

	public function testTheMostSeriousReasonComesFirst(): void {
		$uncertain = self::person('Uncertain', '1900', 'year', 'uncertain');
		$circa = self::person('Circa', '1900', 'circa');
		$living = self::person('Living', null, 'living');
		$proposal = ProtectionTerm::propose([$uncertain, $circa, $living], 2026);
		self::assertSame('living', $proposal['reason']);
		self::assertSame(['living', 'death_circa', 'identification_uncertain'], array_column($proposal['blocking'], 'reason'));
	}

	public function testYearsBeforeChrist(): void {
		$horace = self::person('Horaz', '-8', 'year');
		$proposal = ProtectionTerm::propose([$horace], 2026);
		self::assertSame(ProtectionTerm::FREE, $proposal['status']);
		self::assertSame('0062-12-31', $proposal['until']);
		self::assertSame([$horace->getName(), -8, 62], $proposal['parameters']);
	}
}
