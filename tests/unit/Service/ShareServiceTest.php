<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Service;

use OCA\OrchestraScoresManager\Db\FolderCollection;
use OCA\OrchestraScoresManager\Db\FolderCollectionMapper;
use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreBookScoreLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreFolderCollectionLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Db\Share;
use OCA\OrchestraScoresManager\Db\ShareMapper;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCA\OrchestraScoresManager\Service\MusicXmlService;
use OCA\OrchestraScoresManager\Service\PdfService;
use OCA\OrchestraScoresManager\Service\PermissionService;
use OCA\OrchestraScoresManager\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShareService.
 */
final class ShareServiceTest extends TestCase {
	private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

	private ShareMapper $shareMapper;
	private ScoreMapper $scoreMapper;
	private FolderCollectionMapper $folderCollectionMapper;
	private ScoreFolderCollectionLinkMapper $linkMapper;
	private ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper;
	private PermissionService $permissionService;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;
	private MusicXmlService $musicXmlService;
	private PdfService $pdfService;
	private ShareService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->folderCollectionMapper = $this->createMock(FolderCollectionMapper::class);
		$this->linkMapper = $this->createMock(ScoreFolderCollectionLinkMapper::class);
		$this->scoreBookScoreLinkMapper = $this->createMock(ScoreBookScoreLinkMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->musicXmlService = $this->createMock(MusicXmlService::class);
		$this->pdfService = $this->createMock(PdfService::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1700000000);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new ShareService(
			$this->shareMapper,
			$this->scoreMapper,
			$this->folderCollectionMapper,
			$this->linkMapper,
			$this->scoreBookScoreLinkMapper,
			$this->permissionService,
			$this->groupManager,
			$this->userSession,
			$this->secureRandom,
			$timeFactory,
			$this->musicXmlService,
			$this->pdfService,
			$l10n,
		);
	}

	private function makeScore(int $id, string $title, string $rightsStatus = 'public_domain', string $publicationStatus = 'free'): Score {
		$score = new Score();
		$score->setId($id);
		$score->setTitle($title);
		$score->setRightsStatus($rightsStatus);
		$score->setPublicationStatus($publicationStatus);
		$score->setDefects('internal');
		return $score;
	}

	private function makeShare(string $type, string $targetType, int $targetId, ?string $groupId = null): Share {
		$share = new Share();
		$share->setId(5);
		$share->setShareType($type);
		$share->setTargetType($targetType);
		$share->setTargetId($targetId);
		$share->setGroupId($groupId);
		$share->setToken($type === 'link' ? self::TOKEN : null);
		$share->setCreatedBy('admin');
		$share->setCreatedAt(1700000000);
		return $share;
	}

	public function testCreateShareRequiresEditPermission(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(false);
		$this->shareMapper->expects($this->never())->method('insert');

		$this->expectException(PermissionDeniedException::class);
		$this->service->createShare('link', 'score', 1);
	}

