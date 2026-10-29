<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Integration test of opening archive files from the Files app (module
 * "files_integration") against a real Nextcloud instance with an imported
 * catalog and a file sync: which copy a Nextcloud file belongs to, the same
 * 404 for unlisted files and for files of others (even when they are in the
 * archive), the route and the script of the Files app with the module
 * switched off, and who gets the script.
 *
 * Usage (as the web server user):
 *   php tests/Integration/files_integration_test.php <nextcloud-root> <uid>
 *
 * <uid> must hold a role and see the archive folder; the catalog must have
 * a copy with a MusicXML file and one with a PDF (the demo data have both).
 * The test creates a second account without a role (random password, never
 * printed) with one file of its own and deletes it at the end. Database
 * changes are rolled back; the module switches are exactly as before.
 */

require __DIR__ . '/harness.php';
[$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Controller\FilesIntegrationController;
use OCA\Cantoria\Db\ArchiveFile;
use OCA\Cantoria\Db\ArchiveFileMapper;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Listener\FilesScriptListener;
use OCA\Cantoria\Middleware\ModuleMiddleware;
use OCA\Cantoria\Service\AppSettings;
use OCA\Cantoria\Service\FilesIntegrationService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\Roles;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Util;
use Psr\Log\LoggerInterface;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

const NOT_LISTED = 'This file is not listed in the music archive.';
const SCRIPT = 'cantoria/js/cantoria-files';

$db = Server::get(IDBConnection::class);
$appConfig = Server::get(IAppConfig::class);
$modules = Server::get(Modules::class);
$service = Server::get(FilesIntegrationService::class);
$archiveFiles = Server::get(ArchiveFileMapper::class);
$rootFolder = Server::get(IRootFolder::class);
$session = Server::get(IUserSession::class);
$users = Server::get(IUserManager::class);
$l = Server::get(IFactory::class)->get(Application::APP_ID, 'en');

$user = $users->get($uid);
$check('test account exists', $user !== null);
$check('test account has access', Server::get(Roles::class)->hasAccess($uid));

/** The module switches this test changes: key => stored value, or null when not stored. */
$switchKeys = [Modules::FILES_INTEGRATION, 'score_viewer'];
$switches = function () use ($appConfig, $switchKeys): array {
	$values = [];
	foreach ($switchKeys as $key) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		$values[$key] = $appConfig->hasKey(Application::APP_ID, $configKey, null)
			? $appConfig->getValueBool(Application::APP_ID, $configKey)
			: null;
	}
	return $values;
};
$original = $switches();

$controller = new FilesIntegrationController(Server::get(IRequest::class), $uid, Server::get(Roles::class), $service, $l,
	Server::get(LoggerInterface::class));
