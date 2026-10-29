<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Exception;

/** The current user is not permitted to do this (HTTP 403). */
class AccessDeniedException extends UserFacingException {
}
