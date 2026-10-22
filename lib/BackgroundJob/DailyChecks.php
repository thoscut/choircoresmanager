<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\BackgroundJob;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\Event;
use OCA\Notenarchiv\Db\EventMapper;
use OCA\Notenarchiv\Db\LicenseMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ConcertDeadlines;
use OCA\Notenarchiv\Service\FieldValue;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\PersonService;
use OCA\Notenarchiv\Service\ProtectionTerm;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Daily checks that turn into notifications for the catalog librarian:
 * - licenses ending within 30 days, and ended licenses
 * - versions still in the singers' portal that are no longer released
 * - concert deadlines and concerts to confirm as sung (to the conductor)
 * - protected versions whose protection has ended by the life dates of all
 *   persons involved (resubmission; the rights status is not changed)
 *
 * Each case is notified once (state kept in lazy app config) and withdrawn
 * when it is resolved.
 *
 * A part whose notification is switched off (module `notify_<subject>`) is
 * not computed; its notifications are withdrawn and its state is cleared,
 * so that the cases are notified again once it is switched on.
 */
class DailyChecks extends TimedJob {
	private const WARN_DAYS = 30;

	public function __construct(
		ITimeFactory $time,
		private AppSettings $settings,
		private LicenseMapper $licenses,
		private WorkVersionMapper $versions,
		private WorkMapper $works,
		private CopyMapper $copies,
		private RightsService $rights,
		private NotificationService $notifications,
		private IAppConfig $appConfig,
		private EventMapper $events,
		private PersonService $persons,
		private Modules $modules,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$uid = $this->settings->archiveInfo()['user'];
		$today = $uid === '' ? gmdate('Y-m-d') : $this->rights->today($uid);
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();
		$firstCopy = [];
		foreach ($this->copies->findAll() as $copy) {
			$firstCopy[$copy->getVersionId()] ??= $copy;
		}
		$describe = function (int $versionId) use ($versions, $works, $firstCopy): array {
			$version = $versions[$versionId] ?? null;
			$copy = $firstCopy[$versionId] ?? null;
			return [
				'shelfmark' => $copy?->getShelfmark() ?? '',
				'title' => $version === null ? '' : ($works[$version->getWorkId()]?->getTitle() ?? ''),
				'copyId' => $copy?->getId(),
			];
		};

		$licensesByVersion = $this->licenses->findAllByVersion();
		$warnUntil = (new \DateTimeImmutable($today))->modify('+' . self::WARN_DAYS . ' days')->format('Y-m-d');
		foreach ($licensesByVersion as $versionId => $licenses) {
			foreach ($licenses as $license) {
				$end = $license->getValidUntil();
				$key = 'notified_license_' . $license->getId();
				$state = $end === null ? '' : ($end < $today ? 'expired' : ($end <= $warnUntil ? 'expiring' : ''));
				$this->transition($key, $state, 'license', (string)$license->getId(), [
					'expiring' => NotificationService::LICENSE_EXPIRING,
					'expired' => NotificationService::LICENSE_EXPIRED,
				], $describe($versionId) + ['date' => (string)$end]);
			}
		}

		foreach ($this->events->findByKind(Event::CONCERT) as $event) {
			$date = $event->getEventDate();
			if ($event->getImported() === true || $date === null || !FieldValue::isDate($date)) {
				continue;
			}
			// A sung or cancelled concert has no stage: its notification is withdrawn.
			$state = ConcertDeadlines::stage($event, $today);
			$this->transition('notified_event_' . $event->getId(), $state, 'event', (string)$event->getId(), [
				'10' => NotificationService::CONCERT_DEADLINE,
				'8' => NotificationService::CONCERT_DEADLINE,
				'6' => NotificationService::CONCERT_DEADLINE,
				'confirm' => NotificationService::CONCERT_CONFIRM,
			], ['eventId' => $event->getId(), 'title' => (string)$event->getTitle(), 'date' => $date, 'weeks' => is_numeric($state) ? (int)$state : null],
				$state === 'confirm' ? [Roles::CONDUCTOR] : [Roles::CONDUCTOR, Roles::LIBRARIAN]);
		}

		$portalOn = $this->modules->notificationEnabled(NotificationService::PORTAL_REMOVE);
		foreach ($versions as $version) {
			$onPortal = $portalOn && $this->rights->isOnPortal($version);
			$denied = $onPortal && $this->rights->portalDenial($version, $licensesByVersion[$version->getId()] ?? [], $today) !== null;
			$this->transition('notified_portal_' . $version->getId(), $denied ? 'remove' : '', 'version', (string)$version->getId(),
				['remove' => NotificationService::PORTAL_REMOVE], $describe($version->getId()));
		}

		// Resubmission: marked protected, but by the life dates of all persons
		// involved the protection has ended. A task to check; nothing changes
		// automatically.
		$protected = array_keys(array_filter($versions, fn ($v): bool => $v->getRightsStatus() === 'protected'));
		$proposals = $this->modules->notificationEnabled(NotificationService::PROTECTION_ENDED)
			? $this->persons->protectionForVersions($protected, (int)substr($today, 0, 4))
			: [];
		foreach ($versions as $version) {
			$proposal = $proposals[$version->getId()] ?? null;
			$ended = $proposal !== null && $proposal['status'] === ProtectionTerm::FREE;
			$this->transition('notified_protection_' . $version->getId(), $ended ? 'ended' : '', 'version', (string)$version->getId(),
				['ended' => NotificationService::PROTECTION_ENDED], $describe($version->getId()) + ['date' => $ended ? (string)$proposal['freeSince'] : '']);
		}
	}

	/**
	 * Notifies when a case enters a new state and withdraws the notification
	 * of the previous state. A state whose notification is switched off
	 * counts as no state.
	 *
	 * @param array<array-key, string> $subjects state => notification subject
	 * @param array<string, scalar|null> $parameters
	 * @param list<string> $roles recipients
	 */
	private function transition(string $key, string $state, string $objectType, string $objectId, array $subjects, array $parameters,
		array $roles = [Roles::LIBRARIAN]): void {
		if (isset($subjects[$state]) && !$this->modules->notificationEnabled($subjects[$state])) {
			$state = '';
		}
		$previous = $this->appConfig->getValueString(Application::APP_ID, $key, '', true);
		if ($previous === $state) {
			return;
		}
		if (isset($subjects[$previous])) {
			$this->notifications->withdraw($subjects[$previous], $objectType, $objectId);
		}
		if (isset($subjects[$state])) {
			$this->notifications->notifyRoles($roles, $subjects[$state], $objectType, $objectId, $parameters);
		}
		if ($state === '') {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		} else {
			$this->appConfig->setValueString(Application::APP_ID, $key, $state, true);
		}
	}
}
