<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service;

use OCA\ChoirScores\Db\Score;
use OCA\ChoirScores\Db\ScoreMapper;
use OCA\ChoirScores\Policy\PolicyInterface;
use OCA\ChoirScores\Policy\ScorePolicy;
use OCA\ChoirScores\ResponseDefinitions;
use OCA\ChoirScores\Service\Catalogue\CatalogueColumns;
use OCA\ChoirScores\Service\Catalogue\CatalogueCsv;
use OCA\ChoirScores\Service\Catalogue\CatalogueException;
use OCA\ChoirScores\Service\Exceptions\NotFoundException;
use OCA\ChoirScores\Utility\SignatureComparator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Import and export of the work catalogue (Werkverzeichnis) as CSV.
 *
 * Rows are matched to scores by signature. Scores that are not in an imported file are left
 * untouched, nothing is ever deleted. Columns without a matching field are kept verbatim per
 * score, so that exporting reproduces the imported table; see CatalogueColumns.
 *
 * @psalm-import-type ChoirScoresCatalogueImportResult from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueImportRow from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueChange from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueExport from ResponseDefinitions
 * @psalm-import-type ChoirScoresCatalogueValue from ResponseDefinitions
 *
 * @psalm-type CatalogueAnalysedRow = array{
 *     line: int,
 *     signature: string,
 *     title: string,
 *     status: 'new'|'changed'|'unchanged'|'error',
 *     score: Score|null,
 *     changes: list<ChoirScoresCatalogueChange>,
 *     errors: list<string>,
 *     fields: array<string, string|null>,
 *     extra: array<array-key, string>
 * }
 */
class CatalogueService {
	use TTransactional;

	/** Upper limit for the size of an imported file in MB */
	public const MAX_FILE_SIZE_MB = 10;

	/** @psalm-suppress PossiblyUnusedMethod - Constructor used by DI container */
	public function __construct(
		private readonly ScoreMapper $scoreMapper,
		private readonly AuthorizationService $authorizationService,
		private readonly ScorePolicy $scorePolicy,
		private readonly ConfigService $configService,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private IL10N $l,
	) {
	}

