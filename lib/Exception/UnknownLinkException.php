<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Exception;

/**
 * No excerpt link has this token (HTTP 400). The controller marks such
 * requests for Nextcloud's brute-force protection, so that tokens cannot be
 * guessed by trying.
 */
class UnknownLinkException extends InvalidInputException {
}
