<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Service\ConfigService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigService.
 */
final class ConfigServiceTest extends TestCase {
	private IAppConfig $appConfig;
	private ConfigService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));
		$this->service = new ConfigService($this->appConfig, $l10n);
	}

	public function testGetAllowedEditGroups(): void {
		$expectedGroups = ['admin', 'editors', 'moderators'];

		$this->appConfig->expects($this->once())
			->method('getAppValueArray')
			->with('allowed_edit_groups')
			->willReturn($expectedGroups);

		$result = $this->service->getAllowedEditGroups();

		$this->assertSame($expectedGroups, $result);
	}

	public function testGetAllowedEditGroupsReturnsEmptyArray(): void {
		$this->appConfig->expects($this->once())
			->method('getAppValueArray')
			->with('allowed_edit_groups')
			->willReturn([]);

		$result = $this->service->getAllowedEditGroups();

		$this->assertSame([], $result);
	}

	public function testSetAllowedEditGroups(): void {
		$groups = ['admin', 'editors'];

		$this->appConfig->expects($this->once())
			->method('setAppValueArray')
			->with('allowed_edit_groups', $groups);

		$this->service->setAllowedEditGroups($groups);
	}

	public function testSetAllowedEditGroupsRemovesEmptyValues(): void {
		$groups = ['admin', '', 'editors', null, 'moderators'];
		$expectedGroups = ['admin', 'editors', 'moderators'];

		$this->appConfig->expects($this->once())
			->method('setAppValueArray')
			->with('allowed_edit_groups', $expectedGroups);

		$this->service->setAllowedEditGroups($groups);
	}

	public function testSetAllowedEditGroupsRemovesDuplicates(): void {
		$groups = ['admin', 'editors', 'admin', 'moderators', 'editors'];
		$expectedGroups = ['admin', 'editors', 'moderators'];

		$this->appConfig->expects($this->once())
			->method('setAppValueArray')
			->with('allowed_edit_groups', $expectedGroups);

		$this->service->setAllowedEditGroups($groups);
	}

	#[TestWith(['admin', ['admin', 'editors'], true])]
	#[TestWith(['moderators', ['admin', 'editors'], false])]
	#[TestWith(['admin', [], false])]
	#[TestWith(['editors', ['editors'], true])]
	public function testIsGroupAllowedToEdit(string $groupId, array $allowedGroups, bool $expected): void {
		$this->appConfig->expects($this->once())
			->method('getAppValueArray')
			->with('allowed_edit_groups')
			->willReturn($allowedGroups);

		$result = $this->service->isGroupAllowedToEdit($groupId);

		$this->assertSame($expected, $result);
	}

	/**
	 * Configure the mocked app config to return the given stored thresholds.
	 */
	private function storeThresholds(int $currentYears, int $dormantYears): void {
		$this->appConfig->method('getAppValueInt')
			->willReturnCallback(fn (string $key, int $default) => match ($key) {
				'repertoire_current_years' => $currentYears,
				'repertoire_dormant_years' => $dormantYears,
				default => $default,
			});
	}

	public function testGetRepertoireThresholdsUsesDefaults(): void {
		$this->appConfig->method('getAppValueInt')
			->willReturnCallback(fn (string $key, int $default) => $default);

		$this->assertSame(['currentYears' => 3, 'dormantYears' => 10], $this->service->getRepertoireThresholds());
	}

	public function testGetRepertoireThresholdsReturnsStoredValues(): void {
		$this->storeThresholds(5, 20);

		$this->assertSame(['currentYears' => 5, 'dormantYears' => 20], $this->service->getRepertoireThresholds());
	}

	#[TestWith([10, 3])]
	#[TestWith([4, 4])]
	#[TestWith([-1, 10])]
	#[TestWith([3, 101])]
	public function testGetRepertoireThresholdsFallsBackToDefaultsForInvalidStoredValues(int $currentYears, int $dormantYears): void {
		$this->storeThresholds($currentYears, $dormantYears);

		$this->assertSame(['currentYears' => 3, 'dormantYears' => 10], $this->service->getRepertoireThresholds());
	}

	#[TestWith([0, 1])]
	#[TestWith([3, 10])]
	#[TestWith([99, 100])]
	public function testSetRepertoireThresholdsStoresValidValues(int $currentYears, int $dormantYears): void {
		$stored = [];
		$this->appConfig->expects($this->exactly(2))
			->method('setAppValueInt')
			->willReturnCallback(function (string $key, int $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			});

		$this->service->setRepertoireThresholds($currentYears, $dormantYears);

		$this->assertSame([
			'repertoire_current_years' => $currentYears,
			'repertoire_dormant_years' => $dormantYears,
		], $stored);
	}

	#[TestWith([10, 3])]
	#[TestWith([5, 5])]
	#[TestWith([-1, 10])]
	#[TestWith([3, 101])]
	public function testSetRepertoireThresholdsRejectsInvalidValues(int $currentYears, int $dormantYears): void {
		$this->appConfig->expects($this->never())->method('setAppValueInt');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->setRepertoireThresholds($currentYears, $dormantYears);
	}

	public function testGetCatalogueColumnsReturnsOnlyStrings(): void {
		$this->appConfig->expects($this->once())
			->method('getAppValueArray')
			->with('catalogue_columns', [], true)
			->willReturn(['Signatur', 42, 'Titel', null]);

		$this->assertSame(['Signatur', 'Titel'], $this->service->getCatalogueColumns());
	}

	public function testSetCatalogueColumnsStoresLazily(): void {
		$this->appConfig->expects($this->once())
			->method('setAppValueArray')
			->with('catalogue_columns', ['Signatur', 'Neue Spalte'], true);

		$this->service->setCatalogueColumns(['Signatur', 'Neue Spalte']);
	}
}
