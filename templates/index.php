<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\Util;

Util::addScript(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-main');

?>

<div id="notenarchiv"></div>
