<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service;

use OCA\ChoirScores\Service\ConfigService;
use OCA\ChoirScores\Service\RepertoireClassifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RepertoireClassifier.
 */
final class RepertoireClassifierTest extends TestCase {
	private ConfigService $configService;
	private ITimeFactory $timeFactory;
	private IDateTimeZone $dateTimeZone;
	private RepertoireClassifier $classifier;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(ConfigService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->dateTimeZone = $this->createMock(IDateTimeZone::class);
		$this->dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));

		$this->classifier = new RepertoireClassifier($this->configService, $this->timeFactory, $this->dateTimeZone);
	}

	public static function classificationProvider(): array {
		// current year 2026, thresholds 3 and 10
		return [
			'this year' => ['2026-03-01', 'current'],
			'boundary of current' => ['2023-01-01', 'current'],
			'just outside current' => ['2022-12-31', 'dormant'],
			'boundary of dormant' => ['2016-06-30', 'dormant'],
			'just outside dormant' => ['2015-12-31', 'earlier'],
			'long ago' => ['1991-11-09', 'earlier'],
			'future date' => ['2027-01-01', 'current'],
			'no date' => [null, 'not_documented'],
			'not a date' => ['ca. 1990', 'not_documented'],
		];
	}

	#[DataProvider('classificationProvider')]
	public function testClassifyForYear(?string $lastPerformed, string $expected): void {
		$this->assertSame($expected, RepertoireClassifier::classifyForYear($lastPerformed, 2026, 3, 10));
	}

	public function testClassifyForYearWithZeroCurrentYears(): void {
		$this->assertSame('current', RepertoireClassifier::classifyForYear('2026-01-01', 2026, 0, 1));
		$this->assertSame('dormant', RepertoireClassifier::classifyForYear('2025-12-31', 2026, 0, 1));
		$this->assertSame('earlier', RepertoireClassifier::classifyForYear('2024-12-31', 2026, 0, 1));
	}

	public function testClassifyUsesConfiguredThresholdsAndLocalYear(): void {
		$this->configService->method('getRepertoireThresholds')
			->willReturn(['currentYears' => 1, 'dormantYears' => 5]);
		// New Year's Eve 23:30 UTC is already 2027 in Berlin
		$this->timeFactory->method('now')
			->willReturn(new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('UTC')));

		$this->assertSame('current', $this->classifier->classify('2026-05-01'));
		$this->assertSame('dormant', $this->classifier->classify('2025-05-01'));
		$this->assertSame('dormant', $this->classifier->classify('2022-05-01'));
		$this->assertSame('earlier', $this->classifier->classify('2021-05-01'));
		$this->assertSame('not_documented', $this->classifier->classify(null));
	}

	public function testToLocalDateUsesDefaultTimezone(): void {
		$utc = new \DateTimeImmutable('2025-12-31 23:30:00', new \DateTimeZone('UTC'));

		$this->assertSame('2026-01-01', $this->classifier->toLocalDate($utc));
	}
}
