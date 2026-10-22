<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ReviewPoint;
use OCA\Notenarchiv\Db\ReviewPointMapper;
use OCA\Notenarchiv\Db\TranscriptionReportMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\ReviewService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who decides a review point and which decisions are allowed: the catalog
 * librarian decides every point, the conductor the musical ones; `corrected`
 * is set by the app only; a correction order needs its text, dismissing a
 * reason. Decisions are stored with the decider, never deleted.
 */
#[CoversClass(ReviewService::class)]
final class ReviewServiceTest extends TestCase {
	use ModuleSwitches;

	/** @var list<ReviewPoint> points written by the service */
	private array $updated = [];

	/**
	 * @param list<string> $roles roles of the account "anna"
	 * @param list<string> $off modules switched off
	 */
	private function service(ReviewPoint $point, array $roles, array $off = [], ?CopyMapper $copies = null): ReviewService {
		$points = $this->createStub(ReviewPointMapper::class);
		$points->method('find')->willReturn($point);
		$points->method('update')->willReturnCallback(function (ReviewPoint $p): ReviewPoint {
			$this->updated[] = $p;
			return $p;
		});
		$rolesService = $this->createStub(Roles::class);
		$rolesService->method('rolesOf')->willReturn($roles);
		$rolesService->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => array_intersect($roles, $allowed) !== []);
		$rolesService->method('requireRole')->willReturnCallback(function (string $uid, array $allowed) use ($roles): void {
			if (array_intersect($roles, $allowed) === []) {
				throw new AccessDeniedException('This requires one of these roles: %s', [implode(', ', $allowed)]);
			}
		});
		$users = $this->createStub(IUserManager::class);
		$users->method('getDisplayName')->willReturn('Anna');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1761150000);
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []): string => vsprintf($text, $p));
		return new ReviewService($this->createStub(IDBConnection::class), $points, $this->createStub(TranscriptionReportMapper::class),
			$copies ?? $this->createStub(CopyMapper::class), $this->createStub(WorkVersionMapper::class), $this->createStub(WorkMapper::class),
			$this->createStub(ArchiveFileMapper::class), $this->createStub(AuditLog::class), $rolesService, $this->modulesWithout($off),
			$this->createStub(NotificationService::class), $this->createStub(ProposalService::class), $this->createStub(TaskService::class),
			$this->createStub(AppSettings::class), $this->createStub(IRootFolder::class), $users, $time, $this->createStub(IDateTimeZone::class),
			$l, $this->createStub(LoggerInterface::class));
	}

	private static function point(string $role, string $status = ReviewPoint::OPEN): ReviewPoint {
		$point = new ReviewPoint();
		$point->setId(12);
		$point->setVersionId(3);
		$point->setCopyId(5);
		$point->setCategory($role === Roles::CONDUCTOR ? ReviewPoint::UNCERTAIN : ReviewPoint::MATERIAL);
		$point->setRole($role);
		$point->setStatus($status);
		$point->setText('d or c');
		return $point;
	}

	public function testTheConductorDecidesMusicalPoints(): void {
		$point = self::point(Roles::CONDUCTOR);
		$decided = $this->service($point, [Roles::CONDUCTOR])->decide(12, ReviewPoint::CONFIRMED, null, ' as in the scan ', null, 'anna');
		self::assertSame(ReviewPoint::CONFIRMED, $decided->getStatus());
		self::assertSame('anna', $decided->getDecidedBy());
		self::assertSame('Anna', $decided->getDecidedByName());
		self::assertSame(1761150000, $decided->getDecided());
		self::assertSame('as in the scan', $decided->getDecisionReason());
		self::assertSame([$point], $this->updated);
	}

	public function testOnlyTheLibrarianDecidesTheMaterial(): void {
		$this->expectExceptionObject(new AccessDeniedException('Only the catalog librarian decides this review point.'));
		$this->service(self::point(Roles::LIBRARIAN), [Roles::CONDUCTOR, Roles::READER])->decide(12, ReviewPoint::CONFIRMED, null, null, null, 'anna');
	}

	public function testTheLibrarianDecidesEverything(): void {
		$decided = $this->service(self::point(Roles::CONDUCTOR), [Roles::LIBRARIAN])->decide(12, ReviewPoint::CORRECT, 'd, as in the scan', null, null, 'anna');
		self::assertSame(ReviewPoint::CORRECT, $decided->getStatus());
		self::assertSame('d, as in the scan', $decided->getCorrection());
	}

	public function testAReaderDecidesNothing(): void {
		$this->expectException(AccessDeniedException::class);
		$this->service(self::point(Roles::CONDUCTOR), [Roles::READER, Roles::CONTRIBUTOR])->decide(12, ReviewPoint::CONFIRMED, null, null, null, 'anna');
	}

	public function testACorrectionOrderNeedsItsText(): void {
		$this->expectExceptionObject(new InvalidInputException('Please describe the correction.'));
		$this->service(self::point(Roles::CONDUCTOR), [Roles::CONDUCTOR])->decide(12, ReviewPoint::CORRECT, '  ', null, null, 'anna');
	}

	public function testDismissingNeedsAReason(): void {
		$this->expectExceptionObject(new InvalidInputException('Please give a reason.'));
		$this->service(self::point(Roles::CONDUCTOR), [Roles::CONDUCTOR])->decide(12, ReviewPoint::DISMISSED, null, null, null, 'anna');
	}

	public function testAQuestionNeedsTheTasks(): void {
		$this->expectException(ModuleDisabledException::class);
		$this->service(self::point(Roles::CONDUCTOR), [Roles::LIBRARIAN], ['tasks'])->decide(12, ReviewPoint::ASKED, null, null, null, 'anna');
	}

	public function testNothingWorksWithTheModuleOff(): void {
		$this->expectException(ModuleDisabledException::class);
		$this->service(self::point(Roles::CONDUCTOR), [Roles::LIBRARIAN], ['transcription_review'])->decide(12, ReviewPoint::CONFIRMED, null, null, null, 'anna');
	}

	public function testReopeningADecidedPoint(): void {
		$decided = $this->service(self::point(Roles::CONDUCTOR, ReviewPoint::DISMISSED), [Roles::CONDUCTOR])->decide(12, ReviewPoint::OPEN, null, 'look again', null, 'anna');
		self::assertSame(ReviewPoint::OPEN, $decided->getStatus());
	}

	public function testTransitions(): void {
		foreach (ReviewService::DECISIONS as $status) {
			ReviewService::checkTransition($status === ReviewPoint::OPEN ? ReviewPoint::CONFIRMED : ReviewPoint::OPEN, $status);
		}
		$refused = [
			[ReviewPoint::OPEN, ReviewPoint::CORRECTED, 'A review point is set to corrected only by a correction or a new version.'],
			[ReviewPoint::OPEN, ReviewPoint::OPEN, 'The review point already has this status.'],
			[ReviewPoint::CONFIRMED, ReviewPoint::CONFIRMED, 'The review point already has this status.'],
			[ReviewPoint::OPEN, 'deleted', '"%s": value not allowed.'],
		];
		foreach ($refused as [$from, $to, $message]) {
			try {
				ReviewService::checkTransition($from, $to);
				self::fail($from . ' → ' . $to);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}

	public function testWhoMayDecide(): void {
		self::assertTrue(ReviewService::mayDecide([Roles::LIBRARIAN], Roles::CONDUCTOR));
		self::assertTrue(ReviewService::mayDecide([Roles::LIBRARIAN], Roles::LIBRARIAN));
		self::assertTrue(ReviewService::mayDecide([Roles::CONDUCTOR], Roles::CONDUCTOR));
		self::assertFalse(ReviewService::mayDecide([Roles::CONDUCTOR], Roles::LIBRARIAN));
		self::assertFalse(ReviewService::mayDecide([Roles::OPERATIONS, Roles::READER, Roles::CONTRIBUTOR], Roles::CONDUCTOR));
		self::assertFalse(ReviewService::mayDecide([], Roles::CONDUCTOR));
	}

	/**
	 * A report handed in through the API for agents for one copy must name
	 * exactly that copy, and only the contributor and the catalog librarian
	 * hand in reports.
	 */
	public function testAReportForACopyMustNameThatCopy(): void {
		$copy = new Copy();
		$copy->setId(5);
		$copy->setVersionId(3);
		$copy->setShelfmark('5-1');
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($copy);
		$other = '{"format":"bericht/1","signatur":"5-02","basisname":"5-02_Titel"}';
		try {
			$this->service(self::point(Roles::CONDUCTOR), [Roles::CONTRIBUTOR], [], $copies)->ingestForCopy(5, $other, 'agent');
			self::fail('a report of another copy of the version was read');
		} catch (InvalidInputException $e) {
			self::assertSame('The shelf mark of the report does not belong to this copy.', $e->getMessage());
		}

		$this->expectException(AccessDeniedException::class);
		$this->service(self::point(Roles::CONDUCTOR), [Roles::READER, Roles::CONDUCTOR, Roles::OPERATIONS], [], $copies)
			->ingestForCopy(5, $other, 'agent');
	}

	public function testCatalogFieldsOfAReport(): void {
		self::assertSame(['version', 'publisher'], ReviewService::catalogField('Verlag / Bestellnummer'));
		self::assertSame(['work', 'composer'], ReviewService::catalogField('Komponist'));
		self::assertSame(['version', 'scoring'], ReviewService::catalogField('version.scoring'));
		self::assertNull(ReviewService::catalogField('version.unknown_field'));
		self::assertNull(ReviewService::catalogField('Lieblingsfarbe'));
		self::assertNull(ReviewService::catalogField('oc_users.password'));
	}
}
