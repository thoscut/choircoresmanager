<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Dashboard;

use OCA\Cantoria\Dashboard\DueWidget;
use OCA\Cantoria\Service\DueService;
use OCA\Cantoria\Service\PersonalView;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard widget: only for accounts with a role, the items of the
 * start page in its order, each with a link into the app, no personal data.
 */
#[CoversClass(DueWidget::class)]
final class DueWidgetTest extends TestCase {
	use ModuleSwitches;

	private const APP_URL = 'https://cloud.example.org/apps/cantoria/';

	/** @var array<string, list<string>> */
	private array $roles = ['carla' => ['conductor'], 'admin' => []];
	private ?string $sessionUser = 'carla';
	private int $dueCalls = 0;
	/** @var list<string> modules switched off */
	private array $off = [];
	/** @var list<string> cards hidden in the personal view */
	private array $hiddenCards = [];

	/** @var array{cards: list<array{key: string, count: int, label: string}>, tasks: list<array<string, mixed>>, concerts: list<array{id: int, title: string, date: string, stage: string, label: string}>} */
	private array $due = [
		'cards' => [
			['key' => 'tasks_open', 'count' => 2, 'label' => 'Open tasks for your role'],
			['key' => 'valid_missing', 'count' => 1, 'label' => 'Current repertoire: no valid version chosen'],
		],
		'tasks' => [
			['id' => 4, 'copy_id' => 35, 'status' => 'open', 'kind' => 'question', 'question' => 'Frau Meier fragt nach Takt 12', 'created_by' => 'meier',
				'shelfmark' => '5-1', 'title' => 'Freude, schöner Götterfunken'],
			['id' => 5, 'copy_id' => 36, 'status' => 'answered', 'kind' => 'check', 'question' => null, 'answered_by_name' => 'Anna Archiv',
				'shelfmark' => null, 'title' => null],
		],
		'concerts' => [
			['id' => 3, 'title' => 'Adventskonzert', 'date' => '2026-12-06', 'stage' => '8', 'label' => 'Clarify the rights of all pieces'],
			['id' => 9, 'title' => '', 'date' => '2026-10-01', 'stage' => 'confirm', 'label' => 'Confirm what was sung'],
		],
	];

