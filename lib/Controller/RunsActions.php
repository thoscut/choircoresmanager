<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
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
			$status = $e instanceof AccessDeniedException ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			$response = new JSONResponse(['error' => $this->translate($e->getMessage(), $e->getParameters())], $status);
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
