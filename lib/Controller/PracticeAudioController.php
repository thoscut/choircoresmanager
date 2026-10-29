<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Attribute\RequiresModule;
use OCA\Cantoria\Service\PracticeAudioService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Practice audio files from the score view (see docs/api.md): the browser
 * renders the files, the server allows each export and records it.
 *
 * @psalm-suppress UnusedClass
 */
#[RequiresModule('practice_audio')]
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PracticeAudioController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private PracticeAudioService $practiceAudio,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Allows one export of practice audio and records it in the audit log;
	 * 403 with the reason when the rights policy refuses it.
	 *
	 * @param array<string, mixed> $export measures, voice, mode, format, … (see docs/api.md)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/practice-audio', requirements: ['id' => '\d+'])]
	public function authorize(int $id, array $export = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->practiceAudio->authorize($id, $export, $uid));
	}
}
