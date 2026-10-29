<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The page "Shared with me" of the choir members (milestone 0.7): its own
// small bundle, nothing of the catalog.

use OCP\Util;

Util::addScript(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-member');
Util::addStyle(OCA\Cantoria\AppInfo\Application::APP_ID, OCA\Cantoria\AppInfo\Application::APP_ID . '-member');

?>

<div id="cantoria-member"></div>
