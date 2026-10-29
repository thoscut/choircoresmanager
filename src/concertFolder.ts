/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The concert folder of the conductor (milestone 0.15): a small bundle of
 * its own, without the catalog, that also works without a network.
 */
import { createApp } from 'vue'
import ConcertFolderApp from './sharing/ConcertFolderApp.vue'

createApp(ConcertFolderApp).mount('#cantoria-concert-folder')
