<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCA\Notenarchiv\Exception\NotSharedException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Exception\UserFacingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Shared request handling of the JSON controllers: runs an action for the
 * logged-in user and maps domain exceptions to HTTP responses with a
 * translated message. Expects `$userId`, `$l` (IL10N) and `$logger`.
 *
 * @psalm-import-type Warning from \OCA\Notenarchiv\Service\ImportService
 */
trait RunsActions {
	use TranslatesMessages;

	/**
	 * @param callable(string): (array|\JsonSerializable) $action
	 */
	private function run(callable $action): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->l->t('Not logged in.')], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse($action($this->userId));
		} catch (UserFacingException $e) {
			$response = $this->errorResponse($e);
			if ($e instanceof UnknownLinkException) {
				// A guessed token: counts for Nextcloud's brute-force protection
				// (only on methods with #[BruteForceProtection]).
				$response->throttle(['reason' => 'unknown_token']);
			}
			return $response;
		} catch (ModuleDisabledException) {
			// A part of the action belongs to a module that is switched off.
			return new JSONResponse(['error' => $this->l->t('This function is switched off.')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Notenarchiv: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => $this->l->t('Internal error. Details are in the Nextcloud log.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * `{ error }` with 403 for a refused permission, 404 for something not
	 * there (or not for this account), 400 otherwise. A file or folder that is
	 * not shared with the account adds `reason: "not_shared"`.
	 */
	private function errorResponse(UserFacingException $e): JSONResponse {
		$status = match (true) {
			$e instanceof AccessDeniedException => Http::STATUS_FORBIDDEN,
			$e instanceof NotFoundException => Http::STATUS_NOT_FOUND,
			default => Http::STATUS_BAD_REQUEST,
		};
		$data = ['error' => $this->translate($e->getMessage(), $e->getParameters())];
		if ($e instanceof NotSharedException) {
			$data['reason'] = NotSharedException::REASON;
		}
		return new JSONResponse($data, $status);
	}

	/**
	 * Translates import warnings ([message, parameters] pairs).
	 *
	 * @param list<Warning> $warnings
	 * @return list<string>
	 */
	private function translateWarnings(array $warnings): array {
		$texts = [];
		foreach ($warnings as [$message, $parameters]) {
			$texts[] = $this->translate($message, $parameters);
		}
		return $texts;
	}

	/**
	 * @param list<string> $texts English source strings without parameters
	 * @return list<string>
	 */
	private function translateTexts(array $texts): array {
		return array_map(fn (string $text): string => $this->l->t($text), $texts);
	}
}
