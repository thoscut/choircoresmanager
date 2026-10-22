<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service\Catalogue;

/**
 * A problem with a catalogue CSV file or one of its values.
 *
 * The reason is a machine-readable code; CatalogueService turns it into a translated message.
 */
class CatalogueException extends \InvalidArgumentException {
	public const NOT_UTF8 = 'not_utf8';
	public const EMPTY_FILE = 'empty_file';
	public const UNTERMINATED_QUOTE = 'unterminated_quote';
	public const EMPTY_COLUMN_NAME = 'empty_column_name';
	public const DUPLICATE_COLUMN = 'duplicate_column';
	public const MISSING_SIGNATURE_COLUMN = 'missing_signature_column';
	public const TOO_LONG = 'too_long';
	public const INVALID_DATE = 'invalid_date';
	public const UNKNOWN_VALUE = 'unknown_value';
	public const REQUIRED = 'required';

	/**
	 * @param string $reason One of the constants of this class
	 * @param array<string, int|string> $context Details for the message, e.g. line or column
	 */
	public function __construct(
		public readonly string $reason,
		public readonly array $context = [],
	) {
		parent::__construct($reason . ($context === [] ? '' : ' ' . json_encode($context)));
	}
}
