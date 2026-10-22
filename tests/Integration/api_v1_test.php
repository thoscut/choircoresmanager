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
 *   reference, the due list, tasks, concerts, statistics, the review of a
 *   copy and the correction orders;
 * - writing: a proposal, a task and the report of a transcription (the test
 *   writes its reports itself, so it also runs on a catalog without
 *   transcription reports);
 * - forbidden: proposing a decision, answering for another role, internal
 *   preservation copies for roles that may not view them, writing as reader,
 *   deciding a review point as contributor (the API has no such route; the
 *   route of the UI refuses it);
 * - switched off: every route answers 404 without `agent_api`, the routes of
 *   a module without it.
 *
 * Usage (as the web server user):
 *   php tests/Integration/api_v1_test.php <nextcloud-root> <uid> <shelfmark> <shelfmark-preservation-copy>
 *
 * <uid> must hold the role "librarian". The second copy is made an internal
 * preservation copy inside the transaction if it is not one already, so any
 * two copies of different versions do (e.g. 1-1 3-1 of the demo data). The
 * test gives the account's groups other roles for a while (reader,
 * contributor) and switches modules. Every database change is rolled back;
 * at the end the module switches and the role groups are exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark, $preservationMark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark', 'shelfmark-preservation-copy']);

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Controller\ApiV1CatalogController;
use OCA\Notenarchiv\Controller\ApiV1ConcertController;
use OCA\Notenarchiv\Controller\ApiV1ProposalController;
use OCA\Notenarchiv\Controller\ApiV1ReviewController;
use OCA\Notenarchiv\Controller\ApiV1StatisticsController;
use OCA\Notenarchiv\Controller\ApiV1StatusController;
use OCA\Notenarchiv\Controller\ApiV1TaskController;
use OCA\Notenarchiv\Controller\ReviewController;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ReviewPoint;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Middleware\ModuleMiddleware;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\ReviewService;
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
$copy = $copies[$shelfmark] ?? null;
$preservation = $copies[$preservationMark] ?? null;
$check('both copies exist', $copy !== null && $preservation !== null, $shelfmark . ', ' . $preservationMark);
if ($copy === null || $preservation === null) {
	IntegrationTest::finish();
}
$check('the two copies belong to different versions', $copy->getVersionId() !== $preservation->getVersionId());
$versions = Server::get(WorkVersionMapper::class);

/**
 * A minimal report of a transcription (bericht/1) for a copy, with review
 * points whose texts are unique to this run.
 *
 * @param list<array{takt: string, stimme: string, was: string}> $uncertain
 * @param list<string> $material
 */
