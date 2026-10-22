<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Bootstrap of the unit tests (tests/Unit). They run without a Nextcloud
 * server: the app code and the tests come from the Composer autoloader, the
 * public Nextcloud API (OCP) from the nextcloud/ocp package in the version of
 * the oldest supported Nextcloud (autoload-dev in composer.json).
 *
 * The integration tests in tests/Integration do not use this bootstrap; they
 * load a real Nextcloud instance (see tests/README.md).
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
	fwrite(STDERR, "vendor/autoload.php is missing. Run `composer install` in the app directory first.\n");
	exit(1);
}
require_once $autoload;

if (!interface_exists(\OCP\IDBConnection::class)) {
	fwrite(STDERR, "The Nextcloud public API (OCP) cannot be loaded. Is nextcloud/ocp installed (composer install with dev dependencies)?\n");
	exit(1);
}

// OCP\Files\IRootFolder extends this private server interface.
if (!interface_exists(\OC\Hooks\Emitter::class)) {
	require_once __DIR__ . '/stubs/oc_hooks_emitter.php';
}

// Event of the Files app (not part of OCP) that the app listens to.
if (!class_exists(\OCA\Files\Event\LoadAdditionalScriptsEvent::class)) {
	require_once __DIR__ . '/stubs/oca_files_events.php';
}

// Dates in the tests must not depend on the machine running them.
date_default_timezone_set('UTC');
