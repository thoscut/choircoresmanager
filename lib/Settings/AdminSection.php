<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Settings;

use OCA\Notenarchiv\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The section "Music archive" under Administration (registered in
 * appinfo/info.xml). Admins see it, and the members of the groups that the
 * administration has given the page under Administration privileges.
 */
class AdminSection implements IIconSection {
	public const ID = Application::APP_ID;

	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	#[\Override]
	public function getID(): string {
		return self::ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Music archive');
	}

	/**
	 * Among the sections of apps, after the sections of Nextcloud itself.
	 */
	#[\Override]
	public function getPriority(): int {
		return 75;
	}

	/**
	 * The dark variant of the app icon, like the icons of Nextcloud's own
	 * sections; the dark theme inverts it.
	 */
	#[\Override]
	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
