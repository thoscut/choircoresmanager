<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Cleanup after the run of the agent (tools/ci/integration-demo.sh, step 7):
 * the catalog librarian rejects every open proposal of the agent's account
 * and discards its open tasks, as a person would in the app. Nothing is
 * deleted (guideline 3); the catalog itself was never changed.
 *
 * Usage (as the web server user):
 *   php tools/ci/agent-demo-cleanup.php <nextcloud-root> <librarian-uid> <agent-uid>
 *
 * Exit code 0 when nothing of the agent is left open.
 */

require __DIR__ . '/../../tests/Integration/harness.php';
[$root, $librarian, $agent] = IntegrationTest::start($argv, ['nextcloud-root', 'librarian-uid', 'agent-uid']);

use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Db\Task;
use OCA\Notenarchiv\Db\TaskMapper;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\TaskService;
use OCP\Server;

$check = IntegrationTest::check(...);
$proposalMapper = Server::get(ProposalMapper::class);
$taskMapper = Server::get(TaskMapper::class);
$openTasks = fn (): array => array_values(array_filter($taskMapper->findByStatus([Task::OPEN, Task::ANSWERED]),
	fn (Task $task): bool => $task->getCreatedBy() === $agent));

$rejected = 0;
foreach ($proposalMapper->findByCreator($agent, Proposal::OPEN) as $proposal) {
	Server::get(ProposalService::class)->reject($proposal->getId(), $librarian, 'CI run of the agent interface: rejected by the cleanup');
	$rejected++;
}
$discarded = 0;
foreach ($openTasks() as $task) {
	Server::get(TaskService::class)->close($task->getId(), Task::DISCARDED, 'CI run of the agent interface: discarded by the cleanup', $librarian);
	$discarded++;
}
$check('proposals of the agent rejected', $rejected >= 1, (string)$rejected);
$check('tasks of the agent discarded', $discarded >= 1, (string)$discarded);
$check('no proposal of the agent is open', $proposalMapper->findByCreator($agent, Proposal::OPEN) === []);
$check('no task of the agent is open', $openTasks() === []);
$check('every proposal of the agent is decided', array_filter($proposalMapper->findByCreator($agent, null),
	fn (Proposal $proposal): bool => $proposal->getStatus() !== Proposal::REJECTED) === []);

IntegrationTest::finish();
