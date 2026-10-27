<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Db\ArchiveFile;
use OCA\Notenarchiv\Db\ArchiveFileMapper;
use OCA\Notenarchiv\Db\Proposal;
use OCA\Notenarchiv\Db\ProposalMapper;
use OCA\Notenarchiv\Db\WorkVersion;
use OCA\Notenarchiv\Service\ProposalService;
use OCA\Notenarchiv\Service\RightsNoticeService;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rights notice of a MusicXML file: read from the header only, shown as
 * unchecked information, and at most a proposal (guideline 1).
 */
#[CoversClass(RightsNoticeService::class)]
final class RightsNoticeServiceTest extends TestCase {
	use ModuleSwitches;

	private static function score(string $identification, string $doctype = ''): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . $doctype
			. '<score-partwise version="4.0"><work><work-title>T</work-title></work>'
			. '<identification><creator type="composer">C</creator>' . $identification . '</identification>'
			. '<part-list><score-part id="P1"><part-name>S</part-name></score-part></part-list>'
			. '<part id="P1"><measure number="1"><credit><credit-words>x</credit-words></credit></measure></part></score-partwise>';
	}

	public function testTheNoticeIsReadFromTheIdentification(): void {
		self::assertSame('Public Domain', RightsNoticeService::extract(self::score('<rights>Public Domain</rights>')));
		self::assertSame('Music: CC BY 4.0 · Words: public domain', RightsNoticeService::extract(
			self::score("<rights type=\"music\">Music:\n  CC BY 4.0</rights><rights type=\"words\">Words: public domain</rights>")));
		self::assertNull(RightsNoticeService::extract(self::score('')));
		self::assertNull(RightsNoticeService::extract(self::score('<rights>  </rights>')));
		self::assertNull(RightsNoticeService::extract('no xml at all'));
	}

	public function testTheDoctypeOfMusicXmlIsAcceptedButNoInternalSubset(): void {
		$doctype = '<!DOCTYPE score-partwise PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">';
		self::assertSame('© 2020 Publisher', RightsNoticeService::extract(self::score('<rights>© 2020 Publisher</rights>', $doctype)));
		$subset = '<!DOCTYPE score-partwise [<!ENTITY a "public domain">]>';
		self::assertNull(RightsNoticeService::extract(self::score('<rights>&a;</rights>', $subset)), 'no entities are expanded');
		$prolog = '<!-- ' . str_repeat('padding ', 1000) . '-->';
		self::assertNull(RightsNoticeService::extract(self::score('<rights>Notice &a;</rights>', $prolog . $subset)),
			'an internal subset after a long prolog is refused as well');
	}

	public function testOnlyTheHeaderCounts(): void {
		$xml = '<score-partwise><part-list/><identification><rights>late</rights></identification></score-partwise>';
		self::assertNull(RightsNoticeService::extract($xml));
	}

	public function testLongNoticesAreCut(): void {
		$notice = RightsNoticeService::extract(self::score('<rights>' . str_repeat('ä', 1500) . '</rights>'));
		self::assertSame(RightsNoticeService::MAX_LENGTH, mb_strlen((string)$notice));
	}

	/**
	 * @return iterable<string, array{0: string, 1: ?string}>
	 */
	public static function notices(): iterable {
		yield 'public domain' => ['Public Domain', RightsNoticeService::PUBLIC_DOMAIN];
		yield 'german' => ['Melodie und Text gemeinfrei. Satz: CC0 1.0', RightsNoticeService::PUBLIC_DOMAIN];
		yield 'cc0' => ['CC0', RightsNoticeService::PUBLIC_DOMAIN];
		yield 'cc by' => ['Licensed under CC BY-SA 4.0', RightsNoticeService::LICENSE];
		yield 'creative commons' => ['Creative Commons Attribution', RightsNoticeService::LICENSE];
		yield 'lizenz' => ['Lizenz: Verlag XY', RightsNoticeService::LICENSE];
		yield 'copyright only' => ['© 1998 Edition XY. All rights reserved.', null];
		yield 'nothing' => ['Transcribed by the choir', null];
	}

	#[DataProvider('notices')]
	public function testClassify(string $notice, ?string $expected): void {
		self::assertSame($expected, RightsNoticeService::classify($notice));
	}

	public function testANoticeBecomesAtMostAProposal(): void {
		$version = new WorkVersion();
		$version->setId(7);
		$version->setRightsStatus('protected');
		$file = new ArchiveFile();
		$file->setId(3);
		$file->setName('3-1_Mond.mxl');
		$file->setPath('Mappe_3/3-1_Mond.mxl');
		$node = $this->createStub(File::class);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('scoreText')->willReturn(['xml' => self::score('<rights>Public domain</rights>'), 'entry' => null]);
		$node->method('getSize')->willReturn(100);
		$node->method('fopen')->willReturnCallback(function () {
			$handle = fopen('php://memory', 'r+');
			self::assertNotFalse($handle);
			fwrite($handle, 'x');
			rewind($handle);
			return $handle;
		});
		$files = $this->createMock(ArchiveFileMapper::class);
		$files->expects(self::exactly(2))->method('update')->willReturnArgument(0);
		$proposals = $this->createMock(ProposalService::class);
		$proposals->expects(self::once())->method('propose')
			->with('version', 7, ['rights_status' => 'public_domain'], 'lena', self::anything(), self::anything())
			->willReturn([]);
		$open = $this->createStub(ProposalMapper::class);
		$open->method('findOpenForObject')->willReturn([]);
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$service = new RightsNoticeService($scores, $files, $proposals, $open, $this->modulesWithout(), $this->createStub(ITimeFactory::class),
			$l, $this->createStub(LoggerInterface::class));

		$counts = $service->readAll([['file' => $file, 'node' => $node, 'version' => $version]], 'lena');
		self::assertSame(['read' => 1, 'found' => 1, 'proposals' => 1, 'failed' => 0, 'pending' => 0, 'switchedOff' => false], $counts);
		self::assertSame('Public domain', $file->getRightsNotice());
		self::assertSame('protected', $version->getRightsStatus(), 'the rights status is never set');

		// The same open proposal is not made twice.
		$existing = new Proposal();
		$existing->setField('rights_status');
		$existing->setNewValue('"public_domain"');
		$open = $this->createStub(ProposalMapper::class);
		$open->method('findOpenForObject')->willReturn([$existing]);
		$service = new RightsNoticeService($scores, $files, $proposals, $open, $this->modulesWithout(), $this->createStub(ITimeFactory::class),
			$l, $this->createStub(LoggerInterface::class));
		$file->setRightsReadAt(null);
		self::assertSame(0, $service->readAll([['file' => $file, 'node' => $node, 'version' => $version]], 'lena')['proposals']);
	}

	public function testWithoutTheInboxThereIsNoProposal(): void {
		$version = new WorkVersion();
		$version->setId(7);
		$file = new ArchiveFile();
		$node = $this->createStub(File::class);
		$node->method('getSize')->willReturn(1);
		$node->method('fopen')->willReturnCallback(fn () => fopen('php://memory', 'r'));
		$scores = $this->createStub(ScoreService::class);
		$scores->method('scoreText')->willReturn(['xml' => self::score('<rights>CC BY 4.0</rights>'), 'entry' => null]);
		$proposals = $this->createMock(ProposalService::class);
		$proposals->expects(self::never())->method('propose');
		$service = new RightsNoticeService($scores, $this->createStub(ArchiveFileMapper::class), $proposals, $this->createStub(ProposalMapper::class),
			$this->modulesWithout(['proposals']), $this->createStub(ITimeFactory::class), $this->createStub(IL10N::class),
			$this->createStub(LoggerInterface::class));
		$counts = $service->readAll([['file' => $file, 'node' => $node, 'version' => $version]], 'lena');
		self::assertSame(1, $counts['found']);
		self::assertSame(0, $counts['proposals']);
		self::assertSame('CC BY 4.0', $file->getRightsNotice());
	}
}