	/**
	 * Import the catalogue from CSV, or only report what an import would change.
	 *
	 * Rows with errors (missing or duplicate signature, invalid values) are never applied.
	 *
	 * @param string $content The CSV file content
	 * @param bool $dryRun If true, nothing is saved
	 * @return ChoirScoresCatalogueImportResult
	 * @throws \InvalidArgumentException If the file cannot be read as a catalogue
	 * @throws Exception
	 */
	public function importCatalogue(string $content, bool $dryRun): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_CREATE);
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_UPDATE);

		if (strlen($content) > self::MAX_FILE_SIZE_MB * 1024 * 1024) {
			throw new \InvalidArgumentException($this->l->t('The file is too large. The maximum size is %d MB.', [self::MAX_FILE_SIZE_MB]));
		}
		try {
			$table = CatalogueCsv::parse($content);
		} catch (CatalogueException $e) {
			throw new \InvalidArgumentException($this->fileErrorMessage($e), 0, $e);
		}
		$header = $table['header'];
		if (!in_array(CatalogueColumns::SIGNATURE, $header, true)) {
			throw new \InvalidArgumentException($this->l->t('The file has no column "%s".', [CatalogueColumns::SIGNATURE]));
		}

		$rows = $this->analyse($header, $table['records'], $this->scoreMapper->findAll());
		if (!$dryRun) {
			$this->apply($header, $rows);
		}

		return $this->buildResult($header, $rows, $dryRun);
	}

	/**
	 * Export all scores in the catalogue format.
	 *
	 * Columns: the catalogue header in its order, then further columns of the last import and of
	 * stored extra data in first-seen order. Rows are sorted by signature in natural order.
	 *
	 * @return ChoirScoresCatalogueExport
	 */
	public function exportCatalogue(): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);

		$scores = $this->scoreMapper->findAll();
		usort($scores, static fn (Score $a, Score $b): int => SignatureComparator::compare($a->getSignature(), $b->getSignature())
			?: strcmp($a->getTitle(), $b->getTitle())
			?: $a->getId() <=> $b->getId());

		$header = CatalogueColumns::HEADER;
		$known = array_fill_keys($header, true);
		$addColumn = static function (string $column) use (&$header, &$known): void {
			if (!isset($known[$column])) {
				$header[] = $column;
				$known[$column] = true;
			}
		};
		foreach ($this->configService->getCatalogueColumns() as $column) {
			$addColumn($column);
		}
		$extras = [];
		foreach ($scores as $index => $score) {
			$extras[$index] = $score->getCatalogueExtraValues();
			foreach (array_keys($extras[$index]) as $column) {
				$addColumn((string)$column);
			}
		}

		$rows = [];
		foreach ($scores as $index => $score) {
			$row = [];
			foreach ($header as $column) {
				$definition = CatalogueColumns::definition($column);
				$cell = $definition === null ? '' : CatalogueColumns::formatField($definition, $score);
				if ($cell === '') {
					// also covers values stored before their column was mapped to a field
					$cell = $extras[$index][$column] ?? '';
				}
				$row[] = $cell;
			}
			$rows[] = $row;
		}

		$prefix = preg_replace('/[\\\\\/:*?"<>|]/', '_', $this->l->t('Catalogue')) ?? 'Catalogue';
		return [
			'fileName' => $prefix . '-' . $this->timeFactory->now()->format('Y-m-d') . '.csv',
			'content' => CatalogueCsv::serialize($header, $rows),
		];
	}

	/**
	 * Get the catalogue values of a score that are not stored in a field.
	 *
	 * @param int $scoreId
	 * @return list<ChoirScoresCatalogueValue> In catalogue column order, then further columns
	 * @throws NotFoundException If the score does not exist
	 */
	public function getCatalogueValues(int $scoreId): array {
		$this->authorizationService->authorizePolicy($this->scorePolicy, PolicyInterface::ACTION_READ);
		try {
			$score = $this->scoreMapper->find($scoreId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			throw new NotFoundException($this->l->t('Score not found'), 0, $e);
		}

		$values = $score->getCatalogueExtraValues();
		$result = [];
		foreach (CatalogueColumns::HEADER as $column) {
			if (isset($values[$column])) {
				$result[] = ['column' => $column, 'value' => $values[$column]];
				unset($values[$column]);
			}
		}
		foreach ($values as $column => $value) {
			$result[] = ['column' => (string)$column, 'value' => $value];
		}
		return $result;
	}

	/**
	 * Compare the records of a file with the existing scores.
	 *
	 * @param list<string> $header
	 * @param list<array{line: int, cells: list<string>}> $records
	 * @param array<Score> $scores All existing scores
	 * @return list<CatalogueAnalysedRow>
	 */
	private function analyse(array $header, array $records, array $scores): array {
		$signatureIndex = (int)array_search(CatalogueColumns::SIGNATURE, $header, true);

		/** @var array<string, list<Score>> $scoresBySignature */
		$scoresBySignature = [];
		foreach ($scores as $score) {
			$signature = $score->getSignature();
			if ($signature !== null && $signature !== '') {
				$scoresBySignature[$signature][] = $score;
			}
		}

		/** @var array<string, int> $signatureCounts */
		$signatureCounts = [];
		foreach ($records as $record) {
			$signature = $record['cells'][$signatureIndex] ?? '';
			if (trim($signature) !== '') {
				$signatureCounts[$signature] = ($signatureCounts[$signature] ?? 0) + 1;
			}
		}

		$rows = [];
		foreach ($records as $record) {
			$signature = $record['cells'][$signatureIndex] ?? '';
			$rows[] = $this->analyseRecord($header, $record, $signature, $signatureCounts[$signature] ?? 0, $scoresBySignature[$signature] ?? []);
		}
		return $rows;
	}

	/**
	 * Compare one record with the score that has its signature.
	 *
	 * @param list<string> $header
	 * @param array{line: int, cells: list<string>} $record
	 * @param string $signature
	 * @param int $signatureCount How often the signature occurs in the file
	 * @param list<Score> $matches Existing scores with the signature
	 * @return CatalogueAnalysedRow
	 */
	private function analyseRecord(array $header, array $record, string $signature, int $signatureCount, array $matches): array {
		$cells = $record['cells'];
		$errors = [];
		$score = null;

		if (count($cells) > count($header)) {
			$errors[] = $this->l->t('The row has %1$d values, but the header has only %2$d columns.', [count($cells), count($header)]);
		}
		if (trim($signature) === '') {
			$errors[] = $this->l->t('The signature is missing.');
		} elseif (trim($signature) !== $signature) {
			$errors[] = $this->l->t('The signature has leading or trailing spaces.');
		} elseif ($signatureCount > 1) {
			$errors[] = $this->l->t('The signature occurs more than once in the file.');
		} elseif (count($matches) > 1) {
			$errors[] = $this->l->t('The signature is used by more than one existing score.');
		} else {
			$score = $matches[0] ?? null;
		}

		$existingExtra = $score?->getCatalogueExtraValues() ?? [];
		$title = $score?->getTitle() ?? '';
		$changes = [];
		$fields = [];
		$extra = [];
		foreach ($header as $index => $column) {
			$cell = $cells[$index] ?? '';
			$definition = CatalogueColumns::definition($column);
			if ($definition === null) {
				$extra[$column] = $cell;
				$old = $existingExtra[$column] ?? '';
			} else {
				try {
					$fields[$column] = CatalogueColumns::parseCell($definition, $cell);
				} catch (CatalogueException $e) {
					$errors[] = $this->cellErrorMessage($column, $e);
					continue;
				}
				$old = $score === null ? '' : CatalogueColumns::formatField($definition, $score);
				if ($definition['field'] === 'title' && $cell !== '') {
					$title = $cell;
				}
				if ($old === $cell) {
					unset($fields[$column]);
				}
			}
			if ($old !== $cell) {
				$changes[] = ['column' => $column, 'old' => $old, 'new' => $cell];
			}
		}

		if ($score === null) {
			foreach (CatalogueColumns::FIELDS as $column => $definition) {
				if (($definition['required'] ?? false) && !in_array($column, $header, true)) {
					$errors[] = $this->l->t('New scores need the column "%s".', [$column]);
				}
			}
		}

		if ($errors !== []) {
			$status = 'error';
		} elseif ($score === null) {
			$status = 'new';
		} else {
			$status = $changes === [] ? 'unchanged' : 'changed';
		}

		return [
			'line' => $record['line'],
			'signature' => $signature,
			'title' => $title,
			'status' => $status,
			'score' => $score,
			'changes' => $changes,
			'errors' => $errors,
			'fields' => $fields,
			'extra' => $extra,
		];
	}

	/**
	 * Save new and changed rows in one transaction.
	 *
	 * @param list<string> $header
	 * @param list<CatalogueAnalysedRow> $rows
	 * @throws Exception
	 */
	private function apply(array $header, array $rows): void {
		$this->atomic(function () use ($rows): void {
			foreach ($rows as $row) {
				if ($row['status'] === 'new') {
					$score = new Score();
					$this->applyRow($score, $row);
					$this->scoreMapper->insert($score);
				} elseif ($row['status'] === 'changed' && $row['score'] !== null) {
					$this->applyRow($row['score'], $row);
					$this->scoreMapper->update($row['score']);
				}
			}
		}, $this->db);
		$this->configService->setCatalogueColumns($header);
	}

	/**
	 * Copy the values of an analysed row into a score.
	 *
	 * @param Score $score
	 * @param CatalogueAnalysedRow $row
	 */
	private function applyRow(Score $score, array $row): void {
		foreach ($row['fields'] as $column => $value) {
			$definition = CatalogueColumns::definition($column);
			if ($definition !== null) {
				CatalogueColumns::setField($definition, $score, $value);
			}
		}

		$extra = $score->getCatalogueExtraValues();
		foreach ($row['extra'] as $column => $cell) {
			if ($cell === '') {
				unset($extra[$column]);
			} else {
				$extra[$column] = $cell;
			}
		}
		foreach (array_keys($extra) as $column) {
			// values of columns that are mapped to a field by now
			if (CatalogueColumns::definition((string)$column) !== null) {
				unset($extra[$column]);
			}
		}
		$score->setCatalogueExtraValues($extra);
	}

	/**
	 * Build the API result; unchanged rows are only counted.
	 *
	 * @param list<string> $header
	 * @param list<CatalogueAnalysedRow> $rows
	 * @param bool $dryRun
	 * @return ChoirScoresCatalogueImportResult
	 */
	private function buildResult(array $header, array $rows, bool $dryRun): array {
		$counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'errors' => 0];
		$resultRows = [];
		foreach ($rows as $row) {
			$status = $row['status'];
			if ($status === 'unchanged') {
				$counts['unchanged']++;
				continue;
			}
			$counts[$status === 'error' ? 'errors' : $status]++;
			$resultRows[] = [
				'line' => $row['line'],
				'signature' => $row['signature'],
				'title' => $row['title'],
				'status' => $status,
				'scoreId' => $row['score']?->getId(),
				'changes' => $row['changes'],
				'errors' => $row['errors'],
			];
		}

		$mappedColumns = [];
		$extraColumns = [];
		foreach ($header as $column) {
			if (CatalogueColumns::definition($column) === null) {
				$extraColumns[] = $column;
			} else {
				$mappedColumns[] = $column;
			}
		}

		return [
			'dryRun' => $dryRun,
			'counts' => $counts,
			'mappedColumns' => $mappedColumns,
			'extraColumns' => $extraColumns,
			'rows' => $resultRows,
		];
	}

	/**
	 * Translate a problem with the whole file.
	 */
	private function fileErrorMessage(CatalogueException $e): string {
		return match ($e->reason) {
			CatalogueException::NOT_UTF8 => $this->l->t('The file is not valid UTF-8. Save it as "CSV UTF-8" and try again.'),
			CatalogueException::EMPTY_FILE => $this->l->t('The file is empty.'),
			CatalogueException::UNTERMINATED_QUOTE => $this->l->t('The quoted value starting in line %d is not closed.', [(int)($e->context['line'] ?? 0)]),
			CatalogueException::EMPTY_COLUMN_NAME => $this->l->t('Column %d of the header has no name.', [(int)($e->context['column'] ?? 0)]),
			CatalogueException::DUPLICATE_COLUMN => $this->l->t('The header contains the column "%s" more than once.', [(string)($e->context['name'] ?? '')]),
			default => $e->getMessage(),
		};
	}

	/**
	 * Translate a problem with a single value.
	 */
	private function cellErrorMessage(string $column, CatalogueException $e): string {
		return match ($e->reason) {
			CatalogueException::TOO_LONG => $this->l->t('%1$s: The value is longer than %2$d characters.', [$column, (int)($e->context['max'] ?? 0)]),
			CatalogueException::INVALID_DATE => $this->l->t('%s: The value is not a date in the form YYYY-MM-DD.', [$column]),
			CatalogueException::UNKNOWN_VALUE => $this->l->t('%1$s: The value is not one of: %2$s.', [$column, (string)($e->context['allowed'] ?? '')]),
			CatalogueException::REQUIRED => $this->l->t('%s: The value must not be empty.', [$column]),
			default => $column . ': ' . $e->getMessage(),
		};
	}
}
