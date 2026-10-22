<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\Util;

// Administration → Music archive (OCA\Notenarchiv\Settings\Admin). The page
// has its own small bundle, without the catalog and the score viewer.
Util::addScript(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-admin-settings');
Util::addStyle(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-admin-settings');

?>

<div id="notenarchiv-admin-settings"></div>
