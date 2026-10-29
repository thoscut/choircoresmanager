<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of the revisions of a version (milestone 0.8, module
 * "version_states") against a real Nextcloud instance with an imported
 * catalog and a file sync.
 *
 * Usage (as the web server user):
 *   php tests/Integration/version_states_test.php <nextcloud-root> <uid> <shelfmark>
 *
 * <shelfmark> is a copy with a MusicXML score (.musicxml or .mxl) that <uid>
 * may read and whose folder <uid> may write to. The test runs on any catalog
 * and does not depend on the module switches of the instance: it switches
 * on what it needs, gives the user's groups the roles "librarian" and
 * "conductor" for the time of the test (and once takes "librarian" away),
 * runs the file sync, saves a small correction, sets the current revision
 * and confirms a concert of today as sung. Database changes are rolled
 * back, the corrected file is deleted, roles and module switches are
 * restored exactly.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $shelfmark] = IntegrationTest::start($argv, ['nextcloud-root', 'uid', 'shelfmark']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\AuditEntryMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Event;
use OCA\Cantoria\Db\EventItemMapper;
use OCA\Cantoria\Db\VersionState;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\CorrectionService;
use OCA\Cantoria\Service\EventService;
use OCA\Cantoria\Service\FileSyncService;
use OCA\Cantoria\Service\FullBackupService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsService;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\ScoreService;
use OCA\Cantoria\Service\StateService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$roles = Server::get(Roles::class);
$states = Server::get(StateService::class);
$catalog = Server::get(CatalogService::class);
$scores = Server::get(ScoreService::class);
$corrections = Server::get(CorrectionService::class);
$fileSync = Server::get(FileSyncService::class);
$events = Server::get(EventService::class);
$fileMapper = Server::get(ArchiveFileMapper::class);
$userFolder = Server::get(IRootFolder::class)->getUserFolder($uid);

$copy = Server::get(CopyMapper::class)->findAllByShelfmark()[$shelfmark] ?? null;
if ($copy === null) {
	$check('the copy exists', false, $shelfmark);
	IntegrationTest::finish();
}
$versionId = $copy->getVersionId();

/** The MusicXML text of a score file (.mxl: the root file named in the container) */
$scoreText = function (string $bytes): string {
	if (!str_starts_with($bytes, "PK\x03\x04")) {
		return $bytes;
	}
	$path = tempnam(sys_get_temp_dir(), 'mxl');
	file_put_contents($path, $bytes);
	$zip = new ZipArchive();
	$zip->open($path);
	$name = '';
	$container = $zip->getFromName('META-INF/container.xml');
	if (is_string($container) && preg_match('/full-path\s*=\s*"([^"]+)"/', $container, $m) === 1) {
		$name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
	if ($name === '' || $zip->locateName($name) === false) {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$candidate = (string)$zip->getNameIndex($i);
			if (!str_starts_with($candidate, 'META-INF/') && preg_match('/\.(musicxml|xml)$/i', $candidate) === 1) {
				$name = $candidate;
				break;
			}
		}
	}
	$text = (string)$zip->getFromName($name);
	$zip->close();
	unlink($path);
	return $text;
};

/** @return list<VersionState> */
$revisions = fn (): array => $states->forVersion($versionId, $uid)['states'];

// What the test changes outside the transaction, restored in "finally"
$needed = ['score_viewer', Modules::VERSION_STATES, 'corrections', 'concerts'];
$storedSwitches = [];
foreach ([...$needed, 'transcription_review'] as $key) {
	$configKey = Modules::CONFIG_PREFIX . $key;
	$storedSwitches[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null) ? $appConfig->getValueBool(Application::APP_ID, $configKey) : null;
}
$librarianGroups = $roles->groups(Roles::LIBRARIAN);
$conductorGroups = $roles->groups(Roles::CONDUCTOR);
$userGroups = Server::get(IGroupManager::class)->getUserGroupIds(Server::get(IUserManager::class)->get($uid));
/** @var list<array{folder: Folder, name: string}> $written */
$written = [];

$db->beginTransaction();
try {
	// ---- preconditions made by the test itself ---------------------------------
	$roles->setGroups(Roles::LIBRARIAN, array_values(array_unique([...$librarianGroups, ...$userGroups])));
	$roles->setGroups(Roles::CONDUCTOR, array_values(array_unique([...$conductorGroups, ...$userGroups])));
	$check('the account is catalog librarian and conductor', $roles->hasAnyRole($uid, [Roles::LIBRARIAN]) && $roles->hasAnyRole($uid, [Roles::CONDUCTOR]),
		$userGroups === [] ? 'the account is in no group' : '');
	// Reports of transcriptions are not needed here; without them the file sync reads none.
	$modules->change(array_fill_keys($needed, true) + ['transcription_review' => false]);
	$check('the module is active with what it needs', $modules->isEnabled(Modules::VERSION_STATES) && $modules->isEnabled('corrections'));
	$check('the complete backup covers the revisions', in_array('notarch_states', FullBackupService::TABLES, true));

	$score = $catalog->entry($copy->getId(), $uid)['score'];
	$check('the copy has a MusicXML score', $score !== null && $score['canView'], $shelfmark);
	if ($score === null || !$score['canView']) {
		throw new RuntimeException('The copy ' . $shelfmark . ' has no MusicXML score this account may view.');
	}
	$fileId = (int)$score['fileId'];
	$scoreFile = $fileMapper->find($fileId);
	$oldNode = $userFolder->getFirstNodeById($scoreFile->getFileId());
	$check('the score file is readable', $oldNode instanceof File);
	assert($oldNode instanceof File);
	$folder = $oldNode->getParent();
	$oldHash = hash('sha256', $oldNode->getContent());

	// ---- the file sync records the first revision ---------------------------------
	$before = count($revisions());
	$sync = $fileSync->sync($uid);
	$after = $revisions();
	$check('the file sync reports the revisions', isset($sync['states']) && $sync['states']['switchedOff'] === false, json_encode($sync['states'] ?? null));
	$ofScore = array_values(array_filter($after, fn (VersionState $s): bool => $s->getFileId() === $fileId));
	$newestOfScore = $ofScore === [] ? null : $ofScore[count($ofScore) - 1];
	$check('the score file is a revision of the version with its checksum', $newestOfScore !== null && $newestOfScore->getSha256() === $oldHash,
		json_encode(['before' => $before, 'after' => count($after)]));
	if ($before === 0) {
		$check('the first revision of the version is number 1 from the file sync', ($after[0] ?? null)?->getNumber() === 1
			&& $after[0]->getSource() === VersionState::FIRST && $after[0]->getCreatedBy() === $uid);
	}
	$numbers = array_map(fn (VersionState $s): int => $s->getNumber(), $after);
	$check('revisions count 1, 2, 3 … per version', $numbers === range(1, count($after)), implode(', ', $numbers));
	$fileSync->sync($uid);
	$check('an unchanged file makes no new revision', count($revisions()) === count($after));
	$check('nothing is current until the catalog librarian sets it', $before > 0 || StateService::current($revisions()) === null);

	// ---- switched off ------------------------------------------------------------
	$modules->change([Modules::VERSION_STATES => false]);
	$check('switched off, the revisions are not read', (function () use ($states, $versionId, $uid): bool {
		try {
			$states->forVersion($versionId, $uid);
			return false;
		} catch (ModuleDisabledException) {
			return true;
		}
	})());
	$check('switched off, the file sync records nothing', ($fileSync->sync($uid)['states']['switchedOff'] ?? false) === true);
	$check('switched off, the copy shows no revisions', $catalog->entry($copy->getId(), $uid)['revision'] === null);
	$modules->change([Modules::VERSION_STATES => true]);

	// ---- a correction is a new revision -----------------------------------------------
	$xml = $scoreText($scores->content($fileId, $uid)['content']);
	$document = new DOMDocument();
	$document->preserveWhiteSpace = true;
	$document->loadXML($xml, LIBXML_NONET);
	$xpath = new DOMXPath($document);
	$note = $xpath->query('/score-partwise/part[1]/measure[not(@implicit="yes")]/note[pitch and not(grace)]')?->item(0);
	$check('the score has a pitched note to correct', $note instanceof DOMElement);
	if (!$note instanceof DOMElement) {
		throw new RuntimeException('The score of ' . $shelfmark . ' has no note to correct.');
	}
	$measure = $note->parentNode instanceof DOMElement ? $note->parentNode->getAttribute('number') : '';
	$partName = trim((string)$xpath->evaluate('string(/score-partwise/part-list/score-part[1]/part-name)'));
	$step = $xpath->query('pitch/step', $note)?->item(0);
	assert($step instanceof DOMElement);
	$step->textContent = trim($step->textContent) === 'A' ? 'B' : 'A';
	$modified = (string)$document->saveXML();

	$count = count($revisions());
	$corrected = $corrections->correct($versionId, $fileId, $modified, [], 'Integration test: wrong note', $uid);
	$newFile = $corrected['file'];
	$written[] = ['folder' => $folder, 'name' => $newFile->getName()];
	$all = $revisions();
	$latest = $all[count($all) - 1] ?? null;
	$newNode = $userFolder->getFirstNodeById($newFile->getFileId());
	$check('the correction made exactly one new revision', count($all) === $count + 1 && $corrected['state'] === $latest?->getNumber(),
		json_encode(['before' => $count, 'after' => count($all), 'state' => $corrected['state']]));
	$check('the revision points to the new file with its checksum', $latest !== null && $latest->getSource() === VersionState::CORRECTION
		&& $latest->getFileId() === $newFile->getId() && $latest->getFileName() === $newFile->getName()
		&& $newNode instanceof File && $latest->getSha256() === hash('sha256', $newNode->getContent()));
	$check('the revision names measure, voice, reason and changes', $latest !== null && $latest->getMeasures() === $measure
		&& ($partName === '' || $latest->getVoices() === $partName) && str_contains((string)$latest->getNote(), 'Integration test: wrong note')
		&& str_contains((string)$latest->getChanges(), '"kind":"pitch"'),
		json_encode([$latest?->getMeasures(), $latest?->getVoices(), $measure, $partName], JSON_UNESCAPED_UNICODE));
	$check('the old file is unchanged', hash('sha256', $oldNode->getContent()) === $oldHash);
	$check('both files of the comparison can still be read', hash('sha256', $scores->content($fileId, $uid)['content']) === $oldHash
		&& $newNode instanceof File && hash('sha256', $scores->content($newFile->getId(), $uid)['content']) === $latest?->getSha256());
	$fileSync->sync($uid);
	$check('the file sync after the correction adds no revision', count($revisions()) === count($all));

	// ---- the current revision ----------------------------------------------------------
	assert($latest instanceof VersionState);
	$previous = $all[count($all) - 2];
	$today = Server::get(RightsService::class)->today($uid);
	$set = $states->setCurrent($previous->getId(), $uid, 'Integration test');
	$check('the catalog librarian sets the current revision', $set->getValidSince() === $today && $set->getValidSetBy() !== null);
	$check('the same revision again is refused', $throws(fn () => $states->setCurrent($previous->getId(), $uid, null)) !== null);
	$states->setCurrent($latest->getId(), $uid, null);
	$current = array_values(array_filter($revisions(), fn (VersionState $s): bool => $s->getValidSince() !== null));
	$check('only one revision is current', count($current) === 1 && $current[0]->getId() === $latest->getId());
	$logged = array_filter(Server::get(AuditEntryMapper::class)->findForObjects([['version', $versionId]]),
		fn ($e): bool => $e->getAction() === 'state_current');
	$check('setting the current revision is in the audit log', count($logged) >= 2);
	$summary = $catalog->entry($copy->getId(), $uid)['revision'];
	$check('the copy names the current revision and the revision of the score shown', $summary !== null
		&& ($summary['current']['number'] ?? null) === $latest->getNumber() && $summary['shown'] === $latest->getNumber()
		&& $summary['latest'] === $latest->getNumber(), json_encode($summary));

	// Only the catalog librarian sets it; the conductor sees and compares
	$roles->setGroups(Roles::LIBRARIAN, array_values(array_diff($librarianGroups, $userGroups)));
	if (!$roles->hasAnyRole($uid, [Roles::LIBRARIAN])) {
		$check('the conductor may not set the current revision', $throws(fn () => $states->setCurrent($previous->getId(), $uid, null)) !== null);
		$list = $states->forVersion($versionId, $uid);
		$check('the conductor reads the revisions', count($list['states']) === count($all) && $list['may']['setCurrent'] === false);
	} else {
		$check('the user is librarian through another group: conductor rules skipped', true);
	}
	$roles->setGroups(Roles::LIBRARIAN, array_values(array_unique([...$librarianGroups, ...$userGroups])));

	// ---- a concert keeps the revision sung --------------------------------------------
	$event = $events->create(Event::CONCERT, ['title' => 'Integration test concert', 'event_date' => $today], $uid);
	$item = $events->addItem($event->getId(), $copy->getId(), $uid);
	$planned = $events->detail($event->getId(), $uid)['items'][0] ?? [];
	$check('the programme names the current revision', ($planned['revision'] ?? null) === $latest->getNumber()
		&& ($planned['revision_valid_since'] ?? null) === $today && ($planned['revision_sung'] ?? true) === false);
	$events->confirmSung($event->getId(), [], $uid);
	$sungItem = Server::get(EventItemMapper::class)->find($item->getId());
	$check('confirming "sung" keeps the revision', $sungItem->getStateId() === $latest->getId());
	$states->setCurrent($previous->getId(), $uid, 'Integration test: back');
	$sung = $events->detail($event->getId(), $uid)['items'][0] ?? [];
	$check('the revision sung stays when another one becomes current', ($sung['revision'] ?? null) === $latest->getNumber()
		&& ($sung['revision_sung'] ?? false) === true);
	$csv = $events->songOrderCsv($event->getId(), $uid, Server::get(\OCP\L10N\IFactory::class)->get('cantoria', 'en'));
	$check('the song order names the revision', str_contains($csv, 'Revision') && str_contains($csv, ';' . $latest->getNumber() . ";\r\n"),
		strtok($csv, "\n") ?: '');
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::LIBRARIAN, $librarianGroups);
	$roles->setGroups(Roles::CONDUCTOR, $conductorGroups);
	// Module switches exactly as before, also when the rollback did not cover
	// them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($storedSwitches as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	// Delete by name: the rollback also removed the new file from the file
	// cache, so it no longer shows up in a directory listing.
	foreach ($written as ['folder' => $where, 'name' => $name]) {
		if ($where->nodeExists($name)) {
			$where->get($name)->delete();
		}
	}
	$check('the corrected file is deleted again', array_filter($written, fn (array $w): bool => $w['folder']->nodeExists($w['name'])) === []);
}

IntegrationTest::finish();
