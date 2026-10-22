<?php

declare(strict_types=1);

/**
 * Integration test of the gatekeeper for practice audio files against a real
 * Nextcloud instance with an imported catalog: the rights policy, the roles
 * and the audit entry of an allowed export.
 *
 * Usage (as the web server user):
 *   php tests/Integration/practice_audio_test.php <nextcloud-root> <uid> <shelfmark-protected> <shelfmark-preservation-copy> <shelfmark-public-domain>
 *
 * <uid> must hold the role "librarian". The first shelf mark is a version
 * without a license for practice audio, the second a preservation copy and
 * the third an unconfirmed public domain version, all three of different
 * versions. A catalog without a protected version or a preservation copy
 * gets them inside the transaction. The test temporarily takes the user's
 * groups out of the role "librarian". Database changes are rolled back.
 */

require __DIR__ . '/harness.php';
[$root, $uid, $protectedMark, $preservationMark, $publicMark] = IntegrationTest::start($argv,
	['nextcloud-root', 'uid', 'shelfmark-protected', 'shelfmark-preservation-copy', 'shelfmark-public-domain']);

use OCA\Notenarchiv\Db\AuditEntry;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\LicenseService;
use OCA\Notenarchiv\Service\PracticeAudioService;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCP\IDBConnection;
use OCP\Server;

$check = IntegrationTest::check(...);
$throws = IntegrationTest::throws(...);

$db = Server::get(IDBConnection::class);
$copies = Server::get(CopyMapper::class)->findAllByShelfmark();
$versions = Server::get(WorkVersionMapper::class);
$audit = Server::get(AuditEntryMapper::class);
$practiceAudio = Server::get(PracticeAudioService::class);
$licenses = Server::get(LicenseService::class);
$rights = Server::get(RightsService::class);
$roles = Server::get(Roles::class);
$librarianGroups = $roles->groups(Roles::LIBRARIAN);

$protected = $copies[$protectedMark];
$preservation = $copies[$preservationMark];
$public = $copies[$publicMark];

/**
 * The practice audio entries of a version in the audit log, newest first.
 *
 * @return list<AuditEntry>
 */
$entries = fn (int $versionId): array => array_values(array_filter($audit->findForObjects([['version', $versionId]], 1000),
	fn (AuditEntry $entry): bool => $entry->getAction() === PracticeAudioService::ACTION));

/**
 * A request for one copy: measures 1 to 4, Tenor 1 louder, as WAV.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
$request = fn (int $copyId, array $changes = []): array => array_merge(['copy_id' => $copyId, 'measure_from' => '1', 'measure_to' => '4',
	'voice' => 'Tenor 1', 'mode' => 'focus', 'format' => 'wav', 'tempo' => 90, 'repeats' => true], $changes);

/**
 * The reason of a refusal by the rights policy, or null when the export was allowed.
 */
$refusal = function (int $versionId, array $data) use ($practiceAudio, $uid): ?string {
	try {
		$practiceAudio->authorize($versionId, $data, $uid);
	} catch (AccessDeniedException $e) {
		return $e->getMessage();
	}
	return null;
};