	public function testCreateLinkShareGeneratesRandomToken(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);
		$this->scoreMapper->method('findMultipleWithoutTransients')->with([1], false)->willReturn([$this->makeScore(1, 'Ave')]);
		$this->secureRandom->expects($this->once())
			->method('generate')
			->with(32, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn(self::TOKEN);
		$this->shareMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Share $share): Share {
				$share->setId(7);
				return $share;
			});

		$result = $this->service->createShare('link', 'score', 1, null, '  Konzert  ');

		$this->assertSame(7, $result['id']);
		$this->assertSame('link', $result['shareType']);
		$this->assertSame(self::TOKEN, $result['token']);
		$this->assertNull($result['groupId']);
		$this->assertSame('Konzert', $result['label']);
	}

	public function testCreateGroupShareRejectsUnknownGroup(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);
		$this->scoreMapper->method('findMultipleWithoutTransients')->willReturn([$this->makeScore(1, 'Ave')]);
		$this->groupManager->method('groupExists')->with('nope')->willReturn(false);
		$this->shareMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Group not found');
		$this->service->createShare('group', 'score', 1, 'nope');
	}

	public function testCreateGroupShareRejectsDuplicate(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);
		$this->scoreMapper->method('findMultipleWithoutTransients')->willReturn([$this->makeScore(1, 'Ave')]);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->shareMapper->method('findForTarget')->willReturn([$this->makeShare('group', 'score', 1, 'tenors')]);
		$this->shareMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createShare('group', 'score', 1, 'tenors');
	}

	public function testCreateShareRejectsMissingTarget(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);
		$this->folderCollectionMapper->method('find')->willThrowException(new DoesNotExistException(''));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createShare('link', 'folder_collection', 99);
	}

	public function testCreateShareRejectsInvalidTypes(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createShare('everyone', 'score', 1);
	}

	public function testGetLinkShareOnlyQueriesFreeScoresAndWhitelistsFields(): void {
		$this->shareMapper->method('findLinkShareByToken')->with(self::TOKEN)->willReturn($this->makeShare('link', 'score', 1));
		$statuses = [];
		$this->scoreMapper->method('findMultipleWithoutTransients')
			->willReturnCallback(function (array $ids, bool $publicOnly) use (&$statuses): array {
				$statuses[] = $publicOnly;
				return [$this->makeScore(1, 'Ave')];
			});

		$result = $this->service->getLinkShare(self::TOKEN);

		// The score list itself must be filtered to public scores in the query
		$this->assertContains(true, $statuses);
		$this->assertSame('Ave', $result['title']);
		$this->assertSame([[
			'id' => 1,
			'title' => 'Ave',
			'composer' => null,
			'arranger' => null,
			'year' => null,
			'duration' => null,
			'index' => null,
			'hasMusicXml' => false,
			'hasPdf' => false,
		]], $result['scores']);
	}

	public function testGetLinkShareFiltersNonFreeScoresInSql(): void {
		$fc = new FolderCollection();
		$fc->setId(3);
		$fc->setTitle('Weihnachten');
		$fc->setActiveVersionId(30);
		$this->shareMapper->method('findLinkShareByToken')->willReturn($this->makeShare('link', 'folder_collection', 3));
		$this->folderCollectionMapper->method('find')->with(3)->willReturn($fc);
		$this->linkMapper->method('findScoresForVersion')->with(30)->willReturn([
			['id' => 1, 'index' => 2],
			['id' => 2, 'index' => 1],
		]);
		$this->linkMapper->method('findScoreBooksForVersion')->willReturn([['id' => 9, 'index' => 3]]);
		$this->scoreBookScoreLinkMapper->method('findScoresForScoreBook')->with(9)->willReturn([['score_id' => 4, 'index' => 1]]);

		$this->scoreMapper->expects($this->once())
			->method('findMultipleWithoutTransients')
			->with([1, 2, 4], true)
			->willReturn([$this->makeScore(4, 'Stille Nacht'), $this->makeScore(1, 'O du fröhliche')]);

		$result = $this->service->getLinkShare(self::TOKEN);

		$this->assertSame('Weihnachten', $result['title']);
		$this->assertSame(['2', '3.1'], array_column($result['scores'], 'index'));
		$this->assertSame([1, 4], array_column($result['scores'], 'id'));
	}

	public function testGetLinkShareRejectsMalformedTokenWithoutQuery(): void {
		$this->shareMapper->expects($this->never())->method('findLinkShareByToken');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getLinkShare('../etc/passwd');
	}

	public function testGetLinkShareRejectsUnknownToken(): void {
		$this->shareMapper->method('findLinkShareByToken')->willThrowException(new DoesNotExistException(''));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getLinkShare(self::TOKEN);
	}

	public function testGetLinkShareFileRejectsScoreOutsideShare(): void {
		$this->shareMapper->method('findLinkShareByToken')->willReturn($this->makeShare('link', 'score', 1));
		$this->scoreMapper->expects($this->never())->method('findMultipleWithoutTransients');
		$this->pdfService->expects($this->never())->method('readFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getLinkShareFile(self::TOKEN, 2, 'pdf');
	}

	public function testGetLinkShareFileRejectsNonFreeScore(): void {
		$this->shareMapper->method('findLinkShareByToken')->willReturn($this->makeShare('link', 'score', 1));
		$this->scoreMapper->method('findMultipleWithoutTransients')->with([1], true)->willReturn([]);
		$this->pdfService->expects($this->never())->method('readFile');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->getLinkShareFile(self::TOKEN, 1, 'pdf');
	}

	public function testGetLinkShareFileReadsFileOfFreeScore(): void {
		$score = $this->makeScore(1, 'Ave');
		$file = ['content' => '%PDF-1.4', 'mimeType' => 'application/pdf', 'fileName' => 'score-1.pdf'];
		$this->shareMapper->method('findLinkShareByToken')->willReturn($this->makeShare('link', 'score', 1));
		$this->scoreMapper->method('findMultipleWithoutTransients')->with([1], true)->willReturn([$score]);
		$this->pdfService->expects($this->once())->method('readFile')->with($score)->willReturn($file);

		$this->assertSame($file, $this->service->getLinkShareFile(self::TOKEN, 1, 'pdf'));
	}

	public function testGetSharedWithCurrentUserIncludesAllScoresAndDeduplicates(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn(['tenors', 'basses']);
		$this->shareMapper->expects($this->once())
			->method('findGroupSharesForGroups')
			->with(['tenors', 'basses'])
			->willReturn([
				$this->makeShare('group', 'score', 1, 'tenors'),
				$this->makeShare('group', 'score', 1, 'basses'),
			]);
		$this->scoreMapper->method('findMultipleWithoutTransients')
			->willReturnCallback(function (array $ids, bool $publicOnly): array {
				$this->assertFalse($publicOnly);
				return [$this->makeScore(1, 'Geschützt', 'protected', 'blocked')];
			});

		$result = $this->service->getSharedWithCurrentUser();

		$this->assertCount(1, $result);
		$this->assertSame('Geschützt', $result[0]['scores'][0]['title']);
	}

	public function testGetSharedWithCurrentUserHidesFilesOfInternalCopiesWithoutAccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['tenors']);
		$this->shareMapper->method('findGroupSharesForGroups')->willReturn([$this->makeShare('group', 'score', 1, 'tenors')]);
		$copy = $this->makeScore(1, 'Erhaltungskopie', 'protected', 'internal_copy');
		$copy->setMusicxmlFile('copy.musicxml');
		$copy->setPdfFile('copy.pdf');
		$this->scoreMapper->method('findMultipleWithoutTransients')->willReturn([$copy]);
		$this->permissionService->method('canAccessFiles')->with($copy)->willReturn(false);

		$score = $this->service->getSharedWithCurrentUser()[0]['scores'][0];

		$this->assertFalse($score['hasMusicXml']);
		$this->assertFalse($score['hasPdf']);
	}

	public function testGetSharedWithCurrentUserRequiresLogin(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(PermissionDeniedException::class);
		$this->service->getSharedWithCurrentUser();
	}

	public function testGetAllSharesIncludesTargetTitles(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(true);
		$fc = new FolderCollection();
		$fc->setId(3);
		$fc->setTitle('Weihnachten');
		$this->folderCollectionMapper->method('find')->with(3)->willReturn($fc);
		$this->shareMapper->method('findAll')->willReturn([$this->makeShare('link', 'folder_collection', 3)]);

		$result = $this->service->getAllShares();

		$this->assertSame('folder_collection', $result[0]['targetType']);
		$this->assertSame(3, $result[0]['targetId']);
		$this->assertSame('Weihnachten', $result[0]['targetTitle']);
	}

	public function testGetAllSharesRequiresEditPermission(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(false);

		$this->expectException(PermissionDeniedException::class);
		$this->service->getAllShares();
	}

	public function testDeleteShareRequiresEditPermission(): void {
		$this->permissionService->method('canCurrentUserEdit')->willReturn(false);
		$this->shareMapper->expects($this->never())->method('delete');

		$this->expectException(PermissionDeniedException::class);
		$this->service->deleteShare(5);
	}
}
