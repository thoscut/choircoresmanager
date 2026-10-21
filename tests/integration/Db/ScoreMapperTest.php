<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\integration\Db;

use OCA\OrchestraScoresManager\Db\Score;
use OCA\OrchestraScoresManager\Db\ScoreBook;
use OCA\OrchestraScoresManager\Db\ScoreBookMapper;
use OCA\OrchestraScoresManager\Db\ScoreBookScoreLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreBookTagLinkMapper;
use OCA\OrchestraScoresManager\Db\ScoreMapper;
use OCA\OrchestraScoresManager\Db\ScoreTagLinkMapper;
use OCA\OrchestraScoresManager\Db\Tag;
use OCA\OrchestraScoresManager\Db\TagMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Integration tests for ScoreMapper.
 *
 * Tests complex mapper with tag and score book attachments.
 * These tests require a NextCloud environment and run in CI.
 */
final class ScoreMapperTest extends MapperTestCase {
	private ScoreMapper $mapper;
	private TagMapper $tagMapper;
	private ScoreTagLinkMapper $scoreTagLinkMapper;
	private ScoreBookScoreLinkMapper $scoreBookScoreLinkMapper;
	private ScoreBookMapper $scoreBookMapper;

	protected function setUp(): void {
		parent::setUp();

		$scoreBookTagLinkMapper = new ScoreBookTagLinkMapper($this->db);
		$this->tagMapper = new TagMapper($this->db);
		$this->scoreTagLinkMapper = new ScoreTagLinkMapper($this->db);
		$this->scoreBookScoreLinkMapper = new ScoreBookScoreLinkMapper($this->db);
		$this->scoreBookMapper = new ScoreBookMapper($this->db, $scoreBookTagLinkMapper, $this->tagMapper);
		$this->mapper = new ScoreMapper($this->db, $this->scoreTagLinkMapper, $this->tagMapper, $this->scoreBookScoreLinkMapper);
	}

	private function createTestTag(string $name): Tag {
		$tag = new Tag();
		$tag->setName($name);
		return $this->tagMapper->insert($tag);
	}

	private function createTestScoreBook(): ScoreBook {
		$scoreBook = new ScoreBook();
		$scoreBook->setTitle('Test Book');
		return $this->scoreBookMapper->insert($scoreBook);
	}

	public function testFindAttachesTags(): void {
		$tag1 = $this->createTestTag('classical');
		$tag2 = $this->createTestTag('modern');

		$score = new Score();
		$score->setTitle('Test Score');
		$inserted = $this->mapper->insert($score);

		// Add tags
		$this->scoreTagLinkMapper->setTagsForScore($inserted->getId(), [$tag1->getId(), $tag2->getId()]);

		$found = $this->mapper->find($inserted->getId());

		$tags = $found->getTags();
		$this->assertNotNull($tags);
		$this->assertCount(2, $tags);
		$this->assertContains('classical', $tags);
		$this->assertContains('modern', $tags);
	}

	public function testFindAttachesScoreBookInfo(): void {
		$scoreBook = $this->createTestScoreBook();
		$score = new Score();
		$score->setTitle('Test Score');
		$inserted = $this->mapper->insert($score);

		// Add to score book
		$this->scoreBookScoreLinkMapper->addScoreToScoreBook($scoreBook->getId(), $inserted->getId(), 5);

		$found = $this->mapper->find($inserted->getId());

		$bookInfo = $found->getScoreBook();
		$this->assertNotNull($bookInfo);
		$this->assertSame($scoreBook->getId(), $bookInfo['id']);
		$this->assertSame(5, $bookInfo['index']);
	}

	public function testFindAll(): void {
		$tag1 = $this->createTestTag('classical');
		$tag2 = $this->createTestTag('modern');

		$score1 = new Score();
		$score1->setTitle('Score 1');
		$inserted1 = $this->mapper->insert($score1);
		$this->scoreTagLinkMapper->setTagsForScore($inserted1->getId(), [$tag1->getId()]);

		$score2 = new Score();
		$score2->setTitle('Score 2');
		$inserted2 = $this->mapper->insert($score2);
		$this->scoreTagLinkMapper->setTagsForScore($inserted2->getId(), [$tag2->getId()]);

		$all = $this->mapper->findAll();

		$this->assertCount(2, $all);

		// All should have tags attached
		foreach ($all as $score) {
			$this->assertNotNull($score->getTags());
			$this->assertIsArray($score->getTags());
		}
	}

	public function testFindMultiple(): void {
		$tag = $this->createTestTag('classical');

		$score1 = new Score();
		$score1->setTitle('Score 1');
		$inserted1 = $this->mapper->insert($score1);
		$this->scoreTagLinkMapper->setTagsForScore($inserted1->getId(), [$tag->getId()]);

		$score2 = new Score();
		$score2->setTitle('Score 2');
		$inserted2 = $this->mapper->insert($score2);

		$found = $this->mapper->findMultiple([$inserted1->getId(), $inserted2->getId()]);

		$this->assertCount(2, $found);

		// Tags should be attached
		foreach ($found as $score) {
			$this->assertNotNull($score->getTags());
			$this->assertIsArray($score->getTags());
		}
	}

