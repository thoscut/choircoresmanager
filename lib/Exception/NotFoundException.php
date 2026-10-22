<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Exception;

/**
 * The requested object does not exist. The internal API answers 400 as for
 * any other invalid input (unchanged since 0.1); the API for agents (v1)
 * answers 404.
 */
class NotFoundException extends InvalidInputException {
}
