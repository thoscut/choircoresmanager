<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service;

use OCA\ChoirScores\Db\CommentMapper;
use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreBookScoreLinkMapper;
use OCA\ChoirScores\Db\ScoreFolderCollectionLinkMapper;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Db\ScoreTagLinkMapper;
use OCA\ChoirScores\Db\ShareMapper;
use OCA\ChoirScores\Db\Tag;
use OCA\ChoirScores\Db\TagMapper;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\Service\AuthorizationService;
use OCA\ChoirScores\Service\Exceptions\PermissionDeniedException;
use OCA\ChoirScores\Service\ScoreService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScoreService.
 */
final class ScoreServiceTest extends TestCase {
	private CommentMapper $commentMapper;
	private ScoreMapper $scoreMapper;
	private TagMapper $tagMapper;
	private ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper;
	private ScoreFolderCollectionLinkMapper $scoreFolderCollectionLinkMapper;
	private ScoreTagLinkMapper $scoreTagLinkMapper;
	private AuthorizationService $authorizationService;
	private ScorePolicy $scorePolicy;
	private IL10N $l10n;
	private ShareMapper $shareMapper;
	private ScoreService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->scoreMapper = $this->createMock(ScoreMapper::class);
		$this->tagMapper = $this->createMock(TagMapper::class);
		$this->scoreBookScoreLinkMapper = $this->createMock(ScoreBookScoreLinkMapper::class);
		$this->scoreFolderCollectionLinkMapper = $this->createMock(ScoreFolderCollectionLinkMapper::class);
		$this->scoreTagLinkMapper = $this->createMock(ScoreTagLinkMapper::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->scorePolicy = $this->createMock(ScorePolicy::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->shareMapper = $this->createMock(ShareMapper::class);

		$this->l10n->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->service = new ScoreService(
			$this->commentMapper,
			$this->scoreMapper,
			$this->tagMapper,
			$this->scoreBookScoreLinkMapper,
			$this->scoreFolderCollectionLinkMapper,
			$this->scoreTagLinkMapper,
			$this->authorizationService,
			$this->scorePolicy,
			$this->l10n,
			$this->createMock(IDBConnection::class),
			$this->shareMapper,
		);
	}

	public function testGetScoreByIdRequiresAuthorization(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($score);

		$result = $this->service->getScoreById(1);

		$this->assertSame($score, $result);
	}

	public function testGetScoreByIdThrowsWhenNotFound(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Score not found');

		$this->service->getScoreById(999);
	}

	public function testGetScoreByIdThrowsWhenNotAuthorized(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Not authorized'));

		$this->scoreMapper->expects($this->never())
			->method('find');

		$this->expectException(PermissionDeniedException::class);

		$this->service->getScoreById(1);
	}

	public function testGetAllScoresRequiresAuthorization(): void {
		$scores = [new Score(), new Score()];

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ);

		$this->scoreMapper->expects($this->once())
			->method('findAll')
			->willReturn($scores);

		$result = $this->service->getAllScores();

		$this->assertSame($scores, $result);
	}

	public function testGetScoresByIdsRequiresAuthorization(): void {
		$score1 = new Score();
		$score1->setId(1);
		$score2 = new Score();
		$score2->setId(3);
		$scores = [$score1, $score2];

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ);

		$this->scoreMapper->expects($this->once())
			->method('findMultiple')
			->with([1, 3])
			->willReturn($scores);

		$result = $this->service->getScoresByIds([1, 3]);

