<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Policy;

use OCA\OrchestraScoresManager\Db\Enum\PublicationStatus;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Service\PermissionService;

class ScorePolicy implements PolicyInterface {
	/**
	 * Read the content of the MusicXML file of a score (subject: the Score).
	 *
	 * Requires a logged-in user; internal preservation copies may only be read by editors.
	 */
	public const ACTION_READ_MUSICXML = 'READ_MUSICXML';

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly PermissionService $permissionService,
	) {
	}

	public function allows(string $action, ?object $subject = null): bool {
		// For now all write-ish actions are based on canCurrentUserEdit
		return match ($action) {
			self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE, self::ACTION_SHARE => $this->permissionService->canCurrentUserEdit(),
			// read is allowed for every logged-in user
			self::ACTION_READ => $this->permissionService->isCurrentUserLoggedIn(),
			self::ACTION_READ_MUSICXML => $subject instanceof Score
				&& $this->permissionService->isCurrentUserLoggedIn()
				&& ($subject->getPublicationStatus() !== PublicationStatus::INTERNAL_PRESERVATION_COPY->value
					|| $this->permissionService->canCurrentUserEdit()),
			// default deny
			default => false,
		};
	}
}
