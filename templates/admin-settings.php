<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\ChoirScores\AppInfo\Application::APP_ID, OCA\ChoirScores\AppInfo\Application::APP_ID . '-adminSettings');
Util::addStyle(OCA\ChoirScores\AppInfo\Application::APP_ID, OCA\ChoirScores\AppInfo\Application::APP_ID . '-adminSettings');
?>

<div id="orchestra-scores-admin-settings"></div>