		$this->assertSame($scores, $result);
	}

	public function testGetScoresByIdsWithEmptyArray(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_READ);

		$this->scoreMapper->expects($this->once())
			->method('findMultiple')
			->with([])
			->willReturn([]);

		$result = $this->service->getScoresByIds([]);

		$this->assertSame([], $result);
	}

	public function testGetScoresByIdsThrowsWhenNotAuthorized(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->willThrowException(new PermissionDeniedException('Not authorized'));

		$this->scoreMapper->expects($this->never())
			->method('findMultiple');

		$this->expectException(PermissionDeniedException::class);

		$this->service->getScoresByIds([1, 2]);
	}

	public function testDeleteScoreByIdRequiresAuthorization(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_DELETE);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($score);

		$this->scoreFolderCollectionLinkMapper->expects($this->once())
			->method('findVersionsForScore')
			->with(1)
			->willReturn([]);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('removeScoreFromAllBooks')
			->with(1);

		$this->scoreTagLinkMapper->expects($this->once())
			->method('deleteAllTagsForScore')
			->with(1);

		$this->commentMapper->expects($this->once())
			->method('deleteByScoreId')
			->with(1);

		$this->scoreMapper->expects($this->once())
			->method('delete')
			->with($score);

		$this->shareMapper->expects($this->once())
			->method('deleteForTarget')
			->with('score', 1);

		$this->service->deleteScoreById(1);
	}

	public function testDeleteScoreByIdThrowsWhenScoreInFolderCollection(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($score);

		$this->scoreFolderCollectionLinkMapper->expects($this->once())
			->method('findVersionsForScore')
			->willReturn([['versionId' => 1]]);

		$this->scoreMapper->expects($this->never())
			->method('delete');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot delete score because it is part of a folder collection');

		$this->service->deleteScoreById(1);
	}

	public function testCreateScoreRequiresAuthorization(): void {
		$score = new Score();
		$score->setTitle('Test Score');
		$createdScore = clone $score;
		$createdScore->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_CREATE);

		$this->scoreMapper->expects($this->once())
			->method('insert')
			->with($score)
			->willReturn($createdScore);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($createdScore);

		$result = $this->service->createScore($score);

		$this->assertSame($createdScore, $result);
	}

	#[TestWith(['rightsStatus', 'free'])]
	#[TestWith(['rightsStatus', ''])]
	#[TestWith(['publicationStatus', 'published'])]
	#[TestWith(['publicationStatus', ''])]
	public function testCreateScoreRejectsInvalidStatus(string $field, string $value): void {
		$score = new Score();
		$score->setTitle('Test Score');
		$score->{'set' . ucfirst($field)}($value);

		$this->scoreMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid');
		$this->service->createScore($score);
	}

	public function testUpdateScoreAcceptsRightsAndPublicationFields(): void {
		$score = new Score();
		$score->setId(1);
		$score->setRightsStatus('licensed');
		$score->setRightsConfirmedAt(new \DateTimeImmutable('2026-10-20'));
		$score->setRightsConfirmedBy(' TS ');
		$score->setPublicationStatus('blocked');
		$score->setPublicationBlockedUntil(new \DateTimeImmutable('2027-01-31'));

		$this->scoreMapper->expects($this->once())->method('update')->with($score);
		$this->scoreMapper->method('find')->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame('licensed', $result->getRightsStatus());
		$this->assertSame('2026-10-20', $result->getRightsConfirmedAt()?->format('Y-m-d'));
		$this->assertSame('TS', $result->getRightsConfirmedBy());
		$this->assertSame('2027-01-31', $result->getPublicationBlockedUntil()?->format('Y-m-d'));
	}

	public function testUpdateScoreRejectsTooLongRightsConfirmation(): void {
		$score = new Score();
		$score->setId(1);
		$score->setRightsConfirmedBy(str_repeat('ä', ScoreService::RIGHTS_CONFIRMED_BY_MAX_LENGTH + 1));

		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateScore($score);
	}

	public function testUpdateScoreAcceptsRightsConfirmationOfMaximumLength(): void {
		$score = new Score();
		$score->setId(1);
		$score->setRightsConfirmedBy(str_repeat('ä', ScoreService::RIGHTS_CONFIRMED_BY_MAX_LENGTH));
		$this->scoreMapper->method('find')->willReturn($score);

		$this->assertSame(
			str_repeat('ä', ScoreService::RIGHTS_CONFIRMED_BY_MAX_LENGTH),
			$this->service->updateScore($score)->getRightsConfirmedBy(),
		);
	}

	public function testUpdateScoreTurnsBlankRightsConfirmationIntoNull(): void {
		$score = new Score();
		$score->setId(1);
		$score->setRightsConfirmedBy('   ');
		$this->scoreMapper->method('find')->willReturn($score);

		$this->assertNull($this->service->updateScore($score)->getRightsConfirmedBy());
	}

	public function testUpdateScoreClearsRightsConfirmationWhenRightsStatusChanges(): void {
		$score = $this->createLoadedScore();
		$score->setRightsStatus('protected');
		$this->scoreMapper->method('find')->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame('protected', $result->getRightsStatus());
		$this->assertNull($result->getRightsConfirmedAt());
		$this->assertNull($result->getRightsConfirmedBy());
	}

	public function testUpdateScoreKeepsRightsConfirmationSetTogetherWithStatus(): void {
		$score = $this->createLoadedScore();
		$score->setRightsStatus('licensed');
		$score->setRightsConfirmedAt(new \DateTimeImmutable('2026-10-21'));
		$this->scoreMapper->method('find')->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame('2026-10-21', $result->getRightsConfirmedAt()?->format('Y-m-d'));
		$this->assertSame('TS', $result->getRightsConfirmedBy());
	}

	public function testUpdateScoreKeepsRightsConfirmationWhenOtherFieldsChange(): void {
		$score = $this->createLoadedScore();
		$score->setRightsNote('Composer died 1791');
		$score->setPublicationStatus('blocked');
		$score->setPublicationNote('Waiting for the publisher');
		$this->scoreMapper->method('find')->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame('2026-10-01', $result->getRightsConfirmedAt()?->format('Y-m-d'));
		$this->assertSame('TS', $result->getRightsConfirmedBy());
		$this->assertSame('2027-01-31', $result->getPublicationBlockedUntil()?->format('Y-m-d'));
	}

	public function testUpdateScoreClearsBlockedUntilWhenNoLongerBlocked(): void {
		$score = $this->createLoadedScore();
		$score->setPublicationStatus('approved');
		$this->scoreMapper->method('find')->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame('approved', $result->getPublicationStatus());
		$this->assertNull($result->getPublicationBlockedUntil());
	}

	public function testUpdateScoreRejectsBlockedUntilForScoreThatIsNotBlocked(): void {
		$score = $this->createLoadedScore();
		$score->setPublicationStatus('approved');
		$score->setPublicationBlockedUntil(new \DateTimeImmutable('2027-01-31'));

		$this->scoreMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('blocked until');
		$this->service->updateScore($score);
	}

	public function testCreateScoreRejectsBlockedUntilForScoreThatIsNotBlocked(): void {
		$score = new Score();
		$score->setTitle('Test Score');
		$score->setPublicationBlockedUntil(new \DateTimeImmutable('2027-01-31'));

		$this->scoreMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createScore($score);
	}

	#[TestWith([null, null])]
	#[TestWith(['', null])]
	#[TestWith(['2026-10-21', '2026-10-21'])]
	#[TestWith(['2028-02-29', '2028-02-29'])]
	public function testParseDateAcceptsValidDates(?string $value, ?string $expected): void {
		$date = $this->service->parseDate($value);

		$this->assertSame($expected, $date?->format('Y-m-d'));
		if ($date !== null) {
			$this->assertSame('00:00:00 UTC', $date->format('H:i:s T'));
		}
	}

	#[TestWith(['2026-02-30'])]
	#[TestWith(['2027-02-29'])]
	#[TestWith(['2026-13-01'])]
	#[TestWith(['2026-1-5'])]
	#[TestWith(['21.10.2026'])]
	#[TestWith(['2026-10-21T10:00:00'])]
	#[TestWith(['yesterday'])]
	#[TestWith([20261021])]
	#[TestWith([['2026-10-21']])]
	public function testParseDateRejectsInvalidDates(mixed $value): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid date');
		$this->service->parseDate($value);
	}

	/**
	 * A blocked score with a confirmed rights status, as loaded from the database.
	 */
	private function createLoadedScore(): Score {
		$score = new Score();
		$score->setId(1);
		$score->setTitle('Test Score');
		$score->setRightsStatus('public_domain');
		$score->setRightsConfirmedAt(new \DateTimeImmutable('2026-10-01'));
		$score->setRightsConfirmedBy('TS');
		$score->setPublicationStatus('blocked');
		$score->setPublicationBlockedUntil(new \DateTimeImmutable('2027-01-31'));
		$score->resetUpdatedFields();
		return $score;
	}

	public function testCreateScoreWithTags(): void {
		$score = new Score();
		$score->setTitle('Test Score');
		$createdScore = clone $score;
		$createdScore->setId(1);

		$tag1 = new Tag();
		$tag1->setId(10);
		$tag2 = new Tag();
		$tag2->setId(20);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($createdScore);

		$this->tagMapper->expects($this->exactly(2))
			->method('find')
			->willReturnCallback(function ($id) use ($tag1, $tag2) {
				return $id === 10 ? $tag1 : $tag2;
			});

		$this->scoreTagLinkMapper->expects($this->once())
			->method('setTagsForScore')
			->with(1, [10, 20]);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($createdScore);

		$result = $this->service->createScore($score, [10, 20]);

		$this->assertSame($createdScore, $result);
	}

	public function testCreateScoreRollsBackWhenLinkingFails(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$service = new ScoreService(
			$this->commentMapper,
			$this->scoreMapper,
			$this->tagMapper,
			$this->scoreBookScoreLinkMapper,
			$this->scoreFolderCollectionLinkMapper,
			$this->scoreTagLinkMapper,
			$this->authorizationService,
			$this->scorePolicy,
			$this->l10n,
			$db,
			$this->shareMapper,
		);

		$created = new Score();
		$created->setId(1);
		$this->scoreMapper->method('insert')->willReturn($created);
		$this->tagMapper->method('find')->willReturn(new Tag());
		$this->scoreTagLinkMapper->method('setTagsForScore')
			->willThrowException(new \RuntimeException('DB error'));

		$this->expectException(\RuntimeException::class);

		$service->createScore(new Score(), [10]);
	}

	public function testCreateScoreThrowsWhenTagNotFound(): void {
		$score = new Score();

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		// tags are validated before anything is written
		$this->scoreMapper->expects($this->never())
			->method('insert');

		$this->tagMapper->expects($this->once())
			->method('find')
			->with(999)
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Tag not found: 999');

		$this->service->createScore($score, [999]);
	}

	public function testCreateScoreWithScoreBookInfo(): void {
		$score = new Score();
		$createdScore = clone $score;
		$createdScore->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreMapper->expects($this->once())
			->method('insert')
			->willReturn($createdScore);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('findScoreBookForScore')
			->with(1)
			->willReturn(null);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('isIndexOccupied')
			->with(5, 10)
			->willReturn(false);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('addScoreToScoreBook')
			->with(5, 1, 10);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($createdScore);

		$result = $this->service->createScore($score, null, ['scoreBookId' => 5, 'index' => 10]);

		$this->assertSame($createdScore, $result);
	}

	public function testUpdateScoreRequiresAuthorization(): void {
		$score = new Score();
		$score->setId(1);
		$score->setTitle('Updated Score');

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy')
			->with($this->scorePolicy, PolicyInterface::ACTION_UPDATE, $score);

		$this->scoreMapper->expects($this->once())
			->method('update')
			->with($score);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($score);

		$result = $this->service->updateScore($score);

		$this->assertSame($score, $result);
	}

	public function testUpdateScoreWithTags(): void {
		$score = new Score();
		$score->setId(1);

		$tag = new Tag();
		$tag->setId(10);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->tagMapper->expects($this->once())
			->method('find')
			->with(10)
			->willReturn($tag);

		$this->scoreTagLinkMapper->expects($this->once())
			->method('setTagsForScore')
			->with(1, [10]);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($score);

		$result = $this->service->updateScore($score, [10]);

		$this->assertSame($score, $result);
	}

	public function testUpdateScoreSkipsMapperUpdateWhenNoFieldsChanged(): void {
		$score = new Score();
		// Like a score loaded from the database
		$score->resetUpdatedFields();
		$score->setId(1);
		// Don't modify any fields, so getUpdatedFields should only have 'id'

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		// Should NOT call scoreMapper->update since no fields changed
		$this->scoreMapper->expects($this->never())
			->method('update');

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($score);

		$this->service->updateScore($score);
	}

	public function testUpdateScoreThrowsWhenTagNotFound(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->tagMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Tag not found: 999');

		$this->service->updateScore($score, [999]);
	}

	public function testUpdateScoreWithScoreBookRemoval(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('findScoreBookForScore')
			->with(1)
			->willReturn(['score_book_id' => 5, 'index' => 10]);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('removeScoreFromAllBooks')
			->with(1);

		$this->scoreMapper->expects($this->once())
			->method('find')
			->willReturn($score);

		$this->service->updateScore($score, null, ['scoreBookId' => null]);
	}

	public function testUpdateScoreThrowsWhenIndexOccupied(): void {
		$score = new Score();
		$score->setId(1);

		$this->authorizationService->expects($this->once())
			->method('authorizePolicy');

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('findScoreBookForScore')
			->willReturn(null);

		$this->scoreBookScoreLinkMapper->expects($this->once())
			->method('isIndexOccupied')
			->with(5, 10)
			->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Index 10 is already occupied in this score book');

		$this->service->updateScore($score, null, ['scoreBookId' => 5, 'index' => 10]);
	}
}
