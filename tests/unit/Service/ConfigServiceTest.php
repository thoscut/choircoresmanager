<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Service\ConfigService;
use OCP\AppFramework\Services\IAppConfig;
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
		$this->service = new ConfigService($this->appConfig);
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

	public function testGetRepertoireThresholdsUseDefaults(): void {
		$this->appConfig->method('getAppValueInt')
			->willReturnCallback(fn (string $key, int $default) => $default);

		$this->assertSame(ConfigService::DEFAULT_REPERTOIRE_CURRENT_YEARS, $this->service->getRepertoireCurrentYears());
		$this->assertSame(ConfigService::DEFAULT_REPERTOIRE_DORMANT_YEARS, $this->service->getRepertoireDormantYears());
	}

	public function testSetRepertoireThresholds(): void {
		$calls = [];
		$this->appConfig->method('setAppValueInt')
			->willReturnCallback(function (string $key, int $value) use (&$calls): bool {
				$calls[$key] = $value;
				return true;
			});

		$this->service->setRepertoireThresholds(2, 8);

		$this->assertSame(['repertoire_current_years' => 2, 'repertoire_dormant_years' => 8], $calls);
	}

	#[TestWith([0, 10])]
	#[TestWith([5, 3])]
	public function testSetRepertoireThresholdsRejectsInvalidValues(int $currentYears, int $dormantYears): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setRepertoireThresholds($currentYears, $dormantYears);
	}
}
