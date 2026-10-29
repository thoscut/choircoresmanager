<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Db\VersionState;
use OCA\Cantoria\ResponseDefinitions;
use OCA\Cantoria\Service\ApiV1\Values;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\StateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Revisions of a version (API v1, see docs/api-v1.md): read only. Setting
 * the current revision stays with the catalog librarian in the app
 * (guideline 1). Internal preservation copies are left out for roles that
 * may not view them, as in the other routes.
 *
 * @psalm-import-type CantoriaRevisions from ResponseDefinitions
 * @psalm-import-type CantoriaRevision from ResponseDefinitions
 * @psalm-import-type CantoriaRevisionChange from ResponseDefinitions
 * @psalm-suppress UnusedClass
 */
#[RequiresModule(Modules::AGENT_API)]
#[RequiresModule(Modules::VERSION_STATES)]
class ApiV1StateController extends OCSController {
	use RunsOcsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private StateService $states,
		private CatalogService $catalog,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Revisions of the version of a copy
	 *
	 * Every revision of the MusicXML of the version the copy belongs to,
	 * oldest first: its number, where it came from ("first" when the
	 * recording started, "correction" in the app, "sync" from the file sync),
	 * the file and its checksum, the measures and voices changed, a note and
	 * the changes of a correction. "current" marks the revision the catalog
	 * librarian has set as current, with the day since when. Comparing two
	 * revisions is done on the two files.
	 *
	 * @param int $id ID of the copy
	 * @return DataResponse<Http::STATUS_OK, CantoriaRevisions, array{}>
	 * @throws OCSForbiddenException The account has no role, or the copy is an internal preservation copy it may not view
	 * @throws OCSNotFoundException The copy does not exist, or the revisions or the interface for agents are switched off
	 *
	 * 200: Revisions returned
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 600)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/copies/{id}/states', requirements: ['id' => '\d+'])]
	public function states(int $id): DataResponse {
		return new DataResponse($this->ocs(function (string $uid) use ($id): array {
			['copy' => $copy, 'version' => $version] = $this->catalog->copyWithContext($id);
			$list = $this->states->forVersion($version->getId(), $uid);
			$current = StateService::current($list['states']);
			return [
				'copy_id' => $copy->getId(),
				'shelfmark' => $copy->getShelfmark(),
				'version_id' => $version->getId(),
				'current_number' => $current?->getNumber(),
				'items' => array_map(fn (VersionState $state): array => self::revision($state, $uid), $list['states']),
			];
		}));
	}

	/**
	 * A revision without account names; `own` tells whether the calling
	 * account recorded it.
	 *
	 * @return CantoriaRevision
	 */
	private static function revision(VersionState $state, string $uid): array {
		return [
			'id' => $state->getId(),
			'number' => $state->getNumber(),
			'source' => $state->getSource(),
			'copy_id' => $state->getCopyId(),
			'file_id' => $state->getFileId(),
			'file_name' => $state->getFileName(),
			'sha256' => $state->getSha256(),
			'measures' => $state->getMeasures(),
			'voices' => $state->getVoices(),
			'note' => $state->getNote(),
			'changes' => self::changes($state->getChanges()),
			'current' => $state->getValidSince() !== null,
			'valid_since' => $state->getValidSince(),
			'created_at' => Values::time($state->getCreated()) ?? '',
			'own' => $state->getCreatedBy() === $uid,
		];
	}

	/**
	 * @return list<CantoriaRevisionChange>
	 */
	private static function changes(?string $json): array {
		if ($json === null || $json === '') {
			return [];
		}
		try {
			$decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$result = [];
		foreach ($decoded as $change) {
			if (!is_array($change)) {
				continue;
			}
			$result[] = [
				'measure' => Values::string($change['measure'] ?? ''),
				'part' => Values::string($change['part'] ?? ''),
				'kind' => Values::string($change['kind'] ?? ''),
				'verse' => Values::stringOrNull($change['verse'] ?? null),
				'old' => Values::string($change['old'] ?? ''),
				'new' => Values::string($change['new'] ?? ''),
			];
		}
		return $result;
	}
}
