<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service\Catalogue;

/**
 * Reads and writes the CSV format of the work catalogue (Werkverzeichnis).
 *
 * Format: semicolon-separated, UTF-8 (a leading BOM is accepted and always written),
 * standard CSV quoting: values containing a semicolon, a double quote or a line break are
 * enclosed in double quotes, double quotes inside are doubled. Values are never interpreted,
 * so signatures such as "5-19" stay text.
 */
final class CatalogueCsv {
	public const DELIMITER = ';';
	public const BOM = "\xEF\xBB\xBF";
	public const LINE_ENDING = "\r\n";

	/**
	 * Parse CSV content into a header and records.
	 *
	 * Blank lines are skipped. Line numbers refer to the physical line where a record starts,
	 * counting from 1 for the first line of the file.
	 *
	 * @param string $content The raw file content
	 * @return array{header: list<string>, records: list<array{line: int, cells: list<string>}>}
	 * @throws CatalogueException If the file is not valid UTF-8, empty, has an unterminated quote
	 *                            or an invalid header
	 */
	public static function parse(string $content): array {
		if (str_starts_with($content, self::BOM)) {
			$content = substr($content, strlen(self::BOM));
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			throw new CatalogueException(CatalogueException::NOT_UTF8);
		}

		$records = self::splitRecords($content);
		if ($records === []) {
			throw new CatalogueException(CatalogueException::EMPTY_FILE);
		}

		$headerRecord = array_shift($records);
		$header = $headerRecord['cells'];
		$seen = [];
		foreach ($header as $index => $name) {
			if (trim($name) === '') {
				throw new CatalogueException(CatalogueException::EMPTY_COLUMN_NAME, ['column' => $index + 1]);
			}
			if (isset($seen[$name])) {
				throw new CatalogueException(CatalogueException::DUPLICATE_COLUMN, ['name' => $name]);
			}
			$seen[$name] = true;
		}

		return ['header' => $header, 'records' => $records];
	}

	/**
	 * Serialize a header and rows to CSV content, including a BOM.
	 *
	 * @param list<string> $header Column names
	 * @param list<list<string>> $rows Cell values; each row must have as many cells as the header
	 * @return string
	 */
	public static function serialize(array $header, array $rows): string {
		$lines = [self::formatRecord($header)];
		foreach ($rows as $row) {
			$lines[] = self::formatRecord($row);
		}
		return self::BOM . implode(self::LINE_ENDING, $lines) . self::LINE_ENDING;
	}

	/**
	 * Split content into records of cells, honouring quoted values.
	 *
	 * @param string $content Content without BOM
	 * @return list<array{line: int, cells: list<string>}>
	 * @throws CatalogueException If a quoted value is not terminated
	 */
	private static function splitRecords(string $content): array {
		$records = [];
		$cells = [];
		$cell = '';
		$inQuotes = false;
		$cellWasQuoted = false;
		$line = 1;
		$recordLine = 1;
		$quoteLine = 1;
		$length = strlen($content);

		// Delimiters, quotes and line breaks are ASCII and never part of a multibyte
		// UTF-8 sequence, so iterating bytes is safe.
		for ($i = 0; $i < $length; $i++) {
			$char = $content[$i];

			if ($inQuotes) {
				if ($char === '"') {
					if ($i + 1 < $length && $content[$i + 1] === '"') {
						$cell .= '"';
						$i++;
					} else {
						$inQuotes = false;
					}
					continue;
				}
				if ($char === "\n" || ($char === "\r" && ($i + 1 >= $length || $content[$i + 1] !== "\n"))) {
					$line++;
				}
				$cell .= $char;
				continue;
			}

			if ($char === '"' && $cell === '' && !$cellWasQuoted) {
				$inQuotes = true;
				$cellWasQuoted = true;
				$quoteLine = $line;
				continue;
			}
			if ($char === self::DELIMITER) {
				$cells[] = $cell;
				$cell = '';
				$cellWasQuoted = false;
				continue;
			}
			if ($char === "\r" || $char === "\n") {
				$cells[] = $cell;
				self::addRecord($records, $recordLine, $cells);
				$cells = [];
				$cell = '';
				$cellWasQuoted = false;
				if ($char === "\r" && $i + 1 < $length && $content[$i + 1] === "\n") {
					$i++;
				}
				$line++;
				$recordLine = $line;
				continue;
			}
			// Anything else is literal, including quotes inside unquoted values
			$cell .= $char;
		}

		if ($inQuotes) {
			throw new CatalogueException(CatalogueException::UNTERMINATED_QUOTE, ['line' => $quoteLine]);
		}
		if ($cell !== '' || $cells !== [] || $cellWasQuoted) {
			$cells[] = $cell;
			self::addRecord($records, $recordLine, $cells);
		}

		return $records;
	}

	/**
	 * Append a record unless it is a blank line.
	 *
	 * @param list<array{line: int, cells: list<string>}> $records
	 * @param int $line
	 * @param list<string> $cells
	 */
	private static function addRecord(array &$records, int $line, array $cells): void {
		if (count($cells) === 1 && $cells[0] === '') {
			return;
		}
		$records[] = ['line' => $line, 'cells' => $cells];
	}

	/**
	 * Format one record without line ending.
	 *
	 * @param list<string> $cells
	 */
	private static function formatRecord(array $cells): string {
		return implode(self::DELIMITER, array_map(self::formatCell(...), $cells));
	}

	/**
	 * Quote a value if necessary. Leading or trailing whitespace is quoted as well,
	 * so that spreadsheet programs keep it.
	 */
	private static function formatCell(string $value): string {
		if ($value === '') {
			return '';
		}
		if (strpbrk($value, self::DELIMITER . "\"\r\n") !== false || trim($value) !== $value) {
			return '"' . str_replace('"', '""', $value) . '"';
		}
		return $value;
	}
}
