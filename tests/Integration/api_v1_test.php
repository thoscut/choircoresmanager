<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the API for agents (OCS, api/v1) against a real
 * Nextcloud instance with an imported catalog. The controllers come from
 * Nextcloud's dependency injection container with <uid> as the account of
 * the session, as for a request of that account.
 *
 * - reading: module status, search with filters and pages, a copy, a text
 *   reference, the due list, tasks, concerts, statistics;
 * - writing: a proposal and a task;
 * - forbidden: proposing a decision, answering for another role, internal
 *   preservation copies for roles that may not view them, writing as reader;
 * - switched off: every route answers 404 without `agent_api`, the routes of
 *   a module without it.
 *
 * Usage (as the web server user):
 *   php tests/Integration/api_v1_test.php <nextcloud-root> <uid> <shelfmark> <shelfmark-preservation-copy>
 *
 * <uid> must hold the role "librarian". The test gives the account's groups
 * other roles for a while (reader, contributor) and switches modules. Every
 * database change is rolled back; at the end the module switches and the
 * role groups are exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark, $preservationMark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark', 'shelfmark-preservation-copy']);

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Controller\ApiV1CatalogController;
use OCA\Notenarchiv\Controller\ApiV1ConcertController;
use OCA\Notenarchiv\Controller\ApiV1ProposalController;
use OCA\Notenarchiv\Controller\ApiV1StatisticsController;
use OCA\Notenarchiv\Controller\ApiV1StatusController;
use OCA\Notenarchiv\Controller\ApiV1TaskController;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Middleware\ModuleMiddleware;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\Roles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

$check = IntegrationTest::check(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$roles = Server::get(Roles::class);

/**
 * The stored module switches: key => value, or null when not stored.
 *
 * @return array<string, bool|null>
 */
$switches = function () use ($appConfig): array {
	$values = [];
	foreach (array_keys(Modules::MODULES) as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null)
			? $appConfig->getValueBool(Application::APP_ID, $configKey)
			: null;
	}
	return $values;
};
/** @return array<string, list<string>> role => groups */
$roleGroups = function () use ($roles): array {
	$groups = [];
	foreach (Roles::ALL as $role) {
		$groups[$role] = $roles->groups($role);
	}
	return $groups;
};
$originalSwitches = $switches();
$originalGroups = $roleGroups();

$user = Server::get(IUserManager::class)->get($uid);
$check('test account exists', $user !== null);
Server::get(IUserSession::class)->setUser($user);
$userGroups = $user === null ? [] : Server::get(IGroupManager::class)->getUserGroupIds($user);
/** Gives the account exactly these roles (through its own groups). */
$onlyRoles = function (array $wanted) use ($roles, $userGroups, $originalGroups): void {
	foreach (Roles::ALL as $role) {
		$roles->setGroups($role, in_array($role, $wanted, true)
			? array_values(array_unique([...$originalGroups[$role], ...$userGroups]))
			: array_values(array_diff($originalGroups[$role], $userGroups)));
	}
};

/**
 * The data of a response, or the OCS exception the call ends with.
 *
 * @param callable(): DataResponse $call
 */
$ocs = function (callable $call): mixed {
	try {
		return $call()->getData();
	} catch (OCSException $e) {
		return $e;
	}
};
$fails = fn (mixed $result, string $class, int $status): bool => $result instanceof $class && $result->getCode() === $status;
$describe = fn (mixed $result): string => $result instanceof OCSException ? get_class($result) . ': ' . $result->getMessage()
	: substr((string)json_encode($result), 0, 300);

$copies = Server::get(CopyMapper::class)->findAllByShelfmark();
$copy = $copies[$shelfmark];
$preservation = $copies[$preservationMark];
$check('the second shelf mark is an internal preservation copy',
	Server::get(WorkVersionMapper::class)->find($preservation->getVersionId())->getPreservationCopy() === true);

