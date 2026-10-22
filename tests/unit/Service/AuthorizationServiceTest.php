<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service;

use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Service\AuthorizationService;
use OCA\ChoirScores\Service\Exceptions\PermissionDeniedException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AuthorizationService.
 */
final class AuthorizationServiceTest extends TestCase {
	private LoggerInterface $logger;
	private IL10N $l10n;
	private AuthorizationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->service = new AuthorizationService(
			$this->logger,
			'choirscores',
			$this->l10n
		);
	}

	public function testAuthorizePolicyAllowsWhenPolicyReturnsTrue(): void {
		$policy = $this->createMock(PolicyInterface::class);
		$policy->expects($this->once())
			->method('allows')
			->with(PolicyInterface::ACTION_READ, null)
			->willReturn(true);

		// Should not throw
		$this->service->authorizePolicy($policy, PolicyInterface::ACTION_READ);

		// No exception thrown means success
		$this->assertTrue(true);
	}

	public function testAuthorizePolicyThrowsWhenPolicyReturnsFalse(): void {
		$policy = $this->createMock(PolicyInterface::class);
		$policy->expects($this->once())
			->method('allows')
			->with(PolicyInterface::ACTION_CREATE, null)
			->willReturn(false);

		$this->l10n->expects($this->once())
			->method('t')
			->with('Insufficient permissions')
			->willReturn('Insufficient permissions');

		$this->logger->expects($this->once())
			->method('warning')
			->with('Policy denied', $this->callback(function ($context) use ($policy) {
				return $context['app'] === 'choirscores'
					&& $context['policy'] === $policy::class
					&& $context['action'] === PolicyInterface::ACTION_CREATE
					&& $context['subject'] === null;
			}));

		$this->expectException(PermissionDeniedException::class);
		$this->expectExceptionMessage('Insufficient permissions');

		$this->service->authorizePolicy($policy, PolicyInterface::ACTION_CREATE);
	}

	public function testAuthorizePolicyWithSubject(): void {
		$policy = $this->createMock(PolicyInterface::class);
		$subject = new \stdClass();

		$policy->expects($this->once())
			->method('allows')
			->with(PolicyInterface::ACTION_UPDATE, $subject)
			->willReturn(true);

		// Should not throw
		$this->service->authorizePolicy($policy, PolicyInterface::ACTION_UPDATE, $subject);

		$this->assertTrue(true);
	}

	public function testAuthorizePolicyLogsSubjectClassWhenDenied(): void {
		$policy = $this->createMock(PolicyInterface::class);
		$subject = new \stdClass();

		$policy->expects($this->once())
			->method('allows')
			->with(PolicyInterface::ACTION_DELETE, $subject)
			->willReturn(false);

		$this->l10n->expects($this->once())
			->method('t')
			->willReturn('Insufficient permissions');

		$this->logger->expects($this->once())
			->method('warning')
			->with('Policy denied', $this->callback(function ($context) use ($subject) {
				return $context['subject'] === \stdClass::class;
			}));

		$this->expectException(PermissionDeniedException::class);

		$this->service->authorizePolicy($policy, PolicyInterface::ACTION_DELETE, $subject);
	}
}
