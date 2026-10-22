<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The page of a public link (milestone 0.7) in Nextcloud's public layout:
// one excerpt, everything it shows comes with the page (initial state).

use OCP\Util;

Util::addScript(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-public');
Util::addStyle(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-public');

?>

<div id="notenarchiv-public"></div>
