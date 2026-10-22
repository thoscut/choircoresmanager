<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Service\MetaService;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private MetaService $meta,
		private IInitialState $initialState,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		if ($this->userId === null || !$this->roles->hasAccess($this->userId)) {
			$response = new TemplateResponse('core', '403', ['message' => $this->l->t('You have no access to the music archive.')], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}
		$this->initialState->provideInitialState('meta', $this->meta->forUser($this->userId));
		$response = new TemplateResponse(Application::APP_ID, 'index');
		// The score viewer compiles the bundled notation engine (WebAssembly,
		// no external source). This needs 'wasm-unsafe-eval' only; JavaScript
		// eval stays forbidden and no other source is allowed.
		$policy = new ContentSecurityPolicy();
		$policy->allowEvalWasm(true);
		// The scan beside the score: a PDF from the app's own route, shown by
		// the browser's PDF viewer in a frame. Nothing from other origins.
		$policy->addAllowedFrameDomain('\'self\'');
		$response->setContentSecurityPolicy($policy);
		return $response;
	}
}
