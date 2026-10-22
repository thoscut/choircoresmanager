<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Tests\unit\Db;

use OCA\OrchestraScoresManager\Db\Score;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test cases for the Score entity.
 *
 * Tests only custom functionality: custom setMedleyContents/setGemaIds (with JSON encoding),
 * transient properties (tags, scoreBook), and jsonSerialize with JSON decoding.
 */
final class ScoreTest extends TestCase {
	public static function jsonArrayFieldProvider(): array {
		return [
			'medleyContents with array' => ['medleyContents', ['Piece 1', 'Piece 2', 'Piece 3'], ['Piece 1', 'Piece 2', 'Piece 3']],
			'medleyContents with JSON string' => ['medleyContents', json_encode(['Song A', 'Song B']), ['Song A', 'Song B']],
			'medleyContents with null' => ['medleyContents', null, null],
			'medleyContents with empty string' => ['medleyContents', '', null],
			'gemaIds with array' => ['gemaIds', ['GEMA-001', 'GEMA-002', 'GEMA-003'], ['GEMA-001', 'GEMA-002', 'GEMA-003']],
			'gemaIds with JSON string' => ['gemaIds', json_encode(['ID-1', 'ID-2']), ['ID-1', 'ID-2']],
			'gemaIds with null' => ['gemaIds', null, null],
			'gemaIds with empty string' => ['gemaIds', '', null],
		];
	}

	#[DataProvider('jsonArrayFieldProvider')]
	public function testJsonArrayFieldHandling(string $fieldName, mixed $input, mixed $expected): void {
		$score = new Score();
		$setterMethod = 'set' . ucfirst($fieldName);

		$score->$setterMethod($input);

		$json = $score->jsonSerialize();
		if ($expected === null) {
			$this->assertNull($json[$fieldName]);
		} else {
			$this->assertSame($expected, $json[$fieldName]);
		}
	}

	public static function invalidJsonProvider(): array {
		return [
			'medleyContents with invalid JSON' => ['medleyContents', '{"invalid json', 'Failed to decode medleyContents JSON'],
			'gemaIds with invalid JSON' => ['gemaIds', 'not valid json }', 'Failed to decode gemaIds JSON'],
		];
	}

	#[DataProvider('invalidJsonProvider')]
	public function testInvalidJsonThrowsException(string $fieldName, string $invalidJson, string $expectedMessage): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($expectedMessage);

