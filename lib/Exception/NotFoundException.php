<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Exception;

/**
 * What was asked for is not there, or not for this account (HTTP 404). The
 * message must not tell the two apart.
 */
class NotFoundException extends UserFacingException {
}
