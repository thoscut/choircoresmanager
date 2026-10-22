<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\BackgroundJob;

use OCA\Notenarchiv\BackgroundJob\AnnualSnapshotJob;
use OCA\Notenarchiv\BackgroundJob\ChecksumJob;
use OCA\Notenarchiv\BackgroundJob\DailyChecks;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\AnnualSnapshotService;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ChecksumService;
use OCA\Notenarchiv\Service\FolderBookService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\PersonService;
use OCA\Notenarchiv\Service\PrintMirrorService;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The background jobs leave out what is switched off: the checksum job does
 * nothing, the daily checks neither compute nor notify the parts whose
 * notification is off, and withdraw what they notified before.
 */
#[CoversClass(DailyChecks::class)]
#[CoversClass(ChecksumJob::class)]
#[CoversClass(AnnualSnapshotJob::class)]
final class JobsTest extends TestCase {
	use ModuleSwitches;

	/** @var array<string, string> lazy app config of the daily checks */
	private array $state = [];

	private static function runJob(object $job): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * @param list<string> $off modules switched off
	 */
	private function daily(array $off, NotificationService $notifications, ?PersonService $persons = null,
		?FolderBookService $folderBook = null, ?PrintMirrorService $printMirror = null): DailyChecks {
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 1, 'path' => '/Notenarchiv', 'user' => 'lena']);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn('2026-10-22');
		$rights->method('isOnPortal')->willReturn(true);
		$rights->method('portalDenial')->willReturn('Not released.');
		$concert = new Event();
		$concert->setId(1);
		$concert->setKind(Event::CONCERT);
		$concert->setTitle('Advent concert');
		$concert->setEventDate('2026-11-20');
		$concert->setStatus('draft');
		$concert->setImported(false);
		$events = $this->createStub(EventMapper::class);
		$events->method('findByKind')->willReturn([$concert]);
		$version = new WorkVersion();
		$version->setId(5);
		$version->setWorkId(5);
		$version->setRightsStatus('protected');
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('findAllById')->willReturn([5 => $version]);
		$work = new Work();
		$work->setId(5);
		$work->setTitle('Ave verum');
		$works = $this->createStub(WorkMapper::class);
		$works->method('findAllById')->willReturn([5 => $work]);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->state[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->state[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->state[$key]);
		});
		if ($persons === null) {
			$persons = $this->createStub(PersonService::class);
			$persons->method('protectionForVersions')->willReturn([]);
		}
		return new DailyChecks($this->createStub(ITimeFactory::class), $settings, $this->createStub(LicenseMapper::class), $versions,
			$works, $this->createStub(CopyMapper::class), $rights, $notifications, $config, $events,
			$persons, $this->modulesWithout($off), $folderBook ?? $this->createStub(FolderBookService::class),
			$printMirror ?? $this->createStub(PrintMirrorService::class));
	}

	public function testTheFolderBookIsAnonymisedEvenWithItsModuleOff(): void {
		$folderBook = $this->createMock(FolderBookService::class);
		$folderBook->expects(self::once())->method('anonymizeExpired')->with('2026-10-22');
		$printMirror = $this->createMock(PrintMirrorService::class);
		$printMirror->expects(self::once())->method('runDaily');
		self::runJob($this->daily(['folder_book', 'print_mirror'], $this->createStub(NotificationService::class), null, $folderBook, $printMirror));
	}

	/**
	 * @return NotificationService&MockObject
	 */
	private function notifications(): NotificationService {
		return $this->createMock(NotificationService::class);
	}

	public function testConcertDeadlinesAreNotifiedWhileConcertsAreOn(): void {
		$notifications = $this->notifications();
		$notifications->expects(self::exactly(2))->method('notifyRoles')->willReturnCallback(
			function (array $roles, string $subject): void {
				self::assertContains($subject, [NotificationService::CONCERT_DEADLINE, NotificationService::PORTAL_REMOVE]);
			});
		self::runJob($this->daily([], $notifications));
		self::assertSame('6', $this->state['notified_event_1']);
		self::assertSame('remove', $this->state['notified_portal_5']);
	}

	public function testNoConcertDeadlinesWithoutConcerts(): void {
		$this->state = ['notified_event_1' => '6'];
		$notifications = $this->notifications();
		$notifications->expects(self::never())->method('notifyRoles');
		$withdrawn = [];
		$notifications->method('withdraw')->willReturnCallback(function (string $subject, string $type, string $id) use (&$withdrawn): void {
			$withdrawn[] = [$subject, $type, $id];
		});
		self::runJob($this->daily(['concerts', 'portal'], $notifications));
		self::assertSame([[NotificationService::CONCERT_DEADLINE, 'event', '1']], $withdrawn, 'the earlier notification is withdrawn');
		self::assertArrayNotHasKey('notified_event_1', $this->state, 'notified again once concerts are on');

		$notifications = $this->notifications();
		$notifications->expects(self::once())->method('notifyRoles')->with(['conductor', 'librarian'], NotificationService::CONCERT_DEADLINE);
		self::runJob($this->daily(['portal'], $notifications));
		self::assertSame('6', $this->state['notified_event_1']);
	}

	public function testASwitchedOffOccasionAlone(): void {
		$notifications = $this->notifications();
		$notifications->expects(self::once())->method('notifyRoles')->with(self::anything(), NotificationService::PORTAL_REMOVE);
		self::runJob($this->daily(['notify_concert_deadline'], $notifications));
		self::assertArrayNotHasKey('notified_event_1', $this->state);
	}

	public function testTheProtectionTermsAreNotComputedWhenTheirNotificationIsOff(): void {
		$persons = $this->createMock(PersonService::class);
		$persons->expects(self::never())->method('protectionForVersions');
		self::runJob($this->daily(['protection_terms'], $this->createStub(NotificationService::class), $persons));

		$persons = $this->createMock(PersonService::class);
		$persons->expects(self::once())->method('protectionForVersions')->willReturn([]);
		self::runJob($this->daily([], $this->createStub(NotificationService::class), $persons));
	}

	/**
	 * @param list<string> $off modules switched off
	 */
	private function annualJob(array $off, AnnualSnapshotService $snapshots, string $today): AnnualSnapshotJob {
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 1, 'path' => '/Notenarchiv', 'user' => 'lena']);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn($today);
		return new AnnualSnapshotJob($this->createStub(ITimeFactory::class), $this->modulesWithout($off), $settings, $snapshots, $rights,
			$this->createStub(LoggerInterface::class));
	}

	public function testTheAnnualSnapshotIsMadeOnceInJanuaryForTheYearBefore(): void {
		$snapshots = $this->createMock(AnnualSnapshotService::class);
		$snapshots->method('isAuto')->willReturn(true);
		$snapshots->method('hasYear')->willReturnOnConsecutiveCalls(false, true);
		$snapshots->expects(self::once())->method('create')->with('lena', 2026, AnnualSnapshotService::TURN_OF_YEAR)
			->willReturn(['name' => '2026_20270101-010000', 'folder' => 'x', 'files' => [], 'manifest' => ['files' => 0, 'bytes' => 0, 'missing' => 0]]);
		$job = $this->annualJob([], $snapshots, '2027-01-01');
		self::runJob($job);
		self::runJob($job);
	}

	public function testNoAnnualSnapshotOutsideJanuaryWithoutTheSettingOrTheModule(): void {
		$snapshots = $this->createMock(AnnualSnapshotService::class);
		$snapshots->method('isAuto')->willReturn(true);
		$snapshots->method('hasYear')->willReturn(false);
		$snapshots->expects(self::never())->method('create');
		self::runJob($this->annualJob([], $snapshots, '2026-10-22'));
		self::runJob($this->annualJob([Modules::ANNUAL_SNAPSHOT], $snapshots, '2027-01-02'));

		$off = $this->createMock(AnnualSnapshotService::class);
		$off->method('isAuto')->willReturn(false);
		$off->expects(self::never())->method('create');
		self::runJob($this->annualJob([], $off, '2027-01-02'));
	}

	public function testAFailedAnnualSnapshotIsShownWithWhatToDo(): void {
		$snapshots = $this->createMock(AnnualSnapshotService::class);
		$snapshots->method('isAuto')->willReturn(true);
		$snapshots->method('hasYear')->willReturn(false);
		$snapshots->method('create')->willThrowException(new \RuntimeException('disk full'));
		$snapshots->expects(self::once())->method('setLastError')
			->with(self::stringStartsWith('The automatic snapshot failed.'), []);
		self::runJob($this->annualJob([], $snapshots, '2027-01-03'));
	}

	public function testTheChecksumJobDoesNothingWhileItsModuleIsOff(): void {
		$checksums = $this->createMock(ChecksumService::class);
		$checksums->expects(self::never())->method('run');
		self::runJob(new ChecksumJob($this->createStub(ITimeFactory::class), $checksums, $this->createStub(LoggerInterface::class),
			$this->modulesWithout(['checksums'])));

		$this->moduleConfig = [];
		$checksums = $this->createMock(ChecksumService::class);
		$checksums->expects(self::once())->method('run')
			->willReturn(['checked' => 0, 'stored' => 0, 'changed' => 0, 'mismatches' => 0, 'missing' => 0, 'bytes' => 0]);
		self::runJob(new ChecksumJob($this->createStub(ITimeFactory::class), $checksums, $this->createStub(LoggerInterface::class),
			$this->modulesWithout()));
	}
}
