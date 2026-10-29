<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\Util;

// Administration → Cantoria (OCA\Cantoria\Settings\Admin). The page
// has its own small bundle, without the catalog and the score viewer.
Util::addScript(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-admin-settings');
Util::addStyle(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-admin-settings');

?>

<div id="cantoria-admin-settings"></div>
