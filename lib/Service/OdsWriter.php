<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCP\ITempManager;

/**
 * Writes a minimal OpenDocument spreadsheet (.ods) with one or more sheets of
 * plain text cells. Enough for LibreOffice and Nextcloud Office to open, edit
 * and keep the catalog without the app.
 */
class OdsWriter {
	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * @param list<array{name: string, rows: list<list<string>>}> $sheets in order; the first row is the header
	 * @return string binary .ods content
	 */
	public function write(array $sheets): string {
		$path = $this->tempManager->getTemporaryFile('.ods');
		$zip = new \ZipArchive();
		if ($path === false || $zip->open($path, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Cannot create ODS file');
		}
		// The mimetype entry must come first and be stored uncompressed.
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
		$zip->addFromString('META-INF/manifest.xml', self::manifest());
		$zip->addFromString('styles.xml', self::styles());
		$zip->addFromString('content.xml', self::content($sheets));
		$zip->close();
		$data = file_get_contents($path);
		@unlink($path);
		if ($data === false) {
			throw new \RuntimeException('Cannot read ODS file');
		}
		return $data;
	}

	/**
	 * @param list<array{name: string, rows: list<list<string>>}> $sheets
	 */
	private static function content(array $sheets): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.3">'
			. '<office:body><office:spreadsheet>';
		foreach ($sheets as ['name' => $name, 'rows' => $rows]) {
			$xml .= '<table:table table:name="' . self::esc($name) . '">';
			foreach ($rows as $row) {
				$xml .= '<table:table-row>';
				foreach ($row as $cell) {
					$xml .= $cell === ''
						? '<table:table-cell/>'
						: '<table:table-cell office:value-type="string"><text:p>' . self::esc($cell) . '</text:p></table:table-cell>';
				}
				$xml .= '</table:table-row>';
			}
			$xml .= '</table:table>';
		}
		return $xml . '</office:spreadsheet></office:body></office:document-content>';
	}

	private static function manifest(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
			. '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
			. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
			. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
			. '</manifest:manifest>';
	}

	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" office:version="1.3"/>';
	}

	/**
	 * Escapes text for XML and drops characters XML 1.0 does not allow.
	 */
	private static function esc(string $text): string {
		$text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
