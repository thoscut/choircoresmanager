<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Attribute\RequiresModule;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tasks, internal excerpt links and the start page "due" (milestone 0.3,
 * see docs/api.md).
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class TaskController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private TaskService $tasks,
		private LinkService $links,
		private DueService $due,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/due')]
	public function due(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			$due = $this->due->forUser($uid);
			foreach ($due['cards'] as &$card) {
				$card['label'] = $this->l->t($card['label']);
			}
			unset($card);
			foreach ($due['concerts'] as &$concert) {
				$concert['label'] = $this->l->t($concert['label']);
			}
			unset($concert);
			return $due;
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[FrontpageRoute(verb: 'GET', url: '/api/tasks')]
	public function tasks(int $closed = 0): JSONResponse {
		return $this->run(function (string $uid) use ($closed) {
			$this->roles->requireAccess($uid);
			return $this->tasks->all($closed === 1);
		});
	}

	/**
	 * @param array<string, mixed> $task
	 */
	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[FrontpageRoute(verb: 'POST', url: '/api/copies/{id}/tasks', requirements: ['id' => '\d+'])]
	public function createTask(int $id, array $task = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->tasks->create($id, $task, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/answer', requirements: ['id' => '\d+'])]
	public function answer(int $id, string $answer = ''): JSONResponse {
		return $this->run(function (string $uid) use ($id, $answer) {
			$this->tasks->answer($id, $answer, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[RequiresModule('tasks')]
	#[FrontpageRoute(verb: 'POST', url: '/api/tasks/{id}/close', requirements: ['id' => '\d+'])]
	public function close(int $id, string $status = '', ?string $note = null): JSONResponse {
		return $this->run(function (string $uid) use ($id, $status, $note) {
			$this->tasks->close($id, $status, $note, $uid);
			return ['ok' => true];
		});
	}

	/**
	 * @param array<string, mixed> $link
	 */
	#[NoAdminRequired]
	#[RequiresModule('excerpt_links')]
	#[FrontpageRoute(verb: 'POST', url: '/api/copies/{id}/links', requirements: ['id' => '\d+'])]
	public function createLink(int $id, array $link = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->links->create($id, $link, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule('excerpt_links')]
	#[FrontpageRoute(verb: 'GET', url: '/api/links')]
	public function activeLinks(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			return $this->links->active(null, $uid);
		});
	}

	/**
	 * Opens an excerpt link. A token that belongs to no link counts as a
	 * failed attempt for Nextcloud's brute-force protection, which slows down
	 * further attempts from the same address.
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'notenarchiv_link')]
	#[RequiresModule('excerpt_links')]
	#[FrontpageRoute(verb: 'GET', url: '/api/links/{token}', requirements: ['token' => '[A-Za-z0-9]{32}'])]
	public function resolveLink(string $token): JSONResponse {
		// An unknown token ends in UnknownLinkException; run() marks that
		// response for throttling.
		return $this->run(fn (string $uid) => $this->links->resolve($token, $uid));
	}

	#[NoAdminRequired]
	#[RequiresModule('excerpt_links')]
	#[FrontpageRoute(verb: 'POST', url: '/api/links/{id}/revoke', requirements: ['id' => '\d+'])]
	public function revokeLink(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->links->revoke($id, $uid);
			return ['ok' => true];
		});
	}
}
