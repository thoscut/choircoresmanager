<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposalService::class)]
#[CoversClass(Proposal::class)]
final class ProposalServiceTest extends TestCase {
	private CatalogService&MockObject $catalog;
	private ProposalService $service;
	/** @var list<Proposal> */
	private array $inserted = [];

	#[\Override]
	protected function setUp(): void {
		$mapper = $this->createStub(ProposalMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Proposal $proposal): Proposal {
			$proposal->setId(count($this->inserted) + 1);
			$this->inserted[] = $proposal;
			return $proposal;
		});
		$mapper->method('find')->willReturnCallback(fn (int $id): Proposal => $this->inserted[$id - 1]);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('countOpen')->willReturn(1);
		$this->catalog = $this->createMock(CatalogService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1761134400);
		$this->service = new ProposalService($mapper, $this->catalog, $this->createStub(AuditLog::class), $this->createStub(Roles::class),
			$this->createStub(NotificationService::class), $time);
	}

	/**
	 * Falsy values must survive: `json_encode(0) ?: null` once stored null for 0.
	 *
	 * @return iterable<string, array{0: string, 1: string|int|bool|null, 2: string}>
	 */
	public static function values(): iterable {
		yield 'zero' => ['rule1_count', 0, '0'];
		yield 'text zero' => ['key_signature', '0', '"0"'];
		yield 'false' => ['preservation_copy', false, 'false'];
		yield 'empty text' => ['scoring', '', '""'];
		yield 'null' => ['scoring', null, 'null'];
		yield 'umlauts' => ['scoring', 'Männerchor', '"Männerchor"'];
	}

	#[DataProvider('values')]
	public function testProposedValuesAreStoredExactly(string $field, string|int|bool|null $value, string $json): void {
		$this->service->propose('version', 10, [$field => $value], 'anna', null, 'round 40');
		self::assertSame($json, $this->inserted[0]->getNewValue());
		self::assertSame($value, $this->inserted[0]->value());
	}

	public function testAcceptingAppliesTheExactValue(): void {
		$this->service->propose('version', 10, ['rule1_count' => 0], 'anna', 'counted twice', null);
		$this->catalog->expects(self::once())->method('update')
			->with('version', 10, ['rule1_count' => 0], 'bob', 'Proposal #1: counted twice');
		$this->service->accept(1, 'bob', null);
		self::assertSame(Proposal::ACCEPTED, $this->inserted[0]->getStatus());
	}

	public function testReadOnlyFieldsCannotBeProposed(): void {
		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('"%s" cannot be proposed.');
		$this->service->propose('version', 10, ['confirmed_by' => 'mallory'], 'anna', null, null);
	}

	public function testNestedValuesAreRefused(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->propose('version', 10, ['scoring' => ['TTBB']], 'anna', null, null);
	}
}
