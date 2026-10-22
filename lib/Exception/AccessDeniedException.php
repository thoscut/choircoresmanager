<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Exception;

/** The current user is not permitted to do this (HTTP 403). */
class AccessDeniedException extends UserFacingException {
}
