<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Notenarchiv\AppInfo\Application::APP_ID, OCA\Notenarchiv\AppInfo\Application::APP_ID . '-main');

?>

<div id="notenarchiv"></div>
