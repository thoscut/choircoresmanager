<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Sends Nextcloud notifications to role holders.
 *
 * Rules: notifications go to the members of the groups mapped to a role, never
 * to singers; they carry shelf marks and titles but no personal data; each
 * subject and object is notified once and withdrawn when the task is done.
 * Without the notifications app this is a no-op; the start page shows the
 * same tasks. A subject whose switch (module `notify_<subject>`) is not
 * active is not sent; withdrawing always works.
 */
class NotificationService {
	public const PROPOSALS_WAITING = 'proposals_waiting';
	public const PORTAL_REMOVE = 'portal_remove';
	public const LICENSE_EXPIRING = 'license_expiring';
	public const LICENSE_EXPIRED = 'license_expired';
	public const EXPORT_FAILED = 'export_failed';
	public const TASK_NEW = 'task_new';
	public const TASK_ANSWERED = 'task_answered';
	public const CONCERT_DEADLINE = 'concert_deadline';
	public const CONCERT_CONFIRM = 'concert_confirm';
	/** Milestone 0.5: a protected version whose protection term has ended by the proposal (task: check, nothing changes automatically) */
	public const PROTECTION_ENDED = 'protection_ended';
	/** Milestone 0.5: the content of an archive file changed without a visible modification (silent corruption) */
	public const CHECKSUM_MISMATCH = 'checksum_mismatch';

	public function __construct(
		private IManager $manager,
		private IGroupManager $groupManager,
		private Roles $roles,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private Modules $modules,
	) {
	}

	/**
	 * One notification for the whole inbox, replaced whenever the number of
	 * open proposals changes; withdrawn when the inbox is empty.
	 */
	public function proposalsWaiting(int $count): void {
		$this->withdraw(self::PROPOSALS_WAITING, 'inbox', 'inbox');
		if ($count > 0) {
			$this->notifyRole(Roles::LIBRARIAN, self::PROPOSALS_WAITING, 'inbox', 'inbox', ['count' => $count]);
		}
	}

	/**
	 * @param array<string, scalar|null> $parameters
	 */
	public function notifyRole(string $role, string $subject, string $objectType, string $objectId, array $parameters = [], bool $includeAdmins = false): void {
		$this->notifyRoles([$role], $subject, $objectType, $objectId, $parameters, $includeAdmins);
	}

	/**
	 * Notifies the members of several roles; a person holding more than one of
	 * them is notified once.
	 *
	 * @param list<string> $roles
	 * @param array<string, scalar|null> $parameters
	 */
	public function notifyRoles(array $roles, string $subject, string $objectType, string $objectId, array $parameters = [], bool $includeAdmins = false): void {
		if (!$this->modules->notificationEnabled($subject)) {
			return;
		}
		// A list, not keys: numeric account names would become integer keys.
		$recipients = [];
		foreach ($roles as $role) {
			foreach ($this->roles->groups($role) as $groupId) {
				foreach ($this->groupManager->get($groupId)?->getUsers() ?? [] as $user) {
					$recipients[] = $user->getUID();
				}
			}
		}
		if ($includeAdmins) {
			foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $user) {
				$recipients[] = $user->getUID();
			}
		}
		foreach (array_values(array_unique($recipients)) as $uid) {
			try {
				$notification = $this->base($subject, $objectType, $objectId)
					->setUser($uid)
					->setDateTime($this->time->getDateTime())
					->setSubject($subject, $parameters);
				$this->manager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning('Notenarchiv: notification failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}

	/**
	 * Withdraws a notification from all users, e.g. when the task is done.
	 */
	public function withdraw(string $subject, string $objectType, string $objectId): void {
		try {
			$this->manager->markProcessed($this->base($subject, $objectType, $objectId));
		} catch (\Throwable $e) {
			$this->logger->warning('Notenarchiv: withdrawing notification failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function base(string $subject, string $objectType, string $objectId): INotification {
		return $this->manager->createNotification()
			->setApp(Application::APP_ID)
			->setObject($objectType, $objectId)
			->setSubject($subject);
	}
}
