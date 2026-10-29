/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The page "Shared with me" for choir members (milestone 0.7): a small
 * bundle of its own, without the catalog.
 */
import { createApp } from 'vue'
import MemberApp from './sharing/MemberApp.vue'

createApp(MemberApp).mount('#cantoria-member')
