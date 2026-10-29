<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Exception;

/**
 * What was asked for is not there, or not for this account. Both APIs answer
 * 404 (the internal one through RunsActions, the API for agents through
 * RunsOcsActions); the message must not tell the two cases apart. It extends
 * InvalidInputException, so code that handles invalid input in general also
 * handles it.
 */
class NotFoundException extends InvalidInputException {
}
