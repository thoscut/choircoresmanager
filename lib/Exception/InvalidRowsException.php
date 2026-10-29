<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Exception;

/**
 * Several entries of one request do not fit (HTTP 400): the message and,
 * for each faulty entry, its label (e.g. the shelf mark) and its own message,
 * so that the person sees which rows to correct.
 *
 * @psalm-type FaultyRow = array{row: string, message: string, parameters: list<string|int>}
 */
class InvalidRowsException extends InvalidInputException {
	/**
	 * @param list<FaultyRow> $rows
	 * @param list<string|int> $parameters
	 */
	public function __construct(
		string $message,
		private array $rows,
		array $parameters = [],
	) {
		parent::__construct($message, $parameters);
	}

	/**
	 * @return list<FaultyRow>
	 */
	public function getRows(): array {
		return $this->rows;
	}
}
