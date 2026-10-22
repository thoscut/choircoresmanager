<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Utility;

use OCA\ChoirScores\Utility\SignatureComparator;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SignatureComparator.
 */
final class SignatureComparatorTest extends TestCase {
	public function testSortsInNaturalOrderWithEmptySignaturesLast(): void {
		$signatures = ['10-1', '', '1-10', '7-1a', null, '1-9', '2-3', '7-2', '5-19', '7-1'];

		usort($signatures, [SignatureComparator::class, 'compare']);

		$this->assertSame(['1-9', '1-10', '2-3', '5-19', '7-1', '7-1a', '7-2', '10-1'], array_slice($signatures, 0, 8));
		$this->assertSame([null, null], array_map(fn (?string $s) => $s === '' ? null : $s, array_slice($signatures, 8)));
	}

	#[TestWith(['1-9', '1-10'])]
	#[TestWith(['1-10', '10-1'])]
	#[TestWith(['7-1', '7-1a'])]
	#[TestWith(['7-1a', '7-1b'])]
	#[TestWith(['A-2', 'b-1'])]
	#[TestWith(['99999999999999999999-1', '100000000000000000000-1'])]
	#[TestWith(['5-9', ''])]
	public function testFirstSortsBeforeSecond(string $first, string $second): void {
		$this->assertLessThan(0, SignatureComparator::compare($first, $second));
		$this->assertGreaterThan(0, SignatureComparator::compare($second, $first));
	}

	#[TestWith(['05-1', '5-1'])]
	#[TestWith(['7-1A', '7-1a'])]
	#[TestWith(['5 19', '5-19'])]
	#[TestWith(['', ''])]
	public function testEquivalentSignatures(string $first, string $second): void {
		$this->assertSame(0, SignatureComparator::compare($first, $second));
	}
}
