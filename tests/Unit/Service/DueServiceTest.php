<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventItemMapper;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\ReleaseListService;
use OCA\Notenarchiv\Service\RepertoireService;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concerts with a deadline on the start page "due" (and so in the dashboard
 * widget): for the roles the notifications go to, and only for the modules
 * that are on.
 */
#[CoversClass(DueService::class)]
final class DueServiceTest extends TestCase {
	use ModuleSwitches;

	private const TODAY = '2026-10-22';

	/**
	 * @param list<string> $roles
	 * @param list<string> $off modules switched off
	 */
	private function service(array $roles, array $off = [], ?TaskService $tasks = null): DueService {
		$roleService = $this->createStub(Roles::class);
		$roleService->method('rolesOf')->willReturn($roles);
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		$events = $this->createStub(EventMapper::class);
		$events->method('findByKind')->willReturn([
			self::concert(1, '2026-12-31'),                       // 70 days: 10 weeks
			self::concert(2, '2026-11-20'),                       // 29 days: 6 weeks
			self::concert(3, '2026-10-01'),                       // past, not confirmed
			self::concert(4, '2026-10-01', 'sung'),               // past, confirmed
			self::concert(5, '2026-11-20', Event::CANCELLED),     // cancelled
			self::concert(6, '2026-11-20', 'fixed', true),        // imported history
			self::concert(7, '2027-06-01'),                       // far ahead
		]);
		if ($tasks === null) {
			$tasks = $this->createStub(TaskService::class);
			$tasks->method('forUser')->willReturn([['id' => 4, 'copy_id' => 35, 'status' => 'open']]);
		}
		$proposals = $this->createStub(ProposalMapper::class);
		$proposals->method('countOpen')->willReturn(2);
		return new DueService($roleService, $tasks, $proposals,
			$this->createStub(ReleaseListService::class), $this->createStub(LicenseMapper::class), $this->createStub(WorkVersionMapper::class),
			$this->createStub(WorkMapper::class), $rights, $this->createStub(RepertoireService::class), $this->createStub(EventItemMapper::class),
			$events, $this->modulesWithout($off));
	}

	private static function concert(int $id, string $date, string $status = 'draft', bool $imported = false): Event {
		$event = new Event();
		$event->setId($id);
		$event->setKind(Event::CONCERT);
		$event->setTitle('Konzert ' . $id);
		$event->setEventDate($date);
		$event->setStatus($status);
		$event->setImported($imported);
		return $event;
	}

	public function testTheConductorSeesDeadlinesAndConcertsToConfirm(): void {
		$concerts = $this->service([Roles::CONDUCTOR])->forUser('carla')['concerts'];
		self::assertSame([
			['id' => 3, 'title' => 'Konzert 3', 'date' => '2026-10-01', 'stage' => 'confirm', 'label' => 'Confirm what was sung'],
			['id' => 2, 'title' => 'Konzert 2', 'date' => '2026-11-20', 'stage' => '6', 'label' => 'Prepare the concert binders'],
			['id' => 1, 'title' => 'Konzert 1', 'date' => '2026-12-31', 'stage' => '10', 'label' => 'Fix the programme and the valid versions'],
		], $concerts);
	}

	public function testTheLibrarianSeesTheDeadlinesOnly(): void {
		$concerts = $this->service([Roles::LIBRARIAN])->forUser('lena')['concerts'];
		self::assertSame([2, 1], array_column($concerts, 'id'));
	}

	public function testNothingOfASwitchedOffModule(): void {
		$all = $this->service([Roles::CONDUCTOR, Roles::LIBRARIAN])->forUser('carla');
		self::assertNotSame([], $all['concerts']);
		self::assertContains('proposals', array_column($all['cards'], 'key'));

		$due = $this->service([Roles::CONDUCTOR, Roles::LIBRARIAN], ['concerts', 'proposals'])->forUser('carla');
		self::assertSame([], $due['concerts']);
		self::assertNotContains('proposals', array_column($due['cards'], 'key'));
		self::assertContains('tasks_open', array_column($due['cards'], 'key'), 'the other cards stay');
	}

	public function testTasksAreNotReadWithoutTheModule(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects(self::never())->method('forUser');
		$due = $this->service([Roles::CONDUCTOR], ['tasks'], $tasks)->forUser('carla');
		self::assertSame([], $due['tasks']);
		self::assertNotContains('tasks_open', array_column($due['cards'], 'key'));
	}

	public function testOtherRolesSeeNoConcerts(): void {
		self::assertSame([], $this->service([Roles::READER, Roles::OPERATIONS, Roles::CONTRIBUTOR])->forUser('rolf')['concerts']);
	}
}