$db->beginTransaction();
try {
	$modules->applyPreset(Modules::COMPLETE);
	$modules->change([Modules::AGENT_API => true]);
	$onlyRoles([Roles::LIBRARIAN]);

	// The controllers as Nextcloud creates them for a request of $uid
	$statusController = Server::get(ApiV1StatusController::class);
	$catalog = Server::get(ApiV1CatalogController::class);
	$tasks = Server::get(ApiV1TaskController::class);
	$proposals = Server::get(ApiV1ProposalController::class);
	$concerts = Server::get(ApiV1ConcertController::class);
	$statistics = Server::get(ApiV1StatisticsController::class);
	$middleware = Server::get(ModuleMiddleware::class);
	$routes = [
		[$statusController, 'modules'], [$catalog, 'search'], [$catalog, 'copy'], [$catalog, 'reference'], [$tasks, 'due'], [$tasks, 'tasks'],
		[$tasks, 'createTask'], [$tasks, 'answer'], [$proposals, 'proposals'], [$proposals, 'propose'], [$concerts, 'concerts'],
		[$concerts, 'concert'], [$statistics, 'statistics'],
	];
	/** The answer of the module middleware: null when the route may run, else the OCS exception. */
	$gate = function (Controller $controller, string $method) use ($middleware): ?OCSException {
		try {
			$middleware->beforeController($controller, $method);
			return null;
		} catch (ModuleDisabledException $e) {
			try {
				$middleware->afterException($controller, $method, $e);
			} catch (OCSException $ocs) {
				return $ocs;
			}
			return new OCSException('no OCS answer', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	};
	$check('every route of api/v1 runs with the interface on',
		array_filter(array_map(fn (array $route): ?OCSException => $gate(...$route), $routes)) === []);

	// ---- read ----
	$moduleStatus = $ocs(fn () => $statusController->modules());
	$check('module status of the session account', is_array($moduleStatus) && $moduleStatus['api_version'] === 1 && $moduleStatus['roles'] === [Roles::LIBRARIAN]
		&& $moduleStatus['modules'][Modules::AGENT_API] === true && $moduleStatus['permissions']['propose'] === true
		&& $moduleStatus['permissions']['view_preservation_copies'] === true, $describe($moduleStatus));

	$found = $ocs(fn () => $catalog->search(shelfmark: $shelfmark));
	$row = is_array($found) ? ($found['items'][0] ?? null) : null;
	$check('search by shelf mark', is_array($row) && $row['id'] === $copy->getId() && $found['total'] === 1, $describe($found));

	$all = [];
	$cursor = null;
	$pages = 0;
	do {
		$page = $ocs(fn () => $catalog->search(limit: 5, cursor: $cursor));
		if (!is_array($page)) {
			break;
		}
		$all = [...$all, ...array_column($page['items'], 'id')];
		$cursor = $page['next_cursor'];
		$pages++;
	} while ($cursor !== null && $pages < 1000);
	$entries = Server::get(CatalogService::class)->entries();
	$check('pages cover the catalog once', count($all) === count($entries) && count(array_unique($all)) === count($all),
		count($all) . ' of ' . count($entries) . ' in ' . $pages . ' pages');
	$check('the librarian finds the preservation copy', in_array($preservation->getId(), $all, true));
	$check('an invented cursor is refused', $fails($ocs(fn () => $catalog->search(cursor: 'bm9uc2Vuc2U')), OCSBadRequestException::class, 400));
	$check('a limit above 100 is refused', $fails($ocs(fn () => $catalog->search(limit: 500)), OCSBadRequestException::class, 400));
	$filtered = $ocs(fn () => $catalog->search(status: 'active', has_musicxml: true));
	$check('filters combine', is_array($filtered) && $filtered['items'] !== []
		&& array_filter($filtered['items'], fn (array $r): bool => $r['status'] !== 'active' || !$r['has_musicxml']) === [], $describe($filtered));

	$detail = $ocs(fn () => $catalog->copy($copy->getId()));
	$check('one copy with version, work and rights', is_array($detail) && $detail['shelfmark'] === $copy->getShelfmark()
		&& $detail['version_id'] === $copy->getVersionId() && array_key_exists('status', $detail['rights'])
		&& array_key_exists('rights_status', $detail['fields']['version']), $describe($detail));
	$check('no account names in a copy', is_array($detail) && !array_key_exists('confirmed_by', $detail['fields']['version'])
		&& !array_key_exists('confirmed_by_name', $detail['fields']['version']) && !array_key_exists('valid_set_by', $detail['fields']['version']));
	$check('an unknown copy answers 404', $fails($ocs(fn () => $catalog->copy(999999999)), OCSNotFoundException::class, 404));

	$reference = $ocs(fn () => $catalog->reference($copy->getId(), page: 1, measure_from: '1', measure_to: '4', voice: 'Tenor I'));
	$check('text reference', is_array($reference) && str_starts_with($reference['text'], $copy->getShelfmark() . ' · ')
		&& str_contains($reference['text'], 'Tenor I'), $describe($reference));

	$due = $ocs(fn () => $tasks->due());
	$check('due list of the own roles', is_array($due) && is_array($due['cards']) && is_array($due['concerts']), $describe($due));
	$list = $ocs(fn () => $concerts->concerts(limit: 10));
	$check('concerts can be listed', is_array($list) && is_int($list['total']), $describe($list));
	$check('an unknown concert answers 404', $fails($ocs(fn () => $concerts->concert(999999999)), OCSNotFoundException::class, 404));
	$figures = $ocs(fn () => $statistics->statistics());
	$check('statistics', is_array($figures) && $figures['stock']['copies'] === count($entries), $describe($figures));

	// ---- write: proposals and tasks ----
	$proposed = $ocs(fn () => $proposals->propose('version', $copy->getVersionId(), ['key_signature' => 'API test'], 'integration test', 'api_v1_test'));
	$proposalId = is_array($proposed) ? ($proposed[0]['id'] ?? null) : null;
	$check('a proposal is created', is_int($proposalId) && $proposed[0]['status'] === 'open' && $proposed[0]['value'] === 'API test', $describe($proposed));
	$own = $ocs(fn () => $proposals->proposals(status: 'open'));
	$check('the own proposals list it', is_array($own) && in_array($proposalId, array_column($own['items'], 'id'), true), $describe($own));
	$check('the catalog is unchanged', Server::get(WorkVersionMapper::class)->find($copy->getVersionId())->getKeySignature() !== 'API test');
	$check('a decision cannot be proposed',
		$fails($ocs(fn () => $proposals->propose('version', $copy->getVersionId(), ['confirmed_at' => '2026-01-01'])), OCSBadRequestException::class, 400));
	$check('a proposal for nothing answers 404', $fails($ocs(fn () => $proposals->propose('version', 999999999, ['scoring' => 'x'])), OCSNotFoundException::class, 404));

	$created = $ocs(fn () => $tasks->createTask($copy->getId(), 'question', 'conductor', 'API test: which edition?', measure_from: '1', measure_to: '4'));
	$taskId = is_array($created) ? $created['id'] : null;
	$check('a task is created', is_int($taskId) && $created['status'] === 'open' && $created['own'] === true && $created['shelfmark'] === $copy->getShelfmark(),
		$describe($created));
	$open = $ocs(fn () => $tasks->tasks('open', copy_id: $copy->getId()));
	$check('the open tasks list it', is_array($open) && in_array($taskId, array_column($open['items'], 'id'), true), $describe($open));
	$check('only the conductor answers it', $fails($ocs(fn () => $tasks->answer((int)$taskId, 'yes')), OCSForbiddenException::class, 403));
	$check('a task for an unknown role is refused',
		$fails($ocs(fn () => $tasks->createTask($copy->getId(), 'question', 'board', 'x')), OCSBadRequestException::class, 400));

	// ---- other roles ----
	$onlyRoles([Roles::READER]);
	$readerStatus = $ocs(fn () => Server::get(ApiV1StatusController::class)->modules());
	$check('a reader may not write', is_array($readerStatus) && $readerStatus['permissions']['propose'] === false
		&& $readerStatus['permissions']['create_tasks'] === false && $readerStatus['permissions']['view_preservation_copies'] === false,
		$describe($readerStatus));
	$hidden = $ocs(fn () => $catalog->search(shelfmark: $preservationMark));
	$check('a reader does not find the preservation copy', is_array($hidden) && $hidden['items'] === [], $describe($hidden));
	$check('a reader cannot open it', $fails($ocs(fn () => $catalog->copy($preservation->getId())), OCSForbiddenException::class, 403));
	$check('nor get a text reference to it', $fails($ocs(fn () => $catalog->reference($preservation->getId())), OCSForbiddenException::class, 403));
	$check('a reader cannot propose', $fails($ocs(fn () => $proposals->propose('version', $copy->getVersionId(), ['scoring' => 'x'])),
		OCSForbiddenException::class, 403));
	$check('a reader cannot create tasks', $fails($ocs(fn () => $tasks->createTask($copy->getId(), 'question', 'conductor', 'x')),
		OCSForbiddenException::class, 403));

	$onlyRoles([Roles::CONTRIBUTOR]);
	$check('a contributor proposes', is_array($ocs(fn () => $proposals->propose('copy', $copy->getId(), ['pages' => '4'], null, 'api_v1_test'))));
	$check('a contributor asks', is_array($ocs(fn () => $tasks->createTask($copy->getId(), 'report', 'librarian', 'API test: page 2 is missing'))));
	$check('a contributor cannot create a task on the preservation copy',
		$fails($ocs(fn () => $tasks->createTask($preservation->getId(), 'question', 'conductor', 'x')), OCSForbiddenException::class, 403));
	$onlyRoles([Roles::LIBRARIAN]);

	// ---- modules switched off ----
	$modules->change(['tasks' => false]);
	$off = $gate($tasks, 'tasks');
	$check('without tasks the task routes answer 404', $off instanceof OCSNotFoundException && $off->getCode() === 404
		&& $off->getMessage() === 'This function is switched off.', $describe($off));
	$check('the catalog still answers', $gate($catalog, 'search') === null && $gate($tasks, 'due') === null);
	$modules->change(['tasks' => true]);

	$modules->change([Modules::AGENT_API => false]);
	$refused = array_map(fn (array $route): ?OCSException => $gate(...$route), $routes);
	$check('without agent_api every route answers 404',
		array_filter($refused, fn (?OCSException $e): bool => !($e instanceof OCSNotFoundException && $e->getCode() === 404)) === []);
	$check('switching the interface off keeps the other modules', $modules->isEnabled('tasks') && $modules->isEnabled('concerts'));
	$modules->change([Modules::AGENT_API => true]);
	$check('switched on again it answers', $gate($statusController, 'modules') === null);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	Server::get(IUserSession::class)->setUser(null);
	// Exactly the switches and role groups of before, also when the
	// rollback did not cover them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($originalSwitches as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	foreach ($originalGroups as $role => $groups) {
		if ($roles->groups($role) !== $groups) {
			$roles->setGroups($role, $groups);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $originalSwitches);
	$check('role groups restored exactly', $roleGroups() === $originalGroups);
}

IntegrationTest::finish();