$reportText = fn (string $shelfmarkOfReport, array $uncertain, array $material = []): string => json_encode([
	'format' => 'bericht/1',
	'signatur' => $shelfmarkOfReport,
	'basisname' => 'api-v1-test',
	'titel' => 'API v1 test',
	'runde' => 'api_v1_test',
	'weg' => 'neuaufbau',
	'unsicher' => $uncertain,
	'material' => $material,
	'rueckfragen' => [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$run = bin2hex(random_bytes(4));

$db->beginTransaction();
try {
	$modules->applyPreset(Modules::COMPLETE);
	$modules->change([Modules::AGENT_API => true]);
	$onlyRoles([Roles::LIBRARIAN]);

	// Precondition, so that the test also runs on a catalog without a preservation copy
	if ($versions->find($preservation->getVersionId())->getPreservationCopy() !== true) {
		Server::get(CatalogService::class)->update('version', $preservation->getVersionId(), ['preservation_copy' => true], $uid, 'integration test');
	}
	$check('the second shelf mark is an internal preservation copy', $versions->find($preservation->getVersionId())->getPreservationCopy() === true);
	$check('the first one is not', $versions->find($copy->getVersionId())->getPreservationCopy() !== true);

	// The controllers as Nextcloud creates them for a request of $uid
	$statusController = Server::get(ApiV1StatusController::class);
	$catalog = Server::get(ApiV1CatalogController::class);
	$tasks = Server::get(ApiV1TaskController::class);
	$proposals = Server::get(ApiV1ProposalController::class);
	$concerts = Server::get(ApiV1ConcertController::class);
	$statistics = Server::get(ApiV1StatisticsController::class);
	$reviewApi = Server::get(ApiV1ReviewController::class);
	$middleware = Server::get(ModuleMiddleware::class);
	$routes = [
		[$statusController, 'modules'], [$catalog, 'search'], [$catalog, 'copy'], [$catalog, 'reference'], [$tasks, 'due'], [$tasks, 'tasks'],
		[$tasks, 'createTask'], [$tasks, 'answer'], [$proposals, 'proposals'], [$proposals, 'propose'], [$concerts, 'concerts'],
		[$concerts, 'concert'], [$statistics, 'statistics'], [$reviewApi, 'review'], [$reviewApi, 'orders'], [$reviewApi, 'submitReport'],
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
		&& $moduleStatus['permissions']['view_preservation_copies'] === true && $moduleStatus['permissions']['submit_transcription_reports'] === true,
		$describe($moduleStatus));

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

	// ---- review of transcriptions: reports, the review of a copy, correction orders ----
	$reviewService = Server::get(ReviewService::class);
	/**
	 * The response of a call, or the OCS exception it ends with.
	 *
	 * @param callable(): DataResponse $call
	 */
	$respond = function (callable $call): DataResponse|OCSException {
		try {
			return $call();
		} catch (OCSException $e) {
			return $e;
		}
	};
	/** Every review point of a copy, page by page (two per page, to page through) */
	$allPoints = function (int $copyId, ?string $status = null) use ($reviewApi, $ocs): ?array {
		$items = [];
		$cursor = null;
		$pages = 0;
		do {
			$page = $ocs(fn () => $reviewApi->review($copyId, status: $status, limit: 2, cursor: $cursor));
			if (!is_array($page)) {
				return null;
			}
			$items = [...$items, ...$page['items']];
			$cursor = $page['next_cursor'];
			$pages++;
		} while ($cursor !== null && $pages < 2000);
		return $items;
	};
	$text = fn (string $what): string => 'API test ' . $run . ': ' . $what;
	$raw = $reportText($copy->getShelfmark(), [
		['takt' => '1', 'stimme' => 'Tenor', 'was' => $text('first note uncertain')],
		['takt' => '2–3', 'stimme' => 'Bass', 'was' => $text('tie or slur')],
	], [$text('page 2 of the scan is missing')]);
	$submitted = $respond(fn () => $reviewApi->submitReport($copy->getId(), $raw));
	$result = $submitted instanceof DataResponse ? $submitted->getData() : null;
	$reportId = $result['report']['id'] ?? null;
	$check('a report is read (201)', $submitted instanceof DataResponse && $submitted->getStatus() === Http::STATUS_CREATED
		&& $result['unchanged'] === false && $result['points']['created'] === 3 && $result['report']['own'] === true
		&& $result['report']['copy_id'] === $copy->getId() && $result['report']['round'] === 'api_v1_test', $describe($result ?? $submitted));
	$check('the answer has no received text and no account name', is_array($result) && !array_key_exists('raw', $result['report'])
		&& !array_key_exists('created_by', $result['report']));
	$again = $respond(fn () => $reviewApi->submitReport($copy->getId(), $raw));
	$check('the same report again creates nothing (200)', $again instanceof DataResponse && $again->getStatus() === Http::STATUS_OK
		&& $again->getData()['unchanged'] === true && $again->getData()['report']['id'] === $reportId, $describe($again));
	$check('a report naming another copy is refused', $fails($ocs(fn () => $reviewApi->submitReport($copy->getId(),
		$reportText($preservation->getShelfmark(), []))), OCSBadRequestException::class, 400));
	$check('an invalid report is refused', $fails($ocs(fn () => $reviewApi->submitReport($copy->getId(), '{"format":"bericht/2"}')),
		OCSBadRequestException::class, 400));
	$check('a report for an unknown copy answers 404', $fails($ocs(fn () => $reviewApi->submitReport(999999999, $raw)), OCSNotFoundException::class, 404));

	$reviewData = $ocs(fn () => $reviewApi->review($copy->getId()));
	$check('the review names the copy and lists the new report first', is_array($reviewData) && $reviewData['copy_id'] === $copy->getId()
		&& $reviewData['shelfmark'] === $copy->getShelfmark() && ($reviewData['reports'][0]['id'] ?? null) === $reportId
		&& $reviewData['reports'][0]['superseded_by'] === null && $reviewData['counts']['open'] >= 3, $describe($reviewData));
	$points = $allPoints($copy->getId()) ?? [];
	$mine = array_column(array_values(array_filter($points, fn (array $p): bool => $p['report_id'] === $reportId)), null, 'text');
	$uncertain = $mine[$text('first note uncertain')] ?? [];
	$material = $mine[$text('page 2 of the scan is missing')] ?? [];
	$check('pages cover the review points once', count($points) === count(array_unique(array_column($points, 'id')))
		&& is_array($reviewData) && count($points) === $reviewData['total'], count($points) . ' points');
	$check('the points of the report with place, role and status', count($mine) === 3 && ($uncertain['category'] ?? '') === 'uncertain'
		&& ($uncertain['role'] ?? '') === 'conductor' && ($uncertain['measure_from'] ?? '') === '1' && ($uncertain['voice'] ?? '') === 'Tenor'
		&& ($uncertain['status'] ?? '') === 'open' && ($uncertain['own'] ?? false) === true && ($material['role'] ?? '') === 'librarian',
		$describe(array_values($mine)));
	$json = (string)json_encode($reviewData);
	$check('no account names in the review', !str_contains($json, '"decided_by') && !str_contains($json, '"created_by')
		&& !str_contains($json, '"point_key"') && !str_contains($json, '"raw"'));
	$check('an unknown status is refused', $fails($ocs(fn () => $reviewApi->review($copy->getId(), status: 'deleted')), OCSBadRequestException::class, 400));

	// People decide (here through the service, as the score view does); the order reaches the tool chain
	$reviewService->decide((int)($uncertain['id'] ?? 0), ReviewPoint::CORRECT, $text('read again from the scan'), null, null, $uid);
	$orders = $ocs(fn () => $reviewApi->orders(copy_id: $copy->getId(), limit: 100));
	$order = is_array($orders) ? (array_column($orders['items'], null, 'id')[$uncertain['id'] ?? 0] ?? null) : null;
	$check('the decided point is a correction order', is_array($order) && $order['correction'] === $text('read again from the scan')
		&& $order['shelfmark'] === $copy->getShelfmark() && $order['basename'] === 'api-v1-test' && $order['own'] === true
		&& $order['decided_at'] !== null && !array_key_exists('decided_by_name', $order), $describe($orders));
	$decided = array_column($allPoints($copy->getId(), ReviewPoint::CORRECT) ?? [], null, 'id')[$uncertain['id'] ?? 0] ?? [];
	$check('the review shows the decision without the decider', ($decided['status'] ?? '') === 'correct' && ($decided['decided_at'] ?? null) !== null
		&& !array_key_exists('decided_by', $decided), $describe($decided));
	$firstOrders = $ocs(fn () => $reviewApi->orders(limit: 1));
	$check('the orders come in pages', is_array($firstOrders) && $firstOrders['total'] >= 1 && count($firstOrders['items']) === 1
		&& ($firstOrders['total'] === 1) === ($firstOrders['next_cursor'] === null), $describe($firstOrders));

	// An order on the preservation copy, for the checks of the other roles
	$preserved = $respond(fn () => $reviewApi->submitReport($preservation->getId(),
		$reportText($preservation->getShelfmark(), [['takt' => '1', 'stimme' => 'Alt', 'was' => $text('preservation copy, first note')]])));
	$check('the librarian hands in a report for the preservation copy', $preserved instanceof DataResponse
		&& $preserved->getStatus() === Http::STATUS_CREATED, $describe($preserved));
	$preservedPoint = array_values(array_filter($allPoints($preservation->getId()) ?? [],
		fn (array $p): bool => $p['text'] === $text('preservation copy, first note')))[0] ?? [];
	$reviewService->decide((int)($preservedPoint['id'] ?? 0), ReviewPoint::CORRECT, $text('preservation copy, read again'), null, null, $uid);
	$preservedOrders = $ocs(fn () => $reviewApi->orders(copy_id: $preservation->getId()));
	$check('the librarian sees the order of the preservation copy', is_array($preservedOrders)
		&& in_array($preservedPoint['id'] ?? 0, array_column($preservedOrders['items'], 'id'), true), $describe($preservedOrders));

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
	$check('a reader reads the review', is_array($ocs(fn () => $reviewApi->review($copy->getId()))));
	$check('a reader may not hand in reports', is_array($readerStatus) && $readerStatus['permissions']['submit_transcription_reports'] === false
		&& $fails($ocs(fn () => $reviewApi->submitReport($copy->getId(), $reportText($copy->getShelfmark(),
			[['takt' => '1', 'stimme' => 'Sopran', 'was' => $text('from a reader')]]))), OCSForbiddenException::class, 403));
	$check('a reader cannot read the review of the preservation copy',
		$fails($ocs(fn () => $reviewApi->review($preservation->getId())), OCSForbiddenException::class, 403));
	$hiddenOrders = $ocs(fn () => $reviewApi->orders(copy_id: $preservation->getId()));
	$check('nor its correction orders', is_array($hiddenOrders) && $hiddenOrders['items'] === [] && $hiddenOrders['total'] === 0, $describe($hiddenOrders));

	$onlyRoles([Roles::CONTRIBUTOR]);
	$check('a contributor proposes', is_array($ocs(fn () => $proposals->propose('copy', $copy->getId(), ['pages' => '4'], null, 'api_v1_test'))));
	$check('a contributor asks', is_array($ocs(fn () => $tasks->createTask($copy->getId(), 'report', 'librarian', 'API test: page 2 is missing'))));
	$check('a contributor cannot create a task on the preservation copy',
		$fails($ocs(fn () => $tasks->createTask($preservation->getId(), 'question', 'conductor', 'x')), OCSForbiddenException::class, 403));
	$contributed = $respond(fn () => $reviewApi->submitReport($copy->getId(), $reportText($copy->getShelfmark(),
		[['takt' => '4', 'stimme' => 'Sopran', 'was' => $text('from the contributor')]])));
	$check('a contributor hands in a report', $contributed instanceof DataResponse && $contributed->getStatus() === Http::STATUS_CREATED
		&& $contributed->getData()['points']['created'] === 1, $describe($contributed));
	$check('but not for the preservation copy', $fails($ocs(fn () => $reviewApi->submitReport($preservation->getId(),
		$reportText($preservation->getShelfmark(), []))), OCSForbiddenException::class, 403));
	$contributorOrders = $ocs(fn () => $reviewApi->orders(copy_id: $preservation->getId()));
	$ownOrders = $ocs(fn () => $reviewApi->orders(copy_id: $copy->getId()));
	$check('a contributor reads the orders without those of the preservation copy', is_array($contributorOrders) && $contributorOrders['total'] === 0
		&& is_array($ownOrders) && in_array($uncertain['id'] ?? 0, array_column($ownOrders['items'], 'id'), true),
		$describe([$contributorOrders, $ownOrders]));
	// api/v1 has no route to decide a review point or to save a correction; the routes of the UI refuse a contributor
	$check('api/v1 has no way to decide or correct', !method_exists($reviewApi, 'decide') && !method_exists($reviewApi, 'correct'));
	$internal = Server::get(ReviewController::class);
	$refusedDecision = $internal->decide((int)($material['id'] ?? 0), ReviewPoint::CONFIRMED);
	$check('a contributor cannot decide a review point through the route of the UI', $refusedDecision->getStatus() === Http::STATUS_FORBIDDEN,
		(string)json_encode($refusedDecision->getData()));
	$check('the point stays open', (array_column($allPoints($copy->getId(), ReviewPoint::OPEN) ?? [], null, 'id')[$material['id'] ?? 0]['status'] ?? '')
		=== ReviewPoint::OPEN);
	$refusedCorrection = $internal->correct($copy->getVersionId(), 0, '<score-partwise/>', [], 'agent');
	$check('nor save a correction', $refusedCorrection->getStatus() === Http::STATUS_FORBIDDEN, (string)json_encode($refusedCorrection->getData()));
	$onlyRoles([Roles::LIBRARIAN]);

	// ---- modules switched off ----
	$modules->change(['tasks' => false]);
	$off = $gate($tasks, 'tasks');
	// The message is translated into the account's language
	$l10n = Server::get(\OCP\L10N\IFactory::class)->get('notenarchiv');
	$check('without tasks the task routes answer 404', $off instanceof OCSNotFoundException && $off->getCode() === 404
		&& in_array($off->getMessage(), ['This function is switched off.', $l10n->t('This function is switched off.')], true), $describe($off));
	$check('the catalog still answers', $gate($catalog, 'search') === null && $gate($tasks, 'due') === null);
	$modules->change(['tasks' => true]);
	$modules->change([ReviewService::MODULE => false]);
	$offReview = array_map(fn (string $method): ?OCSException => $gate($reviewApi, $method), ['review', 'orders', 'submitReport']);
	$check('without the review of transcriptions its routes answer 404',
		array_filter($offReview, fn (?OCSException $e): bool => !($e instanceof OCSNotFoundException && $e->getCode() === 404)) === []);
	$check('and the rest still answers', $gate($catalog, 'copy') === null && $gate($tasks, 'tasks') === null);
	$modules->change([ReviewService::MODULE => true]);

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
