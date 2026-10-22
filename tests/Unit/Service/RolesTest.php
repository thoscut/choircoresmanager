<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Settings\Admin;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use OCP\Settings\ISettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who may change the settings: admins, and the groups the administration
 * has given the page "Administration → Music archive" (Administration
 * privileges). That gives no role and no access to the catalog.
 */
#[CoversClass(Roles::class)]
final class RolesTest extends TestCase {
	/** @var list<string> */
	private array $admins = ['root'];
	/** @var array<string, list<ISettings>> uid => settings of the section the account may open */
	private array $allowed = [];
	private bool $settingsFail = false;
	private int $settingsCalls = 0;

	private function roles(): Roles {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturn([]);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->admins, true));
		$groups->method('isInGroup')->willReturn(false);
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if ($uid === 'nobody') {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$settings = $this->createStub(ISettingsManager::class);
		$settings->method('getAllowedAdminSettings')->willReturnCallback(function (string $section, IUser $user): array {
			$this->settingsCalls++;
			self::assertSame('notenarchiv', $section);
			if ($this->settingsFail) {
				throw new \RuntimeException('broken settings class of another app');
			}
			$allowed = $this->allowed[$user->getUID()] ?? [];
			return $allowed === [] ? [] : [10 => $allowed];
		});
		return new Roles($appConfig, $groups, $users, $settings, $this->createStub(LoggerInterface::class));
	}

	public function testAdminsMayChangeTheSettings(): void {
		$roles = $this->roles();
		self::assertTrue($roles->canChangeSettings('root'));
		$roles->requireSettingsAccess('root');
		self::assertSame(0, $this->settingsCalls, 'admins need no lookup');
	}

	public function testTheGroupsTheSettingsWereGivenToMayChangeThem(): void {
		$this->allowed['anna'] = [new Admin()];
		$roles = $this->roles();
		self::assertTrue($roles->canChangeSettings('anna'));
		self::assertTrue($roles->canChangeSettings('anna'));
		self::assertSame(1, $this->settingsCalls, 'looked up once per request');
		// The settings give no role and no access to the catalog
		self::assertSame([], $roles->rolesOf('anna'));
		self::assertFalse($roles->hasAccess('anna'));
		self::assertFalse($roles->isAdmin('anna'));
	}

	public function testOtherSettingsOfTheSectionDoNotCount(): void {
		$this->allowed['bert'] = [$this->createStub(ISettings::class)];
		self::assertFalse($this->roles()->canChangeSettings('bert'));
	}

	public function testEveryoneElseIsRefused(): void {
		$roles = $this->roles();
		self::assertFalse($roles->canChangeSettings('carla'));
		self::assertFalse($roles->canChangeSettings('nobody'));
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Only administrators may change this.');
		$roles->requireSettingsAccess('carla');
	}

	public function testFailsClosedWhenTheSettingsCannotBeRead(): void {
		$this->allowed['anna'] = [new Admin()];
		$this->settingsFail = true;
		self::assertFalse($this->roles()->canChangeSettings('anna'));
	}
}
