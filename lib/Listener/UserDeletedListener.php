<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Listener;

use OCA\Cantoria\Service\PrivacyService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * A deleted Nextcloud account no longer stays in the folder book and the
 * audit log (milestone 0.11, PrivacyService::forgetAccount).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private PrivacyService $privacy,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$user = $event->getUser();
		$this->privacy->forgetAccount($user->getUID(), $user->getDisplayName());
	}
}
