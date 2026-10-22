<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Dashboard;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\PersonalView;
use OCA\Notenarchiv\Service\Roles;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Dashboard widget "Music archive – due": what the start page of the app
 * shows for the user's roles, in the same order (concerts with a deadline,
 * the cards with a number, the tasks), each with a link into the app.
 *
 * Only accounts with an archive role get the widget, and only while the
 * module "dashboard_widget" is on; the items are checked again for the
 * account that asks for them. Cards and lists the account has hidden in its
 * personal view are left out here, too. Items contain no personal data:
 * concert titles and dates, card labels with their number, shelf mark and
 * title of the copy a task is about (as in the notifications), never the
 * text of a question or the name of a person.
 *
 * The Nextcloud dashboard shows the items itself (API widget), so the app
 * loads no script into the dashboard.
 */
class DueWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IConditionalWidget {
	public const ID = 'notenarchiv-due';

	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
		private IUserSession $userSession,
		private Roles $roles,
		private DueService $due,
		private Modules $modules,
		private PersonalView $personalView,
	) {
	}

	#[\Override]
	public function getId(): string {
		return self::ID;
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l->t('Music archive – due');
	}

	#[\Override]
	public function getOrder(): int {
		return 30;
	}

	#[\Override]
	public function getIconClass(): string {
		// The dashboard uses getIconUrl(); there is no CSS class for the icon.
		return '';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->appUrl();
	}

	#[\Override]
	public function load(): void {
		// API widget: the dashboard renders the items, nothing to load.
	}

	/**
	 * Only for accounts with an archive role (not for admins without one),
	 * while the module is on.
	 */
	#[\Override]
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->modules->isEnabled('dashboard_widget') && $this->roles->rolesOf($user->getUID()) !== [];
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->appUrl(), $this->l->t('Open the start page'))];
	}

	/**
	 * The due items of the account, at most $limit. $since is not used: the
	 * list is always the current state.
	 */
	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->modules->isEnabled('dashboard_widget')) {
			return new WidgetItems([], $this->l->t('This function is switched off.'));
		}
		if ($this->roles->rolesOf($userId) === []) {
			return new WidgetItems([], $this->l->t('You have no role in the music archive.'));
		}
		$due = $this->due->forUser($userId);
		$hidden = $this->personalView->get($userId)['cards'];
		$base = $this->appUrl();
		// One icon per kind: concert, card of the start page, task
		$concertIcon = $this->icon('dashboard-concert.svg');
		$cardIcon = $this->icon('dashboard-item.svg');
		$taskIcon = $this->icon('dashboard-task.svg');
		$items = [];
		foreach (in_array('concerts', $hidden, true) ? [] : $due['concerts'] as $concert) {
			$items[] = new WidgetItem(
				$concert['title'] !== '' ? $concert['title'] : $this->l->t('(no title)'),
				$this->formatDate($concert['date']) . ' · ' . $this->l->t($concert['label']),
				$base . '?event=' . $concert['id'],
				$concertIcon,
				'concert-' . $concert['id'] . '-' . $concert['stage'],
			);
		}
		foreach ($due['cards'] as $card) {
			if (in_array($card['key'], $hidden, true)) {
				continue;
			}
			$items[] = new WidgetItem(
				$this->l->t($card['label']),
				$this->l->n('%n entry', '%n entries', $card['count']),
				$base . '?due=' . rawurlencode($card['key']),
				$cardIcon,
				'card-' . $card['key'],
			);
		}
		foreach (in_array('tasks', $hidden, true) ? [] : $due['tasks'] as $task) {
			$id = $task['id'] ?? null;
			$copyId = $task['copy_id'] ?? null;
			if (!is_int($id) || !is_int($copyId)) {
				continue;
			}
			// Short title (the shelf mark), the work and the state in the second line
			$shelfmark = trim(self::text($task['shelfmark'] ?? null));
			$title = trim(self::text($task['title'] ?? null));
			$state = ($task['status'] ?? null) === 'answered' ? $this->l->t('Answered, to apply') : $this->l->t('Question or task for your role');
			$items[] = new WidgetItem(
				$shelfmark !== '' ? $shelfmark : ($title !== '' ? $title : $this->l->t('Task')),
				$shelfmark !== '' && $title !== '' ? $title . ' · ' . $state : $state,
				$base . '?copy=' . $copyId . '&task=' . $id,
				$taskIcon,
				'task-' . $id,
			);
		}
		return new WidgetItems(array_slice($items, 0, max(1, $limit)), $items === [] ? $this->l->t('Nothing is due for your roles.') : '');
	}

	private static function text(mixed $value): string {
		return is_string($value) ? $value : '';
	}

	private function icon(string $file): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, $file));
	}

	private function appUrl(): string {
		return $this->url->linkToRouteAbsolute(Application::APP_ID . '.page.index');
	}

	/**
	 * A date in the user's language; the ISO date if it cannot be formatted.
	 *
	 * @param string $date YYYY-MM-DD
	 */
	private function formatDate(string $date): string {
		try {
			$formatted = $this->l->l('date', new \DateTime($date . ' 12:00:00', new \DateTimeZone('UTC')), ['width' => 'medium']);
		} catch (\Exception) {
			return $date;
		}
		return is_string($formatted) && $formatted !== '' ? $formatted : $date;
	}
}
