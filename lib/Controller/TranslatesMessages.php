<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

/**
 * Translation of the messages of user-facing exceptions, shared by the JSON
 * controllers of the UI (RunsActions) and the API for agents
 * (RunsOcsActions). Expects `$l` (IL10N).
 */
trait TranslatesMessages {
	/**
	 * Translates a message and its parameters. Parameters are mostly field or
	 * role labels and are translated too; `%` is escaped first because
	 * IL10N::t() always runs vsprintf and parameters may contain user input.
	 *
	 * @param list<string|int> $parameters
	 */
	private function translate(string $message, array $parameters): string {
		$translated = array_map(fn (string|int $p): string => $this->l->t(str_replace('%', '%%', (string)$p)), $parameters);
		return $this->l->t($message, $translated);
	}
}
