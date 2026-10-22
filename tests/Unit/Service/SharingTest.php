<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\AuditEntry;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Db\Contribution;
use OCA\Notenarchiv\Db\ContributionMapper;
use OCA\Notenarchiv\Db\Copy;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\ExcerptLink;
use OCA\Notenarchiv\Db\ExcerptLinkMapper;
use OCA\Notenarchiv\Db\Person;
use OCA\Notenarchiv\Db\PersonMapper;
use OCA\Notenarchiv\Db\Work;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\AuditLog;
use OCA\Notenarchiv\Service\FieldValue;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\MusicXmlExcerpt;
use OCA\Notenarchiv\Service\PracticeAudioService;
use OCA\Notenarchiv\Service\PublicRepertoireService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\SharedLinkService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Sharing beyond the archive roles (milestone 0.7): making links for choir
 * members and the public, opening them, practice audio of choir members,
 * the public repertoire page and the links to CPDL and IMSLP.
 */
#[CoversClass(LinkService::class)]
#[CoversClass(SharedLinkService::class)]
#[CoversClass(PublicRepertoireService::class)]
#[CoversClass(FieldValue::class)]
#[CoversClass(PracticeAudioService::class)]
#[CoversClass(AuditLog::class)]
#[UsesClass(MusicXmlExcerpt::class)]
#[UsesClass(Modules::class)]
final class SharingTest extends TestCase {
	use ModuleSwitches;

	private const TODAY = '2026-10-22';
	private const TOKEN = 'abcdefghijABCDEFGHIJ0123456789xy';

	/** Three measures in one part */
	private const SCORE = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<score-partwise version="4.0"><work><work-title>Abendlied</work-title></work>'
		. '<part-list><score-part id="P1"><part-name>Alto</part-name></score-part></part-list><part id="P1">'
		. '<measure number="1"><attributes><divisions>1</divisions><time><beats>4</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>'
		. '<note><pitch><step>C</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '<measure number="2"><note><pitch><step>D</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '<measure number="3"><note><pitch><step>E</step><octave>5</octave></pitch><duration>4</duration><type>whole</type></note></measure>'
		. '</part></score-partwise>';

	/** @var list<array{string, ?string, mixed}> audit entries: action, field, new value */
	private array $audit = [];
	/** @var list<int> ids of links whose call was counted */
	private array $hits = [];

	private function version(array $values = []): WorkVersion {
		$version = new WorkVersion();
		$version->setId(3);
		$version->setWorkId(7);
		foreach ($values as $column => $value) {
			$version->setValue($column, $value);
		}
		return $version;
	}

	private function copy(): Copy {
		$copy = new Copy();
		$copy->setId(35);
		$copy->setVersionId(3);
		$copy->setShelfmark('1-3');
		return $copy;
	}

	private function auditLog(): AuditLog {
		$audit = $this->createStub(AuditLog::class);
		$audit->method('newOperation')->willReturn('op');
		$audit->method('record')->willReturnCallback(function (string $operation, ?string $uid, string $type, ?int $id, string $action,
			?string $field = null, mixed $old = null, mixed $new = null): void {
			$this->audit[] = [$action, $field, $new];
		});
		return $audit;
	}

	private function rights(): RightsService {
		$rights = $this->createStub(RightsService::class);
		$rights->method('today')->willReturn(self::TODAY);
		return $rights;
	}

	/**
	 * @param array<string, ?string> $denials RightsPolicy method => reason
	 */
	private function policy(array $denials = []): RightsPolicy {
		$policy = $this->createStub(RightsPolicy::class);
		foreach (['viewDenial', 'memberViewDenial', 'memberPlayDenial', 'memberPracticeAudioDenial', 'publicViewDenial', 'publicPlayDenial'] as $method) {
			$policy->method($method)->willReturn($denials[$method] ?? null);
		}
		return $policy;
	}

