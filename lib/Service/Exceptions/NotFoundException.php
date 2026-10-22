<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Service\Exceptions;

/**
 * Thrown by services when a requested entity does not exist.
 * Translated to an HTTP 404 response by the controllers.
 */
class NotFoundException extends \RuntimeException {
}
