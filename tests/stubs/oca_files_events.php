<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Stub of an event of the Files app, which is not part of the nextcloud/ocp
 * package: the Files app dispatches it when it renders its page, so that other
 * apps can add their scripts. Used by Psalm and by the unit tests.
 * As in Nextcloud 33 to 35 (apps/files/lib/Event/LoadAdditionalScriptsEvent.php).
 */

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

/**
 * This event is triggered when the files app is rendered.
 *
 * @since 17.0.0
 */
class LoadAdditionalScriptsEvent extends Event {
}
