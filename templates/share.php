<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\OrchestraScoresManager\AppInfo\Application::APP_ID, OCA\OrchestraScoresManager\AppInfo\Application::APP_ID . '-share');
Util::addStyle(OCA\OrchestraScoresManager\AppInfo\Application::APP_ID, OCA\OrchestraScoresManager\AppInfo\Application::APP_ID . '-share');

?>

<div id="orchestrascoresmanager-share"></div>
