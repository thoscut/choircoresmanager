<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Policy;

use OCA\ChoirScores\Db\Setlist;
use OCA\ChoirScores\Service\PermissionService;

class SetlistPolicy implements PolicyInterface {
	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly PermissionService $permissionService,
	) {
	}

	public function allows(string $action, ?object $subject = null): bool {
		return match ($action) {
			self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_SHARE => $this->permissionService->canCurrentUserEdit(),
			self::ACTION_READ => $this->canReadSetlist($subject),
			default => false,
		};
	}

	private function canReadSetlist(?object $subject = null): bool {
		if (!($subject instanceof Setlist)) {
			return false;
		}
		// Published setlists are readable by every logged-in user
		if ($subject->getIsPublished()) {
			return $this->permissionService->isCurrentUserLoggedIn();
		}
		// Drafts require edit permission
		return $this->permissionService->canCurrentUserEdit();
	}
}
