/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The page of a public link (milestone 0.7): one excerpt, viewed and played
 * without signing in. Everything it shows comes with the page; it makes no
 * further requests.
 */
import { createApp } from 'vue'
import PublicApp from './sharing/PublicApp.vue'

createApp(PublicApp).mount('#cantoria-public')
