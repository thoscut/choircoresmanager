<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\OrchestraScoresManager\AppInfo\Application::APP_ID, OCA\OrchestraScoresManager\AppInfo\Application::APP_ID . '-public');
Util::addStyle(OCA\OrchestraScoresManager\AppInfo\Application::APP_ID, OCA\OrchestraScoresManager\AppInfo\Application::APP_ID . '-public');

?>

<div id="orchestrascoresmanager-public"></div>