	private function widget(): DueWidget {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => '[' . vsprintf($text, $parameters) . ']');
		$l->method('n')->willReturnCallback(fn (string $singular, string $plural, int $count): string
			=> '[' . str_replace('%n', (string)$count, $count === 1 ? $singular : $plural) . ']');
		$l->method('l')->willReturn(false);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(fn (string $route): string => $route === 'cantoria.page.index' ? self::APP_URL : 'wrong');
		$url->method('imagePath')->willReturnCallback(fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file);
		$url->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://cloud.example.org' . $path);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->sessionUser === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUser);
			return $user;
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('rolesOf')->willReturnCallback(fn (string $uid): array => $this->roles[$uid] ?? []);
		$due = $this->createStub(DueService::class);
		$due->method('forUser')->willReturnCallback(function (): array {
			$this->dueCalls++;
			return $this->due;
		});
		$personalView = $this->createStub(PersonalView::class);
		$personalView->method('get')->willReturnCallback(fn (): array => ['navigation' => [], 'cards' => $this->hiddenCards]);
		return new DueWidget($l, $url, $session, $roles, $due, $this->modulesWithout($this->off), $personalView);
	}

	public function testOnlyAccountsWithARoleGetTheWidget(): void {
		self::assertTrue($this->widget()->isEnabled());
		$this->sessionUser = 'admin';
		self::assertFalse($this->widget()->isEnabled(), 'an admin without a role');
		$this->sessionUser = 'stranger';
		self::assertFalse($this->widget()->isEnabled());
		$this->sessionUser = null;
		self::assertFalse($this->widget()->isEnabled(), 'nobody logged in');
	}

	public function testNoWidgetWhileTheModuleIsOff(): void {
		$this->off = ['dashboard_widget'];
		$widget = $this->widget();
		self::assertFalse($widget->isEnabled());
		self::assertSame([], $widget->getItemsV2('carla')->getItems());
		self::assertSame(0, $this->dueCalls, 'the start page data are not even computed');
	}

	public function testLeavesOutWhatThePersonHides(): void {
		$this->hiddenCards = ['concerts', 'valid_missing', 'tasks'];
		$links = array_map(fn (WidgetItem $item): string => $item->getLink(), $this->widget()->getItemsV2('carla', null, 10)->getItems());
		self::assertSame([self::APP_URL . '?due=tasks_open'], $links);
	}

	public function testDescribesItself(): void {
		$widget = $this->widget();
		self::assertSame('cantoria-due', $widget->getId());
		self::assertSame('[Cantoria – due]', $widget->getTitle());
		self::assertSame(self::APP_URL, $widget->getUrl());
		self::assertSame('https://cloud.example.org/apps/cantoria/img/app-dark.svg', $widget->getIconUrl());
		$buttons = $widget->getWidgetButtons('carla');
		self::assertCount(1, $buttons);
		self::assertInstanceOf(WidgetButton::class, $buttons[0]);
		self::assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		self::assertSame(self::APP_URL, $buttons[0]->getLink());
	}

	public function testShowsTheStartPageInItsOrderWithDeepLinks(): void {
		$items = $this->widget()->getItemsV2('carla', null, 10);
		self::assertSame('', $items->getEmptyContentMessage());
		$rows = array_map(fn (WidgetItem $item): array => [$item->getTitle(), $item->getSubtitle(), $item->getLink()], $items->getItems());
		self::assertSame([
			['Adventskonzert', '2026-12-06 · [Clarify the rights of all pieces]', self::APP_URL . '?event=3'],
			['[(no title)]', '2026-10-01 · [Confirm what was sung]', self::APP_URL . '?event=9'],
			['[Open tasks for your role]', '[2 entries]', self::APP_URL . '?due=tasks_open'],
			['[Current repertoire: no valid version chosen]', '[1 entry]', self::APP_URL . '?due=valid_missing'],
			['5-1', 'Freude, schöner Götterfunken · [Question or task for your role]', self::APP_URL . '?copy=35&task=4'],
			['[Task]', '[Answered, to apply]', self::APP_URL . '?copy=36&task=5'],
		], $rows);
		// One icon per kind
		$icons = array_map(fn (WidgetItem $item): string => basename($item->getIconUrl()), $items->getItems());
		self::assertSame(['dashboard-concert.svg', 'dashboard-concert.svg', 'dashboard-item.svg', 'dashboard-item.svg', 'dashboard-task.svg', 'dashboard-task.svg'], $icons);
		foreach ($items->getItems() as $item) {
			self::assertStringStartsWith('https://cloud.example.org/apps/cantoria/img/', $item->getIconUrl());
			self::assertNotSame('', $item->getSinceId());
		}
	}

	public function testContainsNoPersonalData(): void {
		$json = (string)json_encode($this->widget()->getItemsV2('carla', null, 10));
		foreach (['Meier', 'meier', 'Anna Archiv', 'Takt 12'] as $personal) {
			self::assertStringNotContainsString($personal, $json);
		}
	}

	public function testKeepsTheLimit(): void {
		$items = $this->widget()->getItemsV2('carla', null, 3)->getItems();
		self::assertCount(3, $items);
		self::assertSame(self::APP_URL . '?due=tasks_open', $items[2]->getLink());
	}

	public function testSaysWhenNothingIsDue(): void {
		$this->due = ['cards' => [], 'tasks' => [], 'concerts' => []];
		$items = $this->widget()->getItemsV2('carla');
		self::assertSame([], $items->getItems());
		self::assertSame('[Nothing is due for your roles.]', $items->getEmptyContentMessage());
	}

	public function testAnAccountWithoutRoleGetsNothing(): void {
		$items = $this->widget()->getItemsV2('admin');
		self::assertSame([], $items->getItems());
		self::assertSame(0, $this->dueCalls, 'the start page data are not even computed');
	}
}
