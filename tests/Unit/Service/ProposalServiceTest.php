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
use OCA\Notenarchiv\Service\RightsService;
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
	private RightsService&MockObject $rights;
	/** @var list<Proposal> open proposals of the object asked for */
	private array $open = [];
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
		$mapper->method('findOpenForObject')->willReturnCallback(fn (): array => $this->open);
		$this->rights = $this->createMock(RightsService::class);
		$this->catalog = $this->createMock(CatalogService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1761134400);
		$this->service = new ProposalService($mapper, $this->catalog, $this->createStub(AuditLog::class), $this->createStub(Roles::class),
			$this->createStub(NotificationService::class), $time, $this->rights);
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

	public function testAProposalIsTakenAsAWholeOrNotAtAll(): void {
		try {
			$this->service->propose('version', 10, ['scoring' => 'TTBB', 'confirmed_at' => '2026-01-01'], 'anna', null, 'tool');
			self::fail('a decision was proposed');
		} catch (InvalidInputException $e) {
			self::assertSame('"%s" cannot be proposed.', $e->getMessage());
		}
		self::assertSame([], $this->inserted, 'nothing stored');
	}

	public function testOnlyFieldsOfTheCatalogLibrarianCanBeProposed(): void {
		$fields = ProposalService::proposableFields();
		self::assertSame(['work', 'version', 'copy'], array_keys($fields));
		self::assertContains('key_signature', $fields['version']);
		self::assertContains('rights_status', $fields['version'], 'a proposal, which only the catalog librarian can accept');
		foreach (['confirmed_at', 'confirmed_by', 'valid_since', 'portal_since', 'rights_confirmed'] as $decision) {
			self::assertNotContains($decision, $fields['version'], $decision);
		}
		self::assertNotContains('shelfmark', $fields['copy']);
	}

	public function testOwnProposalsAndTheirStatus(): void {
		$this->service->own('anna', null);
		$this->service->own('anna', '');
		$this->expectException(InvalidInputException::class);
		$this->service->own('anna', 'maybe');
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

	public function testThePortalIsChangedOnlyThroughTheRights(): void {
		$this->service->proposePortal(10, '2026-10-27', 'bob', 'In the export of the choir system.', 'Choir system: Singste.csv');
		$this->service->proposePortal(11, null, 'bob', 'Not in the export of the choir system.', 'Choir system: Singste.csv');
		self::assertSame(['"2026-10-27"', 'null'], [$this->inserted[0]->getNewValue(), $this->inserted[1]->getNewValue()]);
		$this->catalog->expects(self::never())->method('update');
		$this->rights->expects(self::once())->method('addToPortal')->with(10, 'bob', '2026-10-27');
		$this->rights->expects(self::once())->method('removeFromPortal')
			->with(11, 'bob', 'Proposal #2 (Choir system: Singste.csv): Not in the export of the choir system.');
		$this->service->accept(1, 'bob', null);
		$this->service->accept(2, 'bob', null);
	}

	public function testThePortalIsNotProposedTwice(): void {
		$open = new Proposal();
		$open->setField(ProposalService::PORTAL_FIELD);
		$open->setNewValue('"2026-10-01"');
		$this->open = [$open];
		$this->service->proposePortal(10, '2026-10-27', 'bob', '', 'Choir system');
		self::assertSame([], $this->inserted);
		$this->service->proposePortal(10, null, 'bob', '', 'Choir system');
		self::assertCount(1, $this->inserted, 'the other direction is a proposal of its own');
	}

	public function testNestedValuesAreRefused(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->propose('version', 10, ['scoring' => ['TTBB']], 'anna', null, null);
	}
}
