<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the rights/release workflow (milestone 0.2) against a
 * real Nextcloud instance with an imported catalog.
 *
 * Usage (as the web server user, from any directory):
 *   php tests/Integration/workflow_test.php <nextcloud-root> <uid> <shelfmark-a> <shelfmark-b>
 *
 * <uid> must hold the role "librarian" and have access to the archive folder.
 * Everything runs inside one outer database transaction that is rolled back
 * at the end, so the catalog is left unchanged. Exported files are removed.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmarkA, $shelfmarkB] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark-a', 'shelfmark-b']);

use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\ExportService;
use OCA\Notenarchiv\Service\LicenseService;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\ReleaseListService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ValidVersionService;
use OCP\IDBConnection;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$copies = Server::get(CopyMapper::class)->findAllByShelfmark();
$versions = Server::get(WorkVersionMapper::class);
$catalog = Server::get(CatalogService::class);
$rights = Server::get(RightsService::class);
$valid = Server::get(ValidVersionService::class);
$licenses = Server::get(LicenseService::class);
$proposals = Server::get(ProposalService::class);
$lists = Server::get(ReleaseListService::class);
$policy = Server::get(RightsPolicy::class);
$roles = Server::get(Roles::class);
$export = Server::get(ExportService::class);

$a = $versions->find($copies[$shelfmarkA]->getVersionId());
$b = $versions->find($copies[$shelfmarkB]->getVersionId());
$storedFiles = [];
$conductorGroups = $roles->groups(Roles::CONDUCTOR);

$db->beginTransaction();
try {
	// Confirmation needs reason and evidence; only "public domain" or "protected"
	$check('confirm without evidence is refused', $throws(fn () => $rights->confirm($a->getId(), $uid, 'public_domain', 'reason', '')) !== null);
	$check('confirm "open" is refused', $throws(fn () => $rights->confirm($a->getId(), $uid, 'open', 'r', 'e')) !== null);
	$rights->confirm($a->getId(), $uid, 'public_domain', 'Composer died 1827', 'GND entry, title page');
	$a = $versions->find($a->getId());
	$check('confirmation stored', $a->isConfirmed() && $a->getValue('confirmed_by') === $uid, (string)$a->getValue('confirmed_at'));

	// Changing the rights status removes the confirmation
	$catalog->update('version', $a->getId(), ['rights_status' => 'open'], $uid, 'test');
	$a = $versions->find($a->getId());
	$check('status change removes confirmation', !$a->isConfirmed() && $a->getRightsStatus() === 'open');
	$rights->confirm($a->getId(), $uid, 'public_domain', 'Composer died 1827', 'GND entry');

	// Portal release: only confirmed public domain (or license)
	$day = $rights->today($uid);
	$check('unconfirmed version cannot go to the portal', $throws(fn () => $rights->addToPortal($b->getId(), $uid, $day)) !== null);
	$rights->addToPortal($a->getId(), $uid, $day);
	$check('confirmed version is on the portal', $rights->isOnPortal($versions->find($a->getId())));

	// Downgrade → list says "remove"
	$catalog->update('version', $a->getId(), ['rights_status' => 'protected'], $uid, 'test downgrade');
	$row = array_values(array_filter($lists->portal($uid), fn ($r) => $r['versionId'] === $a->getId()))[0] ?? null;
	$check('downgraded version is listed as "remove"', ($row['status'] ?? null) === 'remove');
	$rights->removeFromPortal($a->getId(), $uid, 'downgraded');
	$check('removed from portal', !$rights->isOnPortal($versions->find($a->getId())));

	// License for practice audio enables playback of a protected version
	$check('protected version has no playback', $policy->playDenial($versions->find($a->getId()), $uid) !== null);
	$check('license without uses is refused', $throws(fn () => $licenses->create($a->getId(), ['holder' => 'Publisher'], $uid)) !== null);
	$license = $licenses->create($a->getId(), ['holder' => 'Publisher', 'uses' => ['practice_audio'], 'valid_until' => '2099-12-31', 'evidence' => 'invoice 42'], $uid);
	$check('licensed version may be played', $policy->playDenial($versions->find($a->getId()), $uid) === null);
	$licenses->update($license->getId(), ['valid_until' => '2000-01-01', 'valid_from' => '1999-01-01'], $uid);
	$check('ended license no longer allows playback', $policy->playDenial($versions->find($a->getId()), $uid) !== null);
	$ending = array_values(array_filter(Server::get(ReleaseListService::class)->licensesEnding($uid), fn ($row) => $row['licenseId'] === $license->getId()));
	$check('ended license is listed as ended', count($ending) === 1 && $ending[0]['ended'] === true && $ending[0]['copies'] !== [], json_encode($ending[0] ?? null));

	// Valid version: conductor only, one per work
	$check('librarian without conductor role cannot set the valid version', $roles->hasAnyRole($uid, [Roles::CONDUCTOR]) || $throws(fn () => $valid->set($b->getId(), $uid, null)) !== null);
	$roles->setGroups(Roles::CONDUCTOR, array_values(array_unique(array_merge($conductorGroups, Server::get(\OCP\IGroupManager::class)->getUserGroupIds(Server::get(\OCP\IUserManager::class)->get($uid))))));
	$valid->set($b->getId(), $uid, 'sung in 2025');
	$check('valid version set', $versions->find($b->getId())->getValidSince() !== null);

	// Proposals: accepted proposal changes the field and records the source
	$created = $proposals->propose('version', $b->getId(), ['key_signature' => 'D major'], $uid, 'from analysis file', 'round 40');
	$check('proposal created', count($created) === 1 && count($proposals->open()) >= 1);
	$proposals->accept($created[0]->getId(), $uid, null);
	$check('accepted proposal applied', $versions->find($b->getId())->getValue('key_signature') === 'D major');
	$check('decided proposal cannot be decided again', $throws(fn () => $proposals->reject($created[0]->getId(), $uid, null)) !== null);
	$check('read-only field cannot be proposed', $throws(fn () => $proposals->propose('version', $b->getId(), ['confirmed_by' => 'x'], $uid, null, null)) !== null);

	// Backup: extended CSV and ODS in the export folder
	$storedFiles = $export->storeBackup('integration-test', $uid);
	$folder = Server::get(AppSettings::class)->exportFolder($uid);
	$ods = $folder->get(basename($storedFiles[1]))->getContent();
	$zip = new ZipArchive();
	$tmp = tempnam(sys_get_temp_dir(), 'ods');
	file_put_contents($tmp, $ods);
	$check('ODS is a valid package', $zip->open($tmp) === true && $zip->getNameIndex(0) === 'mimetype'
		&& str_contains((string)$zip->getFromName('content.xml'), 'table:name="Audit log"'));
	$zip->close();
	unlink($tmp);
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::CONDUCTOR, $conductorGroups);
	if ($storedFiles !== []) {
		$folder = Server::get(AppSettings::class)->exportFolder($uid);
		foreach ($storedFiles as $path) {
			if ($folder->nodeExists(basename($path))) {
				$folder->get(basename($path))->delete();
			}
		}
	}
}

IntegrationTest::finish();
