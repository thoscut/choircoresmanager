<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Controller;

use OCA\OrchestraScoresManager\Service\Exceptions\NotFoundException;
use OCA\OrchestraScoresManager\Service\Exceptions\PermissionDeniedException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;

trait ServiceExceptionBridgeTrait {
	/**
	 * Translate service-level errors in OCS exceptions.
	 * Keep other exceptions untouched so they bubble up normally.
	 *
	 * @template T
	 * @param callable(): T $callable
	 * @return T
	 * @throws OCSForbiddenException
	 * @throws OCSBadRequestException
	 * @throws OCSNotFoundException
	 */
	private function callService(callable $callable) {
		try {
			return $callable();
		} catch (PermissionDeniedException $e) {
			throw new OCSForbiddenException($e->getMessage());
		} catch (NotFoundException $e) {
			throw new OCSNotFoundException($e->getMessage());
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}
}
