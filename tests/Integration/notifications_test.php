<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of notifications and background jobs against a real
 * Nextcloud instance with an imported catalog and the notifications app.
 *
 * Usage (as the web server user):
 *   php tests/Integration/notifications_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <uid> must hold the role "librarian" and be the configured archive user.
 * Database changes are rolled back; exported files are removed.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\BackgroundJob\DailyChecks;
use OCA\Notenarchiv\BackgroundJob\WeeklyBackup;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Notification\Notifier;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\FullBackupService;
use OCA\Notenarchiv\Service\LicenseService;
use OCA\Notenarchiv\Service\NotificationService;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\RightsService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager;
use OCP\Server;

$check = IntegrationTest::check(...);
$runJob = function (object $job): void {
	$method = new ReflectionMethod($job, 'run');
	$method->invoke($job, null);
};
$notificationsFor = function (string $user, string $subject) : array {
	$qb = Server::get(IDBConnection::class)->getQueryBuilder();
	$qb->select('*')->from('notifications')
		->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
		->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($user)))
		->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
	return $qb->executeQuery()->fetchAllAssociative();
};

$db = Server::get(IDBConnection::class);
$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark];
$settings = Server::get(AppSettings::class);
$check('archive user is the test user', $settings->archiveInfo()['user'] === $uid);
// The weekly backup goes into the backup folder, outside the archive folder.
$backupFolder = $settings->backupFolder();
$before = array_map(fn ($n) => $n->getName(), $backupFolder->getDirectoryListing());

$db->beginTransaction();
try {
	// Proposal → one inbox notification for the librarian, rendered in German
	Server::get(ProposalService::class)->propose('version', $copy->getVersionId(), ['scoring' => 'TTBB'], $uid, null, 'test');
	$rows = $notificationsFor($uid, NotificationService::PROPOSALS_WAITING);
	$check('inbox notification created', count($rows) === 1);
	$manager = Server::get(IManager::class);
	$notification = $manager->createNotification()->setApp(Application::APP_ID)->setUser($uid)
		->setObject('inbox', 'inbox')->setSubject(NotificationService::PROPOSALS_WAITING, ['count' => 1])
		->setDateTime(new DateTime());
	$prepared = Server::get(Notifier::class)->prepare($notification, 'de');
	$check('notification rendered in German', str_contains($prepared->getParsedSubject(), 'Vorschlag'), $prepared->getParsedSubject());
	$check('notification links to the inbox', str_contains($prepared->getLink(), 'view=proposals'), $prepared->getLink());

	// License ending in 10 days → daily check notifies once
	$day = Server::get(RightsService::class)->today($uid);
	$license = Server::get(LicenseService::class)->create($copy->getVersionId(), [
		'holder' => 'Test', 'uses' => ['binder'], 'valid_until' => (new DateTimeImmutable($day))->modify('+10 days')->format('Y-m-d'),
	], $uid);
	$daily = Server::get(DailyChecks::class);
	$runJob($daily);
	$runJob($daily);
	$check('expiring license notified once', count($notificationsFor($uid, NotificationService::LICENSE_EXPIRING)) === 1);

	// Weekly backup writes CSV and ODS
	$runJob(Server::get(WeeklyBackup::class));
	$after = array_map(fn ($n) => $n->getName(), $settings->backupFolder()->getDirectoryListing());
	$new = array_values(array_diff($after, $before));
	$check('weekly backup stored CSV, ODS and JSON', count(preg_grep('/_weekly\.csv$/', $new)) === 1 && count(preg_grep('/_weekly\.ods$/', $new)) === 1
		&& count(preg_grep('/_backup_weekly\.json$/', $new)) === 1, implode(', ', $new));
	$json = array_values(preg_grep('/_backup_weekly\.json$/', $new) ?: [])[0] ?? null;
	$jsonFile = $json === null ? null : $settings->backupFolder()->get($json);
	$links = $jsonFile instanceof \OCP\Files\File ? (FullBackupService::parse($jsonFile->getContent())['tables']['notarch_links'] ?? []) : [];
	$check('the backup holds no link tokens', array_filter(array_column($links, 'token'), fn ($t): bool => $t !== '') === []);
} finally {
	$db->rollBack();
	Server::get(IAppConfig::class)->deleteKey(Application::APP_ID, 'notified_license_' . ($license ?? null)?->getId());
	// Delete by name: the rollback also removed the new files from the file
	// cache, so they no longer show up in a directory listing.
	$folder = $settings->backupFolder();
	foreach ($new ?? [] as $name) {
		if ($folder->nodeExists($name)) {
			$folder->get($name)->delete();
		}
	}
}

IntegrationTest::finish();