	public function testFindMultipleWithEmptyArray(): void {
		$found = $this->mapper->findMultiple([]);
		$this->assertCount(0, $found);
		$this->assertSame([], $found);
	}

	public function testFindAllWhenEmpty(): void {
		$all = $this->mapper->findAll();
		$this->assertCount(0, $all);
		$this->assertSame([], $all);
	}

	public function testFindThrowsDoesNotExistException(): void {
		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(999999);
	}

	public function testFindAllBatchesTagQueries(): void {
		// Create many scores with different tags to test batching
		$tags = [];
		for ($i = 0; $i < 5; $i++) {
			$tags[] = $this->createTestTag("tag{$i}");
		}

		for ($i = 0; $i < 10; $i++) {
			$score = new Score();
			$score->setTitle("Score {$i}");
			$inserted = $this->mapper->insert($score);

			// Assign some tags
			$tagIds = [$tags[$i % 5]->getId()];
			$this->scoreTagLinkMapper->setTagsForScore($inserted->getId(), $tagIds);
		}

		// findAll should batch tag queries efficiently
		$all = $this->mapper->findAll();

		$this->assertCount(10, $all);

		// All should have tags
		foreach ($all as $score) {
			$this->assertNotNull($score->getTags());
			$this->assertIsArray($score->getTags());
		}
	}

	public function testFindMultipleWithStringIds(): void {
		$score1 = new Score();
		$score1->setTitle('Score 1');
		$inserted1 = $this->mapper->insert($score1);

		$score2 = new Score();
		$score2->setTitle('Score 2');
		$inserted2 = $this->mapper->insert($score2);

		// Pass IDs as strings
		$found = $this->mapper->findMultiple([
			(string)$inserted1->getId(),
			(string)$inserted2->getId(),
		]);

		$this->assertCount(2, $found);
	}

	public function testRightsAndPublicationStatusDefaults(): void {
		$score = new Score();
		$score->setTitle('Defaults');
		$inserted = $this->mapper->insert($score);

		$found = $this->mapper->find($inserted->getId());
		$this->assertSame('open', $found->getRightsStatus());
		$this->assertSame('to_clarify', $found->getPublicationStatus());
		$this->assertNull($found->getPublicationBlockedUntil());
		$this->assertNull($found->getRightsNote());
	}

	public function testBlockedUntilDateRoundTrips(): void {
		$score = new Score();
		$score->setTitle('Blocked');
		$score->setPublicationStatus('blocked');
		$score->setPublicationBlockedUntil('2027-03-31');
		$inserted = $this->mapper->insert($score);

		$this->assertSame('2027-03-31', $this->mapper->find($inserted->getId())->jsonSerialize()['publicationBlockedUntil']);
	}

	public function testFindPublicRequiresReleaseAndPublishableRights(): void {
		$cases = [
			'B public domain' => ['public_domain', 'free'],
			'A licensed' => ['licensed', 'free'],
			'Protected but free' => ['protected', 'free'],
			'Open but free' => ['open', 'free'],
			'Public domain to clarify' => ['public_domain', 'to_clarify'],
			'Public domain blocked' => ['public_domain', 'blocked'],
			'Internal copy' => ['public_domain', 'internal_copy'],
		];
		$ids = [];
		foreach ($cases as $title => [$rights, $publication]) {
			$score = new Score();
			$score->setTitle($title);
			$score->setRightsStatus($rights);
			$score->setPublicationStatus($publication);
			$ids[$title] = $this->mapper->insert($score)->getId();
		}

		$public = array_values(array_filter(
			$this->mapper->findPublic(),
			fn (Score $s) => in_array($s->getId(), $ids, true),
		));
		$this->assertSame(['A licensed', 'B public domain'], array_map(fn (Score $s) => $s->getTitle(), $public));

		$this->assertSame($ids['A licensed'], $this->mapper->findPublicById($ids['A licensed'])->getId());
		$this->expectException(DoesNotExistException::class);
		$this->mapper->findPublicById($ids['Internal copy']);
	}

	public function testFindMultipleWithoutTransientsCanFilterToPublicScores(): void {
		$ids = [];
		foreach ([['public_domain', 'free'], ['protected', 'free'], ['public_domain', 'internal_copy']] as [$rights, $publication]) {
			$score = new Score();
			$score->setTitle("Score $rights $publication");
			$score->setRightsStatus($rights);
			$score->setPublicationStatus($publication);
			$ids[] = $this->mapper->insert($score)->getId();
		}

		$public = $this->mapper->findMultipleWithoutTransients($ids, true);
		$this->assertSame([$ids[0]], array_map(fn (Score $s) => $s->getId(), $public));

		$this->assertCount(3, $this->mapper->findMultipleWithoutTransients($ids, false));
		$this->assertSame([], $this->mapper->findMultipleWithoutTransients([], false));
	}
}
