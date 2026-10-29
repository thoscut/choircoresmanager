<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Cleanup after the run of the agent (tools/ci/integration-demo.sh, step 7):
 * the catalog librarian rejects every open proposal of the agent's account,
 * discards its open tasks and dismisses the review points from its reports,
 * as a person would in the app. Nothing is deleted (guideline 3): the
 * agent's transcription reports stay as evidence; the catalog itself was
 * never changed.
 *
 * Usage (as the web server user):
 *   php tools/ci/agent-demo-cleanup.php <nextcloud-root> <librarian-uid> <agent-uid>
 *
 * Exit code 0 when nothing of the agent is left open.
 */

require __DIR__ . '/../../tests/Integration/harness.php';
[$root, $librarian, $agent] = IntegrationTest::start($argv, ['nextcloud-root', 'librarian-uid', 'agent-uid']);

use OCA\Cantoria\Db\Proposal;
use OCA\Cantoria\Db\ProposalMapper;
use OCA\Cantoria\Db\ReviewPoint;
use OCA\Cantoria\Db\ReviewPointMapper;
use OCA\Cantoria\Db\Task;
use OCA\Cantoria\Db\TaskMapper;
use OCA\Cantoria\Service\ProposalService;
use OCA\Cantoria\Service\ReviewService;
use OCA\Cantoria\Service\TaskService;
use OCP\Server;

$check = IntegrationTest::check(...);
$proposalMapper = Server::get(ProposalMapper::class);
$taskMapper = Server::get(TaskMapper::class);
$pointMapper = Server::get(ReviewPointMapper::class);
$openTasks = fn (): array => array_values(array_filter($taskMapper->findByStatus([Task::OPEN, Task::ANSWERED]),
	fn (Task $task): bool => $task->getCreatedBy() === $agent));
/** Review points from the agent's reports that still wait for something */
$openPoints = fn (): array => array_values(array_filter($pointMapper->findByStatus(ReviewPoint::UNRESOLVED),
	fn (ReviewPoint $point): bool => $point->getCreatedBy() === $agent));

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
$dismissed = 0;
foreach ($openPoints() as $point) {
	Server::get(ReviewService::class)->decide($point->getId(), ReviewPoint::DISMISSED, null,
		'CI run of the agent interface: dismissed by the cleanup', null, $librarian);
	$dismissed++;
}
$check('proposals of the agent rejected', $rejected >= 1, (string)$rejected);
$check('tasks of the agent discarded', $discarded >= 1, (string)$discarded);
$check('review points of the agent dismissed', $dismissed >= 1, (string)$dismissed);
$check('no proposal of the agent is open', $proposalMapper->findByCreator($agent, Proposal::OPEN) === []);
$check('no task of the agent is open', $openTasks() === []);
$check('no review point of the agent is open', $openPoints() === []);
$check('every proposal of the agent is decided', array_filter($proposalMapper->findByCreator($agent, null),
	fn (Proposal $proposal): bool => $proposal->getStatus() !== Proposal::REJECTED) === []);

IntegrationTest::finish();
