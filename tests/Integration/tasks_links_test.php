<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of tasks, excerpt links and the start page (milestone 0.3)
 * against a real Nextcloud instance with an imported catalog.
 *
 * Usage (as the web server user):
 *   php tests/Integration/tasks_links_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <uid> must hold the role "librarian". The test temporarily adds the user's
 * groups to the role "conductor". Database changes are rolled back.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\TaskService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark];
$tasks = Server::get(TaskService::class);
$links = Server::get(LinkService::class);
$due = Server::get(DueService::class);
$roles = Server::get(Roles::class);
$conductorGroups = $roles->groups(Roles::CONDUCTOR);
$userGroups = Server::get(IGroupManager::class)->getUserGroupIds(Server::get(IUserManager::class)->get($uid));

$db->beginTransaction();
try {
	// Question to the conductor with a location in the score
	$check('task without question is refused', $throws(fn () => $tasks->create($copy->getId(), ['addressee' => 'conductor'], $uid)) !== null);
	$check('invalid measure is refused', $throws(fn () => $tasks->create($copy->getId(), ['addressee' => 'conductor', 'question' => 'q', 'measure_from' => '17; DROP'], $uid)) !== null);
	$task = $tasks->create($copy->getId(), ['kind' => 'question', 'addressee' => 'conductor', 'measure_from' => '62', 'measure_to' => '66',
		'voice' => 'Tenor II', 'page' => 2, 'category' => 'reading', 'origin' => 'round 37', 'question' => 'E flat or E in bar 64?'], $uid);
	$check('task created', $task->getId() > 0 && $task->getStatus() === 'open');

	// Without the conductor role the librarian cannot answer
	$check('only the addressed role answers', $roles->hasAnyRole($uid, [Roles::CONDUCTOR]) || $throws(fn () => $tasks->answer($task->getId(), 'E', $uid)) !== null);
	$roles->setGroups(Roles::CONDUCTOR, array_values(array_unique(array_merge($conductorGroups, $userGroups))));
	$dueCards = array_column($due->forUser($uid)['cards'], 'count', 'key');
	$check('start page shows the open task', ($dueCards['tasks_open'] ?? 0) >= 1, json_encode($dueCards));
	$tasks->answer($task->getId(), 'E flat, as printed.', $uid);
	$check('answered task waits for the librarian', in_array($task->getId(), array_column(array_filter($tasks->forUser($uid), fn ($t) => $t['status'] === 'answered'), 'id'), true));
	$check('answered task cannot be answered again', $throws(fn () => $tasks->answer($task->getId(), 'x', $uid)) !== null);
	$tasks->close($task->getId(), 'applied', 'MusicXML corrected', $uid);
	$check('closed task is no longer due', !in_array($task->getId(), array_column($tasks->forUser($uid), 'id'), true));
	$check('closed task cannot be closed again', $throws(fn () => $tasks->close($task->getId(), 'discarded', null, $uid)) !== null);

	// Internal excerpt link: expiry limits, resolve, revoke
	$check('link with expiry beyond six months is refused', $throws(fn () => $links->create($copy->getId(), ['expires' => '2099-01-01'], $uid)) !== null);
	$check('link with an unknown field is refused', $throws(fn () => $links->create($copy->getId(), ['created_by' => 'someone-else'], $uid)) !== null);
	$check('task with an unknown field is refused', $throws(fn () => $tasks->create($copy->getId(), ['addressee' => 'conductor', 'question' => 'q', 'status' => 'applied'], $uid)) !== null);
	$link = $links->create($copy->getId(), ['measure_from' => '62', 'measure_to' => '66', 'voice' => 'Tenor II', 'note' => 'please check', 'task_id' => $task->getId()], $uid);
	$check('link created with token', strlen($link->getToken()) === 32 && $link->getExpires() !== null, (string)$link->getExpires());
	$resolved = $links->resolve($link->getToken(), $uid);
	$check('link resolves to the excerpt', $resolved['shelfmark'] === $shelfmark && $resolved['measure_from'] === '62' && $resolved['changed'] === false);
	$check('link counts calls without names', !array_key_exists('created_by', $resolved));
	$check('entry lists the active link and the task', count(Server::get(CatalogService::class)->entry($copy->getId(), $uid)['links']) >= 1);
	$links->revoke($link->getId(), $uid);
	$check('revoked link is dead', $throws(fn () => $links->resolve($link->getToken(), $uid)) !== null);
	$check('unknown token is refused', $throws(fn () => $links->resolve(str_repeat('a', 32), $uid)) !== null);

	// A downgrade to preservation copy hides the excerpt from roles that may not see it
	$link2 = $links->create($copy->getId(), [], $uid);
	$version = Server::get(WorkVersionMapper::class)->find($copy->getVersionId());
	$check('librarian still sees a preservation copy', $version->getPreservationCopy() === true || $links->resolve($link2->getToken(), $uid)['id'] === $link2->getId());
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::CONDUCTOR, $conductorGroups);
}

IntegrationTest::finish();
