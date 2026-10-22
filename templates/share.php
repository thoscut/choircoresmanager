<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\ChoirScores\AppInfo\Application::APP_ID, OCA\ChoirScores\AppInfo\Application::APP_ID . '-share');
Util::addStyle(OCA\ChoirScores\AppInfo\Application::APP_ID, OCA\ChoirScores\AppInfo\Application::APP_ID . '-share');

?>

<div id="choirscores-share"></div>
