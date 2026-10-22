<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Exception;

/**
 * The archive folder is not shared with the account: the app reads files
 * only with the permissions of the person who uses it. The JSON response
 * carries `reason: "not_shared"`, so that the UI can explain what to do and
 * point people with the right to change the settings to the access check.
 */
class NotSharedException extends AccessDeniedException {
	public const REASON = 'not_shared';
}