$middleware = new ModuleMiddleware($modules, $l);
/** The answer of the route: the middleware first, then the method. */
$route = function (int $fileId) use ($middleware, $controller): JSONResponse {
	try {
		$middleware->beforeController($controller, 'copyOfFile');
	} catch (ModuleDisabledException $e) {
		$response = $middleware->afterException($controller, 'copyOfFile', $e);
		return $response instanceof JSONResponse ? $response : new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
	return $controller->copyOfFile($fileId);
};
/** Whether the Files app gets the script for the account in the session. */
$scriptLoaded = function (?IUser $account) use ($session): bool {
	$session->setUser($account);
	Server::get(FilesScriptListener::class)->handle(new LoadAdditionalScriptsEvent());
	return in_array(SCRIPT, Util::getScripts(), true);
};

// A second account without a role, with one file of its own. Created
// outside the transaction, so that deleting it removes its files, too.
$otherUid = 'cantoria-files-test-' . bin2hex(random_bytes(4));
$other = $users->createUser($otherUid, bin2hex(random_bytes(24)) . 'aA1!');
$check('second account created', $other instanceof IUser);
$foreignFileId = 0;

$db->beginTransaction();
try {
	if (!$other instanceof IUser || $user === null) {
		throw new RuntimeException('accounts missing');
	}
	$modules->change([Modules::FILES_INTEGRATION => true, 'score_viewer' => true]);

	// ---- the files of the catalog ----
	$musicxml = null;
	$pdf = null;
	foreach ($archiveFiles->findFound() as $file) {
		if ($file->getCopyId() === null) {
			continue;
		}
		if ($musicxml === null && $file->getRole() === 'musicxml' && in_array($file->getType(), ['mxl', 'musicxml'], true)) {
			$musicxml = $file;
		}
		if ($pdf === null && $file->getType() === 'pdf') {
			$pdf = $file;
		}
	}
	$check('the catalog has a MusicXML file and a PDF of copies', $musicxml !== null && $pdf !== null);
	if ($musicxml === null || $pdf === null) {
		throw new RuntimeException('no MusicXML file or PDF in the catalog; run the file sync first');
	}
	$copies = Server::get(CopyMapper::class);

	$answer = $service->copyOf($musicxml->getFileId(), $uid);
	$copy = $copies->find((int)$musicxml->getCopyId());
	$check('a listed MusicXML file names its copy', $answer['copyId'] === $copy->getId() && $answer['shelfmark'] === $copy->getShelfmark(),
		json_encode($answer) ?: '');
	$check('a listed MusicXML file opens the score view', $answer['score'] === true && $answer['role'] === 'musicxml');
	$answer = $service->copyOf($pdf->getFileId(), $uid);
	$check('a listed PDF opens its copy', $answer['copyId'] === $pdf->getCopyId() && $answer['score'] === false && $answer['role'] === 'score_pdf',
		json_encode($answer) ?: '');
	$response = $route($musicxml->getFileId());
	$check('the route answers 200 with the copy', $response->getStatus() === Http::STATUS_OK
		&& ($response->getData()['copyId'] ?? null) === $musicxml->getCopyId(), json_encode($response->getData()) ?: '');

	// ---- files that are not listed ----
	$archive = Server::get(AppSettings::class)->archiveFolder($uid);
	$known = $archiveFiles->findAllByFileId();
	$unlisted = null;
	$withoutCopy = null;
	$walk = function (Folder $folder, int $depth) use (&$walk, &$unlisted, &$withoutCopy, $known): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$row = $known[$node->getId()] ?? null;
				$unlisted ??= $row === null ? $node : null;
				$withoutCopy ??= $row !== null && $row->getCopyId() === null ? $node : null;
			} elseif ($node instanceof Folder && $depth < 2) {
				$walk($node, $depth + 1);
			}
		}
	};
	$walk($archive, 0);
	$check('the archive folder has a file that is not listed', $unlisted !== null);
	if ($unlisted !== null) {
		$check('an unlisted file in the archive folder is not listed', $throws(fn () => $service->copyOf($unlisted->getId(), $uid)) === NOT_LISTED);
		$response = $route($unlisted->getId());
		$check('the route answers 404 for it', $response->getStatus() === Http::STATUS_NOT_FOUND
			&& $response->getData() === ['error' => NOT_LISTED], json_encode($response->getData()) ?: '');
	}
	if ($withoutCopy !== null) {
		$check('a file of the archive without a copy is not listed', $throws(fn () => $service->copyOf($withoutCopy->getId(), $uid)) === NOT_LISTED);
	}
	$check('an unknown file id is not listed', $throws(fn () => $service->copyOf(PHP_INT_MAX, $uid)) === NOT_LISTED);

	// ---- a file of another account, even when it is in the archive ----
	$foreign = $rootFolder->getUserFolder($otherUid)->newFile('2-01_Foreign.mxl', '<score-partwise version="4.0"/>');
	$foreignFileId = $foreign->getId();
	$check('the test account cannot see the file of the other account',
		$rootFolder->getUserFolder($uid)->getFirstNodeById($foreignFileId) === null);
	$row = new ArchiveFile();
	$row->setCopyId($musicxml->getCopyId());
	$row->setFileId($foreignFileId);
	$row->setPath('foreign/2-01_Foreign.mxl');
	$row->setName('2-01_Foreign.mxl');
	$row->setType('mxl');
	$row->setRole('musicxml');
	$row->setFound(true);
	$row->setSyncedAt(time());
	$archiveFiles->insert($row);
	$check('the foreign file is answered for the account that sees it', $service->copyOf($foreignFileId, $otherUid)['copyId'] === $musicxml->getCopyId());
	$check('a foreign file in the archive is not listed for others', $throws(fn () => $service->copyOf($foreignFileId, $uid)) === NOT_LISTED);
	$response = $route($foreignFileId);
	$check('the route answers the same 404 as for an unlisted file', $response->getStatus() === Http::STATUS_NOT_FOUND
		&& $response->getData() === ['error' => NOT_LISTED], json_encode($response->getData()) ?: '');

	// ---- the script of the Files app ----
	$check('an account without a role gets no script', !$scriptLoaded($other));
	$check('an account without a role gets no state', $service->clientState($otherUid) === null);
	$state = $service->clientState($uid);
	$expectedPath = $rootFolder->getUserFolder($uid)->getRelativePath($archive->getPath());
	$check('the state names the archive folder as the account sees it', $state !== null && $state['archivePath'] === $expectedPath,
		json_encode($state) ?: '');
	$check('the state knows MusicXML, PDF and audio', $state !== null && array_diff(['mxl', 'musicxml', 'pdf', 'mscz', 'mp3'], $state['extensions']) === []);

	// ---- switched off ----
	$modules->change([Modules::FILES_INTEGRATION => false]);
	$response = $route($musicxml->getFileId());
	$check('the route answers 404 while the module is off', $response->getStatus() === Http::STATUS_NOT_FOUND
		&& $response->getData() === ['error' => 'This function is switched off.'], json_encode($response->getData()) ?: '');
	$check('no script while the module is off', !$scriptLoaded($user));

	$modules->change([Modules::FILES_INTEGRATION => true]);
	$check('the route answers again', $route($musicxml->getFileId())->getStatus() === Http::STATUS_OK);
	$check('the account with a role gets the script', $scriptLoaded($user));

	// Without the score view, MusicXML opens the copy; switching off relaxes nothing
	$modules->change(['score_viewer' => false]);
	$check('without the score view MusicXML opens the copy', $service->copyOf($musicxml->getFileId(), $uid)['score'] === false);
	$check('and the state says so', ($service->clientState($uid)['scoreView'] ?? true) === false);
} catch (Throwable $e) {
	IntegrationTest::unexpected($e);
} finally {
	$db->rollBack();
	$session->setUser(null);
	// Exactly the switches of before, also when the rollback did not cover
	// them (the app config caches its values).
	$appConfig->clearCache(true);
	foreach ($original as $key => $value) {
		$configKey = Modules::CONFIG_PREFIX . $key;
		if ($value === null) {
			$appConfig->deleteKey(Application::APP_ID, $configKey);
		} elseif (!$appConfig->hasKey(Application::APP_ID, $configKey, null) || $appConfig->getValueBool(Application::APP_ID, $configKey) !== $value) {
			$appConfig->setValueBool(Application::APP_ID, $configKey, $value);
		}
	}
	$appConfig->clearCache(true);
	$check('module configuration restored exactly', $switches() === $original);
	if ($other instanceof IUser) {
		$check('second account deleted with its files', $other->delete());
	}
	if ($foreignFileId !== 0) {
		try {
			$archiveFiles->findByFileId($foreignFileId);
			$check('no archive row of the foreign file is left', false);
		} catch (DoesNotExistException) {
			$check('no archive row of the foreign file is left', true);
		}
	}
}

IntegrationTest::finish();
