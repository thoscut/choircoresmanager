<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

// Only the app's own PHP code. Never scan the whole project directory: it may
// contain local, non-public material (e.g. _intern/) and third-party code.
$config = new Config();
$config
	->getFinder()
	->in([
		__DIR__ . '/lib',
		__DIR__ . '/templates',
		__DIR__ . '/tests',
	])
	->append([
		__DIR__ . '/.php-cs-fixer.dist.php',
		__DIR__ . '/rector.php',
	]);

return $config;
