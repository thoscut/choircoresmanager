<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Service\Catalogue;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Service\Catalogue\CatalogueColumns;
use OCA\ChoirScores\Service\Catalogue\CatalogueException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalogue column mapping.
 */
final class CatalogueColumnsTest extends TestCase {
	private const ENUM = [
		'field' => 'inventoryStatus',
		'type' => 'enum',
		'values' => ['aktiv' => 'active', 'verliehen' => 'lent', '0' => 'missing'],
	];

	public function testHeaderHasTheCatalogueColumnsOnce(): void {
		$this->assertCount(31, CatalogueColumns::HEADER);
		$this->assertSame(CatalogueColumns::HEADER, array_values(array_unique(CatalogueColumns::HEADER)));
		$this->assertSame('Mappe', CatalogueColumns::HEADER[0]);
		$this->assertSame('Notenliste', CatalogueColumns::HEADER[30]);
	}

	public function testOnlyColumnsWithExactlyMatchingFieldsAreMapped(): void {
		$fields = array_map(fn (array $definition) => $definition['field'], CatalogueColumns::FIELDS);

		$this->assertSame([
			'Signatur' => 'signature',
			'Titel' => 'title',
			'Komponist' => 'composer',
			'Zuletzt aufgeführt' => 'lastPerformedImported',
		], $fields);
		foreach (array_keys(CatalogueColumns::FIELDS) as $column) {
			$this->assertContains($column, CatalogueColumns::HEADER);
		}
		$this->assertSame(CatalogueColumns::SIGNATURE, array_key_first(CatalogueColumns::FIELDS));
	}

	#[TestWith(['Bearbeiter/Text'])]
	#[TestWith(['Verlag / Bestellnummer'])]
	#[TestWith(['Rechtestatus'])]
	#[TestWith(['Repertoire'])]
	#[TestWith(['Unbekannte Spalte'])]
	public function testOtherColumnsAreKeptVerbatim(string $column): void {
		$this->assertNull(CatalogueColumns::definition($column));
	}

	public function testParseTextKeepsTheValueVerbatim(): void {
		$definition = CatalogueColumns::FIELDS['Komponist'];

		$this->assertSame(' Satz: N. N. ', CatalogueColumns::parseCell($definition, ' Satz: N. N. '));
		$this->assertNull(CatalogueColumns::parseCell($definition, ''));
	}

	public function testParseTextRejectsValuesLongerThanTheColumn(): void {
		$definition = CatalogueColumns::FIELDS['Signatur'];
		$this->assertSame(str_repeat('ä', 32), CatalogueColumns::parseCell($definition, str_repeat('ä', 32)));

		try {
			CatalogueColumns::parseCell($definition, str_repeat('ä', 33));
			$this->fail('Expected CatalogueException');
		} catch (CatalogueException $e) {
			$this->assertSame(CatalogueException::TOO_LONG, $e->reason);
			$this->assertSame(['max' => 32], $e->context);
		}
	}

	public function testParseRequiredTextRejectsEmptyValue(): void {
		$this->expectExceptionObject(new CatalogueException(CatalogueException::REQUIRED));

		CatalogueColumns::parseCell(CatalogueColumns::FIELDS['Titel'], '');
	}

	#[TestWith(['2024-06-22'])]
	#[TestWith(['1991-02-28'])]
	public function testParseDateAcceptsIsoDates(string $value): void {
		$this->assertSame($value, CatalogueColumns::parseCell(CatalogueColumns::FIELDS['Zuletzt aufgeführt'], $value));
	}

	#[TestWith(['22.06.2024'])]
	#[TestWith(['2024-6-22'])]
	#[TestWith(['2023-02-29'])]
	#[TestWith(['2024'])]
	#[TestWith([' 2024-06-22'])]
	public function testParseDateRejectsOtherValues(string $value): void {
		try {
			CatalogueColumns::parseCell(CatalogueColumns::FIELDS['Zuletzt aufgeführt'], $value);
			$this->fail('Expected CatalogueException');
		} catch (CatalogueException $e) {
			$this->assertSame(CatalogueException::INVALID_DATE, $e->reason);
		}
	}

	public function testParseEnumMapsValues(): void {
		$this->assertSame('lent', CatalogueColumns::parseCell(self::ENUM, 'verliehen'));
		$this->assertSame('missing', CatalogueColumns::parseCell(self::ENUM, '0'));
		$this->assertNull(CatalogueColumns::parseCell(self::ENUM, ''));

		try {
			CatalogueColumns::parseCell(self::ENUM, 'Aktiv');
			$this->fail('Expected CatalogueException');
		} catch (CatalogueException $e) {
			$this->assertSame(CatalogueException::UNKNOWN_VALUE, $e->reason);
			$this->assertSame(['allowed' => 'aktiv, verliehen, 0'], $e->context);
		}
	}

	public function testFormatAndSetFields(): void {
		$score = new Score();

		CatalogueColumns::setField(CatalogueColumns::FIELDS['Titel'], $score, 'Probelied');
		CatalogueColumns::setField(CatalogueColumns::FIELDS['Zuletzt aufgeführt'], $score, '2019-05-26');
		CatalogueColumns::setField(self::ENUM, $score, 'missing');

		$this->assertSame('Probelied', CatalogueColumns::formatField(CatalogueColumns::FIELDS['Titel'], $score));
		$this->assertSame('', CatalogueColumns::formatField(CatalogueColumns::FIELDS['Komponist'], $score));
		$this->assertSame('2019-05-26', CatalogueColumns::formatField(CatalogueColumns::FIELDS['Zuletzt aufgeführt'], $score));
		$this->assertSame('0', CatalogueColumns::formatField(self::ENUM, $score));

		CatalogueColumns::setField(CatalogueColumns::FIELDS['Titel'], $score, null);
		CatalogueColumns::setField(CatalogueColumns::FIELDS['Zuletzt aufgeführt'], $score, null);
		$this->assertSame('', $score->getTitle());
		$this->assertNull($score->getLastPerformedImported());
	}
}
