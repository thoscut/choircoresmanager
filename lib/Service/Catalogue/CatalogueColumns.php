<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service\Catalogue;

use OCA\ChoirScores\Db\Score;

/**
 * The columns of the work catalogue (Werkverzeichnis) and how they map to score fields.
 *
 * This is the only place that decides which CSV column is stored in which score field.
 * A column is mapped only where an existing field has exactly the same meaning, without
 * splitting or interpreting values. Every other column is stored verbatim per score
 * (Score::catalogueExtra, keyed by column name) and exported unchanged, so that an
 * import followed by an export loses nothing.
 *
 * To store a further column in a field once that field exists (for example the rights,
 * publication and MusicXML fields), add one line to FIELDS. Values kept as extra data so far
 * are taken over into the field by the next import of the catalogue.
 *
 * @psalm-type CatalogueFieldDefinition = array{
 *     field: string,
 *     type: 'text'|'date'|'enum',
 *     maxLength?: int,
 *     required?: bool,
 *     values?: array<array-key, string>
 * }
 */
final class CatalogueColumns {
	/** Column that identifies a score; rows are matched by it */
	public const SIGNATURE = 'Signatur';

	/** Column names of the catalogue, in export order */
	public const HEADER = [
		'Mappe',
		'Nr',
		'Signatur',
		'Titel',
		'Komponist',
		'Bearbeiter/Text',
		'Anmerkung',
		'Rechte-Ersteinschätzung',
		'Rechtestatus',
		'Rechte bestätigt',
		'Auf Singste seit',
		'Verlag / Bestellnummer',
		'Lizenz',
		'Erwerbsbeleg',
		'Fassungen',
		'PDF-Dateien',
		'Titelquelle',
		'Seiten',
		'Formate',
		'Dateien',
		'MusicXML',
		'MusicXML-gilt',
		'MusicXML-Stand',
		'MusicXML-Herkunft',
		'MusicXML-Prüfstand',
		'Veröffentlichung',
		'Veröffentlichung-Begründung',
		'Repertoire',
		'Aufführungen belegt',
		'Zuletzt aufgeführt',
		'Notenliste',
	];

	/**
	 * Columns stored in a score field, keyed by column name.
	 *
	 * - text: stored verbatim, an empty cell clears the field; maxLength is the column length in the database
	 * - date: YYYY-MM-DD, an empty cell clears the field
	 * - enum: values maps each allowed cell value to the stored field value; other values are rejected
	 * - required: the cell must not be empty, and new scores need the column
	 *
	 * Deliberately not mapped (kept verbatim): "Bearbeiter/Text" mixes arranger and lyricist,
	 * "Verlag / Bestellnummer" combines publisher and order number, "Anmerkung" has no
	 * matching field ("defects" means damage), "Repertoire" is derived by the app.
	 *
	 * @var array<string, CatalogueFieldDefinition>
	 */
	public const FIELDS = [
		'Signatur' => ['field' => 'signature', 'type' => 'text', 'maxLength' => 32],
		'Titel' => ['field' => 'title', 'type' => 'text', 'maxLength' => 300, 'required' => true],
		'Komponist' => ['field' => 'composer', 'type' => 'text', 'maxLength' => 255],
		'Zuletzt aufgeführt' => ['field' => 'lastPerformedImported', 'type' => 'date'],
	];

	/**
	 * Get the field definition of a column, or null if the column is kept verbatim.
	 *
	 * @param string $column
	 * @return CatalogueFieldDefinition|null
	 */
	public static function definition(string $column): ?array {
		return self::FIELDS[$column] ?? null;
	}

	/**
	 * Convert a cell into the value passed to the field setter.
	 *
	 * @param CatalogueFieldDefinition $definition
	 * @param string $cell
	 * @return string|null Null for an empty cell
	 * @throws CatalogueException If the value is not allowed for the field
	 */
	public static function parseCell(array $definition, string $cell): ?string {
		if ($cell === '') {
			if ($definition['required'] ?? false) {
				throw new CatalogueException(CatalogueException::REQUIRED);
			}
			return null;
		}
		switch ($definition['type']) {
			case 'date':
				if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $cell, $matches) !== 1
					|| !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
					throw new CatalogueException(CatalogueException::INVALID_DATE);
				}
				return $cell;
			case 'enum':
				$values = $definition['values'] ?? [];
				if (!array_key_exists($cell, $values)) {
					throw new CatalogueException(CatalogueException::UNKNOWN_VALUE, ['allowed' => implode(', ', array_map('strval', array_keys($values)))]);
				}
				return $values[$cell];
			default:
				$maxLength = $definition['maxLength'] ?? null;
				if ($maxLength !== null && mb_strlen($cell) > $maxLength) {
					throw new CatalogueException(CatalogueException::TOO_LONG, ['max' => $maxLength]);
				}
				return $cell;
		}
	}

	/**
	 * Format the field value of a score as a cell.
	 *
	 * @param CatalogueFieldDefinition $definition
	 * @param Score $score
	 * @return string Empty string if the field has no value
	 */
	public static function formatField(array $definition, Score $score): string {
		/** @var mixed $value */
		$value = $score->{'get' . ucfirst($definition['field'])}();
		if ($value === null) {
			return '';
		}
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}
		if ($definition['type'] === 'enum') {
			$cell = array_search($value, $definition['values'] ?? [], true);
			if ($cell !== false) {
				return (string)$cell;
			}
		}
		return is_scalar($value) ? (string)$value : '';
	}

	/**
	 * Set the field of a score from a value returned by parseCell().
	 *
	 * @param CatalogueFieldDefinition $definition
	 * @param Score $score
	 * @param string|null $value
	 */
	public static function setField(array $definition, Score $score, ?string $value): void {
		if ($definition['field'] === 'title') {
			// title is not nullable
			$score->setTitle($value ?? '');
			return;
		}
		$score->{'set' . ucfirst($definition['field'])}($value);
	}
}
