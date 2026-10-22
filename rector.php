<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: CC0-1.0
 */

use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/lib',
		__DIR__ . '/tests',
	])
	->withPhpSets(php82: true)
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
		codingStyle: true,
		typeDeclarations: true,
		privatization: true,
		instanceOf: true,
		earlyReturn: true,
		strictBooleans: true,
		rectorPreset: true,
		phpunitCodeQuality: true,
		doctrineCodeQuality: true,
		symfonyCodeQuality: true,
		symfonyConfigs: true,
	);
