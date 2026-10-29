/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Entry point of the page Administration → Music archive
 * (templates/settings-admin.php). Only the settings: no catalog, no score
 * viewer, so the bundle stays small.
 */
import { createApp } from 'vue'
import AdminSettings from './components/settings/AdminSettings.vue'
import { installEscapeClose } from './escapeClose.ts'

import '@nextcloud/dialogs/style.css'

installEscapeClose()

createApp(AdminSettings).mount('#cantoria-admin-settings')