$db->beginTransaction();
try {
	// Preconditions, so that the test also runs on a catalog where everything is public domain
	$catalog = Server::get(CatalogService::class);
	if ($versions->find($protected->getVersionId())->getRightsStatus() !== 'protected') {
		$catalog->update('version', $protected->getVersionId(), ['rights_status' => 'protected'], $uid, 'integration test');
	}
	if ($versions->find($preservation->getVersionId())->getPreservationCopy() !== true) {
		$catalog->update('version', $preservation->getVersionId(), ['preservation_copy' => true], $uid, 'integration test');
	}

	// Protected without a license: refused with the reason, nothing recorded
	$versionId = $protected->getVersionId();
	$before = count($entries($versionId));
	$reason = $refusal($versionId, $request($protected->getId()));
	$check('protected version is refused', $reason === 'Protected version: playback needs a license for practice audio.', (string)$reason);
	$check('a refused export is not recorded', count($entries($versionId)) === $before);

	// A license for practice audio allows it; the export is recorded
	$licenses->create($versionId, ['holder' => 'Publisher', 'uses' => ['practice_audio'], 'valid_until' => '2099-12-31', 'evidence' => 'invoice 42'], $uid);
	$check('protected version with a practice audio license is allowed', $refusal($versionId, $request($protected->getId())) === null);
	$recorded = $entries($versionId);
	$check('the allowed export is recorded', count($recorded) === $before + 1);
	$details = json_decode((string)(($recorded[0] ?? null)?->getNewValue() ?? ''), true);
	$check('the entry names copy, measures, voice, mode and format', is_array($details)
		&& $details['copy'] === $protected->getShelfmark() && $details['measures'] === '1-4' && $details['voice'] === 'Tenor 1'
		&& $details['mode'] === 'focus' && $details['format'] === 'wav' && $details['tempo'] === 90, (string)(($recorded[0] ?? null)?->getNewValue() ?? ''));
	$check('the entry names who exported', (($recorded[0] ?? null)?->getUserId() ?? '') === $uid);
	$practiceAudio->authorize($versionId, $request($protected->getId(), ['voice' => null, 'mode' => 'solo', 'format' => 'midi', 'all_voices' => true]), $uid);
	$details = json_decode((string)(($entries($versionId)[0] ?? null)?->getNewValue() ?? ''), true);
	$check('a file for every voice is recorded as such', is_array($details) && $details['voice'] === '*' && $details['format'] === 'midi');
	$check('invalid request data is refused', $throws(fn () => $practiceAudio->authorize($versionId, $request($protected->getId(), ['format' => 'mp3']), $uid)) !== null);
	$check('a copy of another version is refused', $throws(fn () => $practiceAudio->authorize($versionId, $request($public->getId()), $uid))
		=== 'The copy does not belong to this version.');

	// Internal preservation copy: never, not even with a license
	$versionId = $preservation->getVersionId();
	$check('the preservation copy is one', $versions->find($versionId)->getPreservationCopy() === true);
	$licenses->create($versionId, ['holder' => 'Publisher', 'uses' => ['practice_audio'], 'valid_until' => '2099-12-31', 'evidence' => 'invoice 43'], $uid);
	$before = count($entries($versionId));
	$reason = $refusal($versionId, $request($preservation->getId()));
	$check('preservation copy is refused despite a license', $reason === 'Internal preservation copy: no playback.', (string)$reason);
	$check('nothing recorded for the preservation copy', count($entries($versionId)) === $before);

	// Public domain: playback without confirmation, files only once confirmed
	$versionId = $public->getVersionId();
	$version = $versions->find($versionId);
	$check('the public domain version is not confirmed yet', $version->getRightsStatus() === 'public_domain' && !$version->isConfirmed());
	$reason = $refusal($versionId, $request($public->getId()));
	$check('unconfirmed public domain is refused', $reason !== null && str_starts_with($reason, 'Public domain is not confirmed yet'), (string)$reason);
	$rights->confirm($versionId, $uid, 'public_domain', 'Composer died 1827', 'GND entry');
	$check('confirmed public domain is allowed', $refusal($versionId, $request($public->getId(), ['voice' => null, 'mode' => 'all'])) === null);

	// Roles: the read-only role may play, but makes no files
	$roles->setGroups(Roles::LIBRARIAN, []);
	if ($roles->hasAnyRole($uid, [Roles::CONDUCTOR])) {
		$check('without the librarian role (skipped: the user is also conductor)', true);
	} else {
		$reason = $refusal($versionId, $request($public->getId()));
		$check('without the librarian or conductor role it is refused', $reason === 'Practice audio files are made by the catalog librarian or the conductor.', (string)$reason);
	}
} finally {
	$db->rollBack();
	$roles->setGroups(Roles::LIBRARIAN, $librarianGroups);
}

IntegrationTest::finish();
