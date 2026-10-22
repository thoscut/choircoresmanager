<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Service;

use OCA\Notenarchiv\Service\OdsWriter;
use OCA\Notenarchiv\Tests\Unit\Support\TempFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(OdsWriter::class)]
#[RequiresPhpExtension('zip')]
final class OdsWriterTest extends TestCase {
	use TempFiles;

	private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	private function write(): \ZipArchive {
		$ods = (new OdsWriter($this->tempManager()))->write([
			['name' => 'Catalog', 'rows' => [['Signatur', 'Titel'], ['5-1', 'Ave <verum> & "corpus"'], ['5-2', '']]],
			['name' => '2024', 'rows' => [['Control' . "\x01" . 'character', "line\nbreak"]]],
		]);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($this->tempFile($ods)));
		return $zip;
	}

	public function testMimetypeComesFirstAndUncompressed(): void {
		$zip = $this->write();
		$first = $zip->statIndex(0);
		self::assertIsArray($first);
		self::assertSame('mimetype', $first['name']);
		self::assertSame(\ZipArchive::CM_STORE, $first['comp_method']);
		self::assertSame('application/vnd.oasis.opendocument.spreadsheet', $zip->getFromName('mimetype'));
		self::assertNotFalse($zip->getFromName('META-INF/manifest.xml'));
		self::assertNotFalse($zip->getFromName('styles.xml'));
	}

	public function testContentHoldsSheetsAndCellsAsText(): void {
		$content = (string)$this->write()->getFromName('content.xml');
		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($content), 'content.xml is well-formed XML');

		$tables = $document->getElementsByTagNameNS(self::NS_TABLE, 'table');
		self::assertSame(2, $tables->length);
		$names = [];
		$cells = [];
		foreach ($tables as $table) {
			self::assertInstanceOf(\DOMElement::class, $table);
			$names[] = $table->getAttributeNS(self::NS_TABLE, 'name');
			$rows = [];
			foreach ($table->getElementsByTagNameNS(self::NS_TABLE, 'table-row') as $row) {
				self::assertInstanceOf(\DOMElement::class, $row);
				$texts = [];
				foreach ($row->getElementsByTagNameNS(self::NS_TABLE, 'table-cell') as $cell) {
					$texts[] = $cell->textContent;
				}
				$rows[] = $texts;
			}
			$cells[] = $rows;
		}
		self::assertSame(['Catalog', '2024'], $names);
		self::assertSame([['Signatur', 'Titel'], ['5-1', 'Ave <verum> & "corpus"'], ['5-2', '']], $cells[0]);
		self::assertSame([['Controlcharacter', "line\nbreak"]], $cells[1], 'characters not allowed in XML are dropped');
		self::assertStringContainsString('<table:table-cell/>', $content, 'empty cells stay empty');
	}

	public function testRemovesItsTemporaryFile(): void {
		$created = [];
		$manager = $this->createStub(\OCP\ITempManager::class);
		$manager->method('getTemporaryFile')->willReturnCallback(function () use (&$created): string {
			$path = $this->tempFile('');
			$created[] = $path;
			return $path;
		});
		(new OdsWriter($manager))->write([['name' => 'Sheet', 'rows' => [['a']]]]);
		self::assertCount(1, $created);
		self::assertFileDoesNotExist($created[0]);
	}
}
