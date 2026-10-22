<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Policy;

use DateTimeImmutable;
use OCA\ChoirScores\Db\FolderCollectionVersion;
use OCA\ChoirScores\Policy\FolderCollectionVersionPolicy;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Service\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FolderCollectionVersionPolicy.
 */
final class FolderCollectionVersionPolicyTest extends TestCase {
	private PermissionService $permissionService;
	private FolderCollectionVersionPolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('isCurrentUserLoggedIn')->willReturn(true);
		$this->policy = new FolderCollectionVersionPolicy($this->permissionService);
	}

	/**
	 * Test that actions without subject-specific logic behave as expected based on user permissions.
	 *
	 * @param string $action The action to test
	 * @param bool|null $canUserEdit Whether the user can edit (null means permission service should not be called)
	 * @param bool $expectedResult The expected result of the allows() call
	 */
	#[DataProvider('allowsProvider')]
	public function testAllows(string $action, ?bool $canUserEdit, bool $expectedResult): void {
		if ($canUserEdit === null) {
			$this->permissionService->expects($this->never())
				->method('canCurrentUserEdit');
		} else {
			$this->permissionService->expects($this->once())
				->method('canCurrentUserEdit')
				->willReturn($canUserEdit);
		}

		$result = $this->policy->allows($action);

		$this->assertSame($expectedResult, $result);
	}

	public static function allowsProvider(): array {
		return [
			'READ action allowed for logged-in user' => [PolicyInterface::ACTION_READ, null, true],
			'CREATE action allowed when user can edit' => [PolicyInterface::ACTION_CREATE, true, true],
			'CREATE action denied when user cannot edit' => [PolicyInterface::ACTION_CREATE, false, false],
			'DELETE action allowed when user can edit' => [PolicyInterface::ACTION_DELETE, true, true],
			'DELETE action denied when user cannot edit' => [PolicyInterface::ACTION_DELETE, false, false],
			'SHARE action is always denied' => [PolicyInterface::ACTION_SHARE, null, false],
			'Unknown action is always denied' => ['UNKNOWN_ACTION', null, false],
		];
	}

	public function testUpdateActionAllowedForActiveVersionWhenUserCanEdit(): void {
		$version = new FolderCollectionVersion();
		$version->setId(1);
		$version->setFolderCollectionId(1);
		$version->setValidFrom(new DateTimeImmutable('2024-01-01'));
		// validTo is null, making this an active version
		$version->setValidTo(null);

		$this->permissionService->expects($this->once())
			->method('canCurrentUserEdit')
			->willReturn(true);

		$result = $this->policy->allows(PolicyInterface::ACTION_UPDATE, $version);

		$this->assertTrue($result);
	}

	public function testUpdateActionDeniedForActiveVersionWhenUserCannotEdit(): void {
		$version = new FolderCollectionVersion();
		$version->setId(1);
		$version->setFolderCollectionId(1);
		$version->setValidFrom(new DateTimeImmutable('2024-01-01'));
		$version->setValidTo(null);

		$this->permissionService->expects($this->once())
			->method('canCurrentUserEdit')
			->willReturn(false);

		$result = $this->policy->allows(PolicyInterface::ACTION_UPDATE, $version);

		$this->assertFalse($result);
	}

	public function testUpdateActionDeniedForInactiveVersion(): void {
		$version = new FolderCollectionVersion();
		$version->setId(1);
		$version->setFolderCollectionId(1);
		$version->setValidFrom(new DateTimeImmutable('2024-01-01'));
		// validTo is set, making this an inactive version
		$version->setValidTo(new DateTimeImmutable('2024-12-31'));

		$this->permissionService->expects($this->once())
			->method('canCurrentUserEdit')
			->willReturn(true);

		$result = $this->policy->allows(PolicyInterface::ACTION_UPDATE, $version);

		$this->assertFalse($result);
	}

	public function testUpdateActionDeniedWhenNoSubjectProvided(): void {
		$this->permissionService->expects($this->once())
			->method('canCurrentUserEdit')
			->willReturn(true);

		$result = $this->policy->allows(PolicyInterface::ACTION_UPDATE, null);

		$this->assertFalse($result);
	}

	public function testUpdateActionDeniedWhenSubjectIsNotFolderCollectionVersion(): void {
		$this->permissionService->expects($this->once())
			->method('canCurrentUserEdit')
			->willReturn(true);

		$result = $this->policy->allows(PolicyInterface::ACTION_UPDATE, new \stdClass());

		$this->assertFalse($result);
	}

	public function testReadDeniedForAnonymousUser(): void {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isCurrentUserLoggedIn')->willReturn(false);
		$policy = new FolderCollectionVersionPolicy($permissionService);

		$this->assertFalse($policy->allows(PolicyInterface::ACTION_READ));
	}
}