	/**
	 * @param array<string, ?string> $denials
	 * @param list<string> $off modules switched off
	 */
	private function links(array $denials = [], array $off = [], bool $withScore = true): LinkService {
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($this->copy());
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($this->version());
		$files = $this->createStub(ArchiveFileMapper::class);
		$file = new ArchiveFile();
		$file->setId(4);
		$file->setEtag('e1');
		$files->method('find')->willReturn($file);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('describe')->willReturn($withScore
			? ['fileId' => 4, 'fileName' => '1-03.musicxml', 'canView' => true, 'canPlay' => true, 'reason' => null, 'canExportAudio' => false, 'exportReason' => null]
			: null);
		$scores->method('content')->willReturn(['content' => self::SCORE, 'mime' => 'application/xml', 'name' => '1-03.musicxml', 'type' => 'musicxml']);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (ExcerptLink $link): ExcerptLink {
			$link->setId(9);
			return $link;
		});
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(self::TOKEN);
		return new LinkService($mapper, $copies, $versions, $files, $scores, $this->policy($denials), $this->rights(), $this->auditLog(),
			$this->createStub(Roles::class), $this->createStub(IUserManager::class), $random, $this->createStub(ITimeFactory::class),
			$this->modulesWithout($off));
	}

	// ---- making links -------------------------------------------------------------

	public function testALinkForChoirMembersKeepsItsCircleAndIsRecorded(): void {
		$link = $this->links()->create(35, ['circle' => 'members', 'measure_from' => '2', 'measure_to' => '3', 'voice' => 'Alto',
			'note' => 'Please practise until Thursday.', 'task_id' => '4'], 'conductor');
		self::assertSame(ExcerptLink::CIRCLE_MEMBERS, $link->getCircle());
		self::assertSame('Please practise until Thursday.', $link->getNote(), 'the choir members read the note');
		self::assertNull($link->getTaskId(), 'tasks stay internal');
		self::assertContains(['created', 'circle', 'members'], $this->audit);
	}

	public function testLinksBeyondTheRolesNeedTheirModule(): void {
		foreach (['members' => Modules::MEMBER_LINKS, 'public' => Modules::PUBLIC_LINKS] as $circle => $module) {
			try {
				$this->links([], [$module])->create(35, ['circle' => $circle, 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
				self::fail($circle . ': the module is off');
			} catch (ModuleDisabledException) {
				self::assertTrue(true);
			}
		}
		$internal = $this->links([], [Modules::MEMBER_LINKS, Modules::PUBLIC_LINKS])->create(35, ['voice' => 'Alto'], 'conductor');
		self::assertSame(ExcerptLink::CIRCLE_INTERNAL, $internal->getCircle(), 'internal links stay as they were');
	}

	public function testLinksBeyondTheRolesAreRefusedWithTheNextStep(): void {
		$reason = 'Not released for choir members: the version needs confirmed public domain or a valid license for the singers\' portal. The catalog librarian records it on the tab "Rights".';
		try {
			$this->links(['memberViewDenial' => $reason])->create(35, ['circle' => 'members', 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
			self::fail('not released');
		} catch (AccessDeniedException $e) {
			self::assertSame($reason, $e->getMessage());
		}
		$cases = [
			'no measures' => [['circle' => 'members', 'measure_from' => '1'], 'Please give the first and the last measure: choir members and the public only see these measures.'],
			'a note for everyone' => [['circle' => 'public', 'measure_from' => '1', 'measure_to' => '2', 'note' => 'internal remark'],
				'A public link has no note, because everyone could read it. Please leave the note empty.'],
			'a measure that is not there' => [['circle' => 'public', 'measure_from' => '2', 'measure_to' => '9'], 'There is no measure %s in this score.'],
			'an unknown circle' => [['circle' => 'friends'], '"%s": value not allowed.'],
		];
		foreach ($cases as $case => [$data, $message]) {
			try {
				$this->links()->create(35, $data, 'conductor');
				self::fail($case);
			} catch (InvalidInputException $e) {
				self::assertSame($message, $e->getMessage(), $case);
			}
		}
		try {
			$this->links([], [], false)->create(35, ['circle' => 'members', 'measure_from' => '1', 'measure_to' => '2'], 'conductor');
			self::fail('no MusicXML');
		} catch (InvalidInputException $e) {
			self::assertSame(LinkService::NO_SCORE, $e->getMessage());
		}
	}

	public function testTheDialogLearnsWhatTheRecipientsGet(): void {
		$options = $this->links(['publicPlayDenial' => 'Protected version: playback needs a license for practice audio.'], [Modules::MEMBER_PRACTICE_AUDIO])
			->circleOptions(35, 'conductor');
		self::assertSame(['available' => true, 'allowed' => true, 'reason' => null, 'playback' => true, 'practiceAudio' => false], $options['members'],
			'practice audio for choir members is switched off');
		self::assertSame(['available' => true, 'allowed' => true, 'reason' => 'Protected version: playback needs a license for practice audio.',
			'playback' => false, 'practiceAudio' => false], $options['public']);

		$options = $this->links([], [Modules::PUBLIC_LINKS])->circleOptions(35, 'conductor');
		self::assertTrue($options['members']['practiceAudio']);
		self::assertSame(['available' => false, 'allowed' => false, 'reason' => LinkService::SWITCHED_OFF, 'playback' => false, 'practiceAudio' => false],
			$options['public']);
	}

	// ---- opening links ----------------------------------------------------------------

	private function link(string $circle, array $values = []): ExcerptLink {
		$link = new ExcerptLink();
		$link->setId(9);
		$link->setToken(self::TOKEN);
		$link->setCopyId(35);
		$link->setCircle($circle);
		$link->setMeasureFrom('2');
		$link->setMeasureTo('3');
		$link->setVoice('Alto');
		$link->setNote('Please practise until Thursday.');
		$link->setExpires('2026-12-31');
		foreach ($values as $column => $value) {
			$link->setValue($column, $value);
		}
		return $link;
	}

	/**
	 * @param list<ExcerptLink> $links
	 * @param array<string, ?string> $denials
	 */
	private function shared(array $links, array $denials = [], bool $member = true): SharedLinkService {
		$mapper = $this->createStub(ExcerptLinkMapper::class);
		$mapper->method('findByToken')->willReturnCallback(function (string $token) use ($links): ExcerptLink {
			foreach ($links as $link) {
				if ($link->getToken() === $token) {
					return $link;
				}
			}
			throw new DoesNotExistException('none');
		});
		$mapper->method('findUnrevokedByCircle')->willReturnCallback(fn (string $circle): array => array_values(array_filter($links,
			fn (ExcerptLink $link): bool => $link->getCircle() === $circle && $link->getRevoked() === null)));
		$mapper->method('countHit')->willReturnCallback(function (int $id): void {
			$this->hits[] = $id;
		});
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('find')->willReturn($this->copy());
		$versions = $this->createStub(WorkVersionMapper::class);
		$versions->method('find')->willReturn($this->version());
		$work = new Work();
		$work->setId(7);
		$work->setTitle('Abendlied');
		$work->setComposer('J. Rheinberger');
		$works = $this->createStub(WorkMapper::class);
		$works->method('find')->willReturn($work);
		$file = new ArchiveFile();
		$file->setId(4);
		$file->setFileId(4711);
		$file->setRole('musicxml');
		$file->setType('musicxml');
		$file->setName('1-03.musicxml');
		$files = $this->createStub(ArchiveFileMapper::class);
		$files->method('findByCopy')->willReturn([$file]);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('isScore')->willReturn(true);
		$scores->method('scoreText')->willReturn(['xml' => self::SCORE, 'entry' => null]);
		$node = $this->createStub(File::class);
		$node->method('getSize')->willReturn(strlen(self::SCORE));
		$node->method('fopen')->willReturnCallback(function () {
			$stream = fopen('php://memory', 'r+');
			self::assertIsResource($stream);
			fwrite($stream, self::SCORE);
			rewind($stream);
			return $stream;
		});
		$folder = $this->createStub(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($node);
		$settings = $this->createStub(AppSettings::class);
		$settings->method('archiveInfo')->willReturn(['id' => 1, 'path' => 'Notenarchiv', 'user' => 'archive']);
		$settings->method('archiveFolder')->willReturn($folder);
		$roles = $this->createStub(Roles::class);
		$roles->method('isMember')->willReturn($member);
		return new SharedLinkService($mapper, $copies, $versions, $works, $files, $scores, $this->policy($denials), $this->rights(), $roles,
			$this->modulesWithout(), $settings);
	}

	public function testChoirMembersSeeTheirLinksAndOnlyTheMeasures(): void {
		$links = [$this->link('members'), $this->link('internal', ['token' => str_repeat('i', 32)]),
			$this->link('members', ['token' => str_repeat('e', 32), 'expires' => '2026-10-21']), $this->link('public', ['token' => str_repeat('p', 32)])];
		$shared = $this->shared($links);
		$list = $shared->forMember('singer');
		self::assertCount(1, $list, 'only active links for choir members');
		self::assertSame(['token' => self::TOKEN, 'title' => 'Abendlied', 'composer' => 'J. Rheinberger', 'measure_from' => '2', 'measure_to' => '3',
			'voice' => 'Alto', 'verse' => null, 'tempo' => null, 'note' => 'Please practise until Thursday.', 'expires' => '2026-12-31',
			'canPlay' => true, 'canExportAudio' => true, 'reason' => null], $list[0]);

		$xml = $shared->memberScore(self::TOKEN, 'singer');
		self::assertStringContainsString('<measure number="2"', $xml);
		self::assertStringContainsString('<measure number="3"', $xml);
		self::assertStringNotContainsString('<measure number="1"', $xml, 'never the whole score');
		self::assertSame([9], $this->hits, 'the call is counted');

		$this->expectException(UnknownLinkException::class);
		$shared->memberScore(str_repeat('i', 32), 'singer');
	}

	public function testOthersAndWithdrawnLinksGetNothing(): void {
		try {
			$this->shared([$this->link('members')], [], false)->forMember('reader');
			self::fail('no choir member');
		} catch (AccessDeniedException) {
			self::assertTrue(true);
		}
		$shared = $this->shared([$this->link('members')], ['memberViewDenial' => 'Public domain is not confirmed yet.']);
		self::assertSame([], $shared->forMember('singer'), 'no longer released: not listed');
		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('This excerpt is no longer shared with choir members.');
		$shared->memberScore(self::TOKEN, 'singer');
	}

	public function testAPublicLinkShowsOnlyTheExcerptAndNothingInternal(): void {
		$excerpt = $this->shared([$this->link('public')])->publicExcerpt(self::TOKEN);
		self::assertNotNull($excerpt);
		self::assertSame(['title', 'composer', 'measureFrom', 'measureTo', 'voice', 'verse', 'tempo', 'canPlay', 'xml'], array_keys($excerpt),
			'no note, no shelf mark, no account');
		self::assertStringNotContainsString('<measure number="1"', $excerpt['xml']);
		self::assertSame([9], $this->hits);

		$refused = [
			'unknown' => [[], self::TOKEN],
			'for choir members' => [[$this->link('members')], self::TOKEN],
			'revoked' => [[$this->link('public', ['revoked' => 1700000000])], self::TOKEN],
			'expired' => [[$this->link('public', ['expires' => '2026-10-21'])], self::TOKEN],
		];
		foreach ($refused as $case => [$links, $token]) {
			self::assertNull($this->shared($links)->publicExcerpt($token), $case);
		}
		self::assertNull($this->shared([$this->link('public')], ['publicViewDenial' => 'Not released for publication.'])->publicExcerpt(self::TOKEN),
			'no longer released');
		self::assertSame([9], $this->hits, 'refused calls are not counted');
	}

	public function testChoirMembersMakePracticeAudioOfTheirExcerptOnly(): void {
		$service = new PracticeAudioService($this->createStub(WorkVersionMapper::class), $this->createStub(CopyMapper::class), $this->policy(),
			$this->auditLog(), $this->createStub(Roles::class), $this->shared([$this->link('members')]), $this->rights());
		$service->authorizeForMember(self::TOKEN, ['voice' => 'Alto', 'mode' => 'solo', 'format' => 'wav', 'tempo' => 80], 'singer');
		$entry = $this->audit[0] ?? null;
		self::assertSame(PracticeAudioService::ACTION, $entry[0] ?? null);
		self::assertSame(['copy' => '1-3', 'measures' => '2-3', 'voice' => 'Alto', 'mode' => 'solo', 'format' => 'wav', 'tempo' => 80, 'repeats' => true],
			$entry[2] ?? null, 'the measures of the link, whatever was asked');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('Unknown field: %s');
		$service->authorizeForMember(self::TOKEN, ['format' => 'wav', 'measure_from' => '1'], 'singer');
	}

	// ---- the public repertoire page ----------------------------------------------------

	public function testTheRepertoirePageShowsChosenPublicDomainWorksOnly(): void {
		$works = [];
		foreach ([7 => ['Abendlied', true], 8 => ['Tischlied', true], 9 => ['Ave verum corpus', false]] as $id => [$title, $listed]) {
			$work = new Work();
			$work->setId($id);
			$work->setTitle($title);
			$work->setComposer('Composer ' . $id);
			$work->setPublicRepertoire($listed);
			$work->setCpdlUrl('https://www.cpdl.org/wiki/index.php/' . $id);
			$work->setImslpUrl('javascript:alert(1)');
			$works[$id] = $work;
		}
		$versions = [
			3 => $this->version(['work_id' => 7, 'rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01', 'scoring' => 'SATB']),
			4 => $this->version(['work_id' => 8, 'rights_status' => 'public_domain']),
			5 => $this->version(['work_id' => 9, 'rights_status' => 'public_domain', 'confirmed_at' => '2026-01-01']),
		];
		foreach ($versions as $id => $version) {
			$version->setId($id);
		}
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$contribution = new Contribution();
		$contribution->setPersonId(1);
		$contribution->setRole('composer');
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findAllByVersion')->willReturn([3 => [$contribution]]);
		$person = new Person();
		$person->setId(1);
		$person->setName('Josef Rheinberger');
		$person->setBorn('1839-03-17');
		$person->setBornPrecision('exact');
		$person->setDied('1901');
		$person->setDiedPrecision('year');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAllById')->willReturn([1 => $person]);

		$entries = (new PublicRepertoireService($workMapper, $versionMapper, $contributions, $persons, $this->modulesWithout()))->entries();
		self::assertSame([[
			'title' => 'Abendlied',
			'composer' => 'Composer 7',
			'persons' => [['role' => 'composer', 'name' => 'Josef Rheinberger', 'lifeDates' => '1839–1901']],
			'scoring' => ['SATB'],
			'cpdl' => 'https://www.cpdl.org/wiki/index.php/7',
			'imslp' => null,
		]], $entries, 'chosen and confirmed public domain; unsafe links are left out');

		$withoutRegister = (new PublicRepertoireService($workMapper, $versionMapper, $contributions, $persons, $this->modulesWithout(['persons'])))->entries();
		self::assertSame([], $withoutRegister[0]['persons'] ?? null, 'without the register of persons: the composer as written');
	}

	public function testLinksToCpdlAndImslpMustBeHttpsToTheirSite(): void {
		$hosts = ['imslp.org', 'www.imslp.org'];
		self::assertTrue(FieldValue::isLinkTo('https://imslp.org/wiki/Ave_verum_corpus,_K.618_(Mozart,_Wolfgang_Amadeus)', $hosts));
		self::assertTrue(FieldValue::isLinkTo('https://IMSLP.org', $hosts));
		foreach (['http://imslp.org/wiki/X', 'https://imslp.org.example.com/wiki/X', 'https://user@imslp.org/wiki/X', 'https://imslp.org:8443/wiki/X',
			'javascript:alert(1)', 'https://imslp.org/wiki/X Y', "https://imslp.org/wiki/X\n", '//imslp.org/wiki/X'] as $value) {
			self::assertFalse(FieldValue::isLinkTo($value, $hosts), $value);
		}
		$info = ['type' => 'text', 'label' => 'Link to IMSLP', 'write' => ['librarian'], 'max' => 500, 'hosts' => $hosts];
		self::assertNull(FieldValue::check($info, 'https://imslp.org/wiki/X'));
		self::assertSame('"%s" must be an https link to the page of the work on cpdl.org or imslp.org.', FieldValue::check($info, 'https://example.com/'));
	}

	public function testTheAuditLogNamesTheRoleOfAChoirMember(): void {
		$entries = [];
		$mapper = $this->createStub(AuditEntryMapper::class);
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry) use (&$entries): AuditEntry {
			$entries[] = $entry;
			return $entry;
		});
		$roles = $this->createStub(Roles::class);
		$roles->method('rolesOf')->willReturn([]);
		$roles->method('isMember')->willReturnCallback(fn (string $uid): bool => $uid === 'singer');
		$log = new AuditLog($mapper, $this->createStub(IUserManager::class), $roles, $this->createStub(ITimeFactory::class));
		$log->record('op', 'singer', 'version', 3, PracticeAudioService::ACTION);
		$log->record('op', 'stranger', 'version', 3, PracticeAudioService::ACTION);
		self::assertSame(['member', ''], array_map(fn (AuditEntry $entry): ?string => $entry->getRoles(), $entries));
	}
}
