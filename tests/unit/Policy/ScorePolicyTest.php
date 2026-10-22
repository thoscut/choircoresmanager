<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Policy;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\Service\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScorePolicy.
 */
final class ScorePolicyTest extends TestCase {
	private PermissionService $permissionService;
	private ScorePolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('isCurrentUserLoggedIn')->willReturn(true);
		$this->policy = new ScorePolicy($this->permissionService);
	}

	/**
	 * Test that actions behave as expected based on user permissions.
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
			'UPDATE action allowed when user can edit' => [PolicyInterface::ACTION_UPDATE, true, true],
			'UPDATE action denied when user cannot edit' => [PolicyInterface::ACTION_UPDATE, false, false],
			'DELETE action allowed when user can edit' => [PolicyInterface::ACTION_DELETE, true, true],
			'DELETE action denied when user cannot edit' => [PolicyInterface::ACTION_DELETE, false, false],
			'SHARE action allowed when user can edit' => [PolicyInterface::ACTION_SHARE, true, true],
			'SHARE action denied when user cannot edit' => [PolicyInterface::ACTION_SHARE, false, false],
			'Unknown action is always denied' => ['UNKNOWN_ACTION', null, false],
		];
	}

	public function testReadDeniedForAnonymousUser(): void {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isCurrentUserLoggedIn')->willReturn(false);
		$policy = new ScorePolicy($permissionService);

		$this->assertFalse($policy->allows(PolicyInterface::ACTION_READ));
	}

	/**
	 * @param string $publicationStatus Publication status of the score
	 * @param bool|null $canUserEdit Whether the user can edit (null means permission service should not be called)
	 * @param bool $expectedResult The expected result of the allows() call
	 */
	#[DataProvider('readMusicXmlProvider')]
	public function testReadMusicXml(string $publicationStatus, ?bool $canUserEdit, bool $expectedResult): void {
		$this->assertReadFile(ScorePolicy::ACTION_READ_MUSICXML, $publicationStatus, $canUserEdit, $expectedResult);
	}

	#[DataProvider('readMusicXmlProvider')]
	public function testReadPdf(string $publicationStatus, ?bool $canUserEdit, bool $expectedResult): void {
		$this->assertReadFile(ScorePolicy::ACTION_READ_PDF, $publicationStatus, $canUserEdit, $expectedResult);
	}

	private function assertReadFile(string $action, string $publicationStatus, ?bool $canUserEdit, bool $expectedResult): void {
		if ($canUserEdit === null) {
			$this->permissionService->expects($this->never())->method('canCurrentUserEdit');
		} else {
			$this->permissionService->method('canCurrentUserEdit')->willReturn($canUserEdit);
		}
		$score = new Score();
		$score->setPublicationStatus($publicationStatus);

		$this->assertSame($expectedResult, $this->policy->allows($action, $score));
	}

	public static function readMusicXmlProvider(): array {
		return [
			'approved score for everyone' => ['approved', null, true],
			'score to clarify for everyone' => ['to_clarify', null, true],
			'blocked score for everyone' => ['blocked', null, true],
			'preservation copy for editors' => ['internal_preservation_copy', true, true],
			'preservation copy denied for others' => ['internal_preservation_copy', false, false],
		];
	}

	public function testReadMusicXmlRequiresScoreSubject(): void {
		$this->assertFalse($this->policy->allows(ScorePolicy::ACTION_READ_MUSICXML));
		$this->assertFalse($this->policy->allows(ScorePolicy::ACTION_READ_MUSICXML, new \stdClass()));
	}

	public function testReadMusicXmlDeniedForAnonymousUser(): void {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isCurrentUserLoggedIn')->willReturn(false);
		$permissionService->method('canCurrentUserEdit')->willReturn(false);
		$policy = new ScorePolicy($permissionService);
		$score = new Score();
		$score->setPublicationStatus('approved');

		$this->assertFalse($policy->allows(ScorePolicy::ACTION_READ_MUSICXML, $score));
	}
}