		$score = new Score();
		$setterMethod = 'set' . ucfirst($fieldName);
		$score->$setterMethod($invalidJson);
		$score->jsonSerialize();
	}

	public static function nonArrayJsonProvider(): array {
		return [
			'medleyContents with string JSON' => ['medleyContents', '"just a string"'],
			'gemaIds with number JSON' => ['gemaIds', '123'],
		];
	}

	#[DataProvider('nonArrayJsonProvider')]
	public function testNonArrayJsonReturnsNull(string $fieldName, string $jsonValue): void {
		$score = new Score();
		$setterMethod = 'set' . ucfirst($fieldName);

		$score->$setterMethod($jsonValue);

		$json = $score->jsonSerialize();
		$this->assertNull($json[$fieldName]);
	}

	public function testSetMedleyContentsEncodesToJson(): void {
		$score = new Score();
		$score->setMedleyContents(['Piece 1', 'Piece 2']);

		$this->assertSame('["Piece 1","Piece 2"]', $score->getMedleyContents());
	}

	public function testSetGemaIdsEncodesToJson(): void {
		$score = new Score();
		$score->setGemaIds(['GEMA-123', 'GEMA-456']);

		$this->assertSame('["GEMA-123","GEMA-456"]', $score->getGemaIds());
	}

	public function testTagsTransientProperty(): void {
		$score = new Score();
		$tags = ['classical', 'symphony', 'beethoven'];

		$score->setTags($tags);

		$this->assertSame($tags, $score->getTags());
	}

	public function testTagsAreNullByDefault(): void {
		$score = new Score();

		$this->assertNull($score->getTags());
	}

	public function testTagsCanBeSetToNull(): void {
		$score = new Score();
		$score->setTags(['tag1', 'tag2']);
		$score->setTags(null);

		$this->assertNull($score->getTags());
	}

	public function testTagsAreConvertedToStrings(): void {
		$score = new Score();
		$score->setTags([1, 2, 'three']);

		$expected = ['1', '2', 'three'];
		$this->assertSame($expected, $score->getTags());
	}

	public function testScoreBookTransientProperty(): void {
		$score = new Score();
		$scoreBookInfo = ['id' => 5, 'index' => 3];

		$score->setScoreBook($scoreBookInfo);

		$this->assertSame($scoreBookInfo, $score->getScoreBook());
	}

	public function testScoreBookIsNullByDefault(): void {
		$score = new Score();

		$this->assertNull($score->getScoreBook());
	}

	public function testScoreBookCanBeSetToNull(): void {
		$score = new Score();
		$score->setScoreBook(['id' => 5, 'index' => 3]);
		$score->setScoreBook(null);

		$this->assertNull($score->getScoreBook());
	}

	public function testJsonSerializeWithAllFields(): void {
		$score = new Score();
		$score->setId(1);
		$score->setTitle('Complete Score');
		$score->setTitleShort('CS');
		$score->setComposer('Composer Name');
		$score->setArranger('Arranger Name');
		$score->setPublisher('Publisher Name');
		$score->setYear(2024);
		$score->setDifficulty(4.0);
		$score->setMedleyContents(['Song 1', 'Song 2']);
		$score->setDefects('Some defects');
		$score->setPhysicalCopiesStatus('Good');
		$score->setDigitalStatus('Available');
		$score->setGemaIds(['GEMA-123', 'GEMA-456']);
		$score->setDuration(450);
		$score->setTags(['tag1', 'tag2']);
		$score->setScoreBook(['id' => 10, 'index' => 5]);

		$json = $score->jsonSerialize();

		$this->assertSame(1, $json['id']);
		$this->assertSame('Complete Score', $json['title']);
		$this->assertSame('CS', $json['titleShort']);
		$this->assertSame('Composer Name', $json['composer']);
		$this->assertSame('Arranger Name', $json['arranger']);
		$this->assertSame('Publisher Name', $json['publisher']);
		$this->assertSame(2024, $json['year']);
		$this->assertSame(4.0, $json['difficulty']);
		$this->assertSame(['Song 1', 'Song 2'], $json['medleyContents']);
		$this->assertSame('Some defects', $json['defects']);
		$this->assertSame('Good', $json['physicalCopiesStatus']);
		$this->assertSame('Available', $json['digitalStatus']);
		$this->assertSame(['GEMA-123', 'GEMA-456'], $json['gemaIds']);
		$this->assertSame(450, $json['duration']);
		$this->assertSame(['tag1', 'tag2'], $json['tags']);
		$this->assertSame(['id' => 10, 'index' => 5], $json['scoreBook']);
	}

	public function testJsonSerializeWithMinimalFields(): void {
		$score = new Score();
		$score->setId(1);
		$score->setTitle('Minimal Score');

		$json = $score->jsonSerialize();

		$this->assertSame(1, $json['id']);
		$this->assertSame('Minimal Score', $json['title']);
		$this->assertNull($json['titleShort']);
		$this->assertNull($json['composer']);
		$this->assertNull($json['arranger']);
		$this->assertNull($json['publisher']);
		$this->assertNull($json['year']);
		$this->assertNull($json['difficulty']);
		$this->assertNull($json['medleyContents']);
		$this->assertNull($json['defects']);
		$this->assertNull($json['physicalCopiesStatus']);
		$this->assertNull($json['digitalStatus']);
		$this->assertNull($json['gemaIds']);
		$this->assertNull($json['duration']);
		$this->assertSame([], $json['tags']);
		$this->assertNull($json['scoreBook']);
	}

	public function testJsonSerializeWithNullTransientProperties(): void {
		$score = new Score();
		$score->setId(1);
		$score->setTitle('Score Without Transients');
		$score->setTags(null);
		$score->setScoreBook(null);

		$json = $score->jsonSerialize();

		$this->assertSame([], $json['tags']);
		$this->assertNull($json['scoreBook']);
	}

	public static function validInventoryStatusProvider(): array {
		return [
			'active' => ['active', 'active'],
			'retired' => ['retired', 'retired'],
			'missing' => ['missing', 'missing'],
			'lent' => ['lent', 'lent'],
			'null selects default' => [null, 'active'],
			'empty string selects default' => ['', 'active'],
		];
	}

	#[DataProvider('validInventoryStatusProvider')]
	public function testSetInventoryStatus(?string $input, string $expected): void {
		$score = new Score();
		$score->setInventoryStatus('missing');
		$score->setInventoryStatus($input);

		$this->assertSame($expected, $score->getInventoryStatus());
		$this->assertSame($expected, $score->jsonSerialize()['inventoryStatus']);
	}

	public function testInventoryStatusDefaultsToActive(): void {
		$score = new Score();

		$this->assertSame('active', $score->getInventoryStatus());
		$this->assertSame('active', $score->jsonSerialize()['inventoryStatus']);
	}

	#[TestWith(['archived'])]
	#[TestWith(['dormant'])]
	#[TestWith(['Active'])]
	public function testSetInventoryStatusRejectsUnknownValue(string $value): void {
		$this->expectException(\InvalidArgumentException::class);

		$score = new Score();
		$score->setInventoryStatus($value);
	}

	public function testSetLastPerformedImportedFromString(): void {
		$score = new Score();
		$score->setLastPerformedImported('2019-05-26');

		$this->assertSame('2019-05-26', $score->getLastPerformedImported()?->format('Y-m-d'));
		$this->assertSame('2019-05-26', $score->jsonSerialize()['lastPerformedImported']);
		$this->assertArrayHasKey('lastPerformedImported', $score->getUpdatedFields());
	}

	public function testSetLastPerformedImportedEmptyStringBecomesNull(): void {
		$score = new Score();
		$score->setLastPerformedImported('2019-05-26');
		$score->setLastPerformedImported('');

		$this->assertNull($score->getLastPerformedImported());
		$this->assertNull($score->jsonSerialize()['lastPerformedImported']);
	}

	public function testSetLastPerformedImportedSameDateIsNoUpdate(): void {
		$score = new Score();
		$score->setLastPerformedImported('2019-05-26');
		$score->resetUpdatedFields();

		$score->setLastPerformedImported(new \DateTimeImmutable('2019-05-26'));

		$this->assertArrayNotHasKey('lastPerformedImported', $score->getUpdatedFields());
	}

	#[TestWith(['26.05.2019'])]
	#[TestWith(['2019-5-26'])]
	#[TestWith(['2019-02-30'])]
	#[TestWith(['2019'])]
	public function testSetLastPerformedImportedRejectsInvalidDate(string $value): void {
		$this->expectException(\InvalidArgumentException::class);

		$score = new Score();
		$score->setLastPerformedImported($value);
	}

	public function testRepertoireFieldsInJson(): void {
		$score = new Score();
		$score->setSignature('5-22');
		$score->setLyricist('Matthias Claudius');
		$score->setVoicing('TTBB');
		$score->setLanguage('Deutsch');
		$score->setLastPerformed('2025-12-05');
		$score->setRepertoireClass('current');

		$json = $score->jsonSerialize();
		$this->assertSame('5-22', $json['signature']);
		$this->assertSame('Matthias Claudius', $json['lyricist']);
		$this->assertSame('TTBB', $json['voicing']);
		$this->assertSame('Deutsch', $json['language']);
		$this->assertSame('2025-12-05', $json['lastPerformed']);
		$this->assertSame('current', $json['repertoireClass']);
	}

	public function testLastPerformedIsNullAndNotDocumentedByDefault(): void {
		$score = new Score();
		$this->assertNull($score->getLastPerformed());
		$this->assertNull($score->jsonSerialize()['lastPerformed']);
		$this->assertSame('not_documented', $score->getRepertoireClass());
		$this->assertSame('not_documented', $score->jsonSerialize()['repertoireClass']);
	}

	public function testCatalogueExtraValuesKeepOrderAndDropEmptyValues(): void {
		$score = new Score();
		$score->setCatalogueExtraValues(['Notenliste' => 'Advent, Frühjahr', 'Mappe' => '5', 'Lizenz' => '', '2024' => 'Zahl als Spaltenname']);

		$this->assertSame(['Notenliste' => 'Advent, Frühjahr', 'Mappe' => '5', '2024' => 'Zahl als Spaltenname'], $score->getCatalogueExtraValues());
		$this->assertSame('{"Notenliste":"Advent, Frühjahr","Mappe":"5","2024":"Zahl als Spaltenname"}', $score->getCatalogueExtra());
		$this->assertArrayNotHasKey('catalogueExtra', $score->jsonSerialize());
	}

	public function testCatalogueExtraValuesWithOnlyNumericColumnNamesStayAnObject(): void {
		$score = new Score();
		$score->setCatalogueExtraValues(['0' => 'a', '1' => 'b']);

		$this->assertSame('{"0":"a","1":"b"}', $score->getCatalogueExtra());
		$this->assertSame(['0' => 'a', '1' => 'b'], $score->getCatalogueExtraValues());
	}

	public function testEmptyCatalogueExtraValuesAreStoredAsNull(): void {
		$score = new Score();
		$score->setCatalogueExtraValues(['Mappe' => '5']);
		$score->setCatalogueExtraValues(['Mappe' => '']);

		$this->assertNull($score->getCatalogueExtra());
		$this->assertSame([], $score->getCatalogueExtraValues());
	}

	public function testSetCatalogueExtraRejectsNonObjectJson(): void {
		$this->expectException(\InvalidArgumentException::class);

		$score = new Score();
		$score->setCatalogueExtra('"just a string"');
	}

	public function testNewScoreAlwaysWritesStatusColumns(): void {
		$score = new Score();

		$this->assertArrayHasKey('rightsStatus', $score->getUpdatedFields());
		$this->assertArrayHasKey('publicationStatus', $score->getUpdatedFields());
		$this->assertSame('open', $score->getRightsStatus());
		$this->assertSame('to_clarify', $score->getPublicationStatus());
	}

	public function testDateFieldsAreSerializedAsDates(): void {
		$score = new Score();
		$score->setRightsConfirmedAt(new \DateTimeImmutable('2026-10-20 00:00:00', new \DateTimeZone('UTC')));
		$score->setPublicationBlockedUntil(new \DateTimeImmutable('2027-03-31 00:00:00', new \DateTimeZone('UTC')));
		$score->setUpdatedAt(new \DateTimeImmutable('2026-10-21 12:34:56', new \DateTimeZone('UTC')));

		$serialized = $score->jsonSerialize();

		$this->assertSame('2026-10-20', $serialized['rightsConfirmedAt']);
		$this->assertSame('2027-03-31', $serialized['publicationBlockedUntil']);
		$this->assertSame('2026-10-21T12:34:56+00:00', $serialized['updatedAt']);
	}

	public function testDateFieldsAreNullByDefault(): void {
		$serialized = (new Score())->jsonSerialize();

		$this->assertNull($serialized['rightsConfirmedAt']);
		$this->assertNull($serialized['publicationBlockedUntil']);
		$this->assertNull($serialized['updatedAt']);
	}
}
