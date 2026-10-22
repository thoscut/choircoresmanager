<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Settings;

use OCA\Notenarchiv\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * The settings of the app under Administration → Music archive: archive
 * folder, roles and groups, modules, repertoire, access to the archive
 * folder, complete backup and restore. The page itself is a Vue app
 * (src/adminSettings.ts) that talks to the settings routes of the app.
 *
 * The administration can give the page to a group under Administration
 * privileges (IDelegatedSettings); the settings routes carry
 * #[AuthorizedAdminSetting(settings: Admin::class)]. Restoring a backup is
 * not part of that: it stays with admins and the catalog role.
 */
class Admin implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings-admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	#[\Override]
	public function getSection(): string {
		return AdminSection::ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 10;
	}

	/**
	 * The only settings of the section: Administration privileges list it
	 * under the name of the section.
	 */
	#[\Override]
	public function getName(): ?string {
		return null;
	}

	/**
	 * No app config through Nextcloud's generic config API: every setting is
	 * changed through the app's own routes, which check the values and record
	 * changes of modules in the audit log.
	 *
	 * @return array<string, list<string>>
	 */
	#[\Override]
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
