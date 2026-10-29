/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
import { installEscapeClose } from './escapeClose.ts'

import '@nextcloud/dialogs/style.css'

installEscapeClose()

const app = createApp(App)
app.mount('#cantoria')
