<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Controller;

use OCA\Cantoria\Exception\AccessDeniedException;
use OCA\Cantoria\Exception\ModuleDisabledException;
use OCA\Cantoria\Exception\NotFoundException;
use OCA\Cantoria\Exception\UserFacingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

/**
 * Request handling of the API for agents (v1, OCS controllers): runs an
 * action for the logged-in account and maps the domain exceptions to OCS
 * exceptions, which Nextcloud answers with the OCS envelope and the
 * translated message in `ocs.meta.message`:
 *
 * - 400 invalid input (UserFacingException),
 * - 403 not permitted (AccessDeniedException),
 * - 404 object not found (NotFoundException) or function switched off,
 * - 500 internal error (logged; the client only gets a generic message).
 *
 * Expects `$userId`, `$l` (IL10N) and `$logger`.
 */
trait RunsOcsActions {
	use TranslatesMessages;

	/**
	 * @template T
	 * @param callable(string): T $action receives the account id
	 * @return T
	 * @throws OCSBadRequestException
	 * @throws OCSForbiddenException
	 * @throws OCSNotFoundException
	 * @throws OCSException
	 */
	private function ocs(callable $action): mixed {
		if ($this->userId === null) {
			// Nextcloud refuses requests without login before; kept as a guard.
			throw new OCSForbiddenException($this->l->t('Not logged in.'));
		}
		try {
			return $action($this->userId);
		} catch (OCSException $e) {
			throw $e;
		} catch (NotFoundException $e) {
			throw new OCSNotFoundException($this->translate($e->getMessage(), $e->getParameters()));
		} catch (AccessDeniedException $e) {
			throw new OCSForbiddenException($this->translate($e->getMessage(), $e->getParameters()));
		} catch (UserFacingException $e) {
			throw new OCSBadRequestException($this->translate($e->getMessage(), $e->getParameters()));
		} catch (ModuleDisabledException) {
			// A part of the action belongs to a module that is switched off.
			throw new OCSNotFoundException($this->l->t('This function is switched off.'));
		} catch (\Throwable $e) {
			$this->logger->error('Cantoria API v1: ' . $e->getMessage(), ['exception' => $e]);
			throw new OCSException($this->l->t('Internal error. Details are in the Nextcloud log.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
