<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\Util;

Util::addScript(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-main');

?>

<div id="cantoria"></div>
