/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The message about a link or copy from the URL that could not be opened
 * (set on start, shown by AppNotices.vue under the heading of the view): an
 * error (role "alert") or a warning (role "status").
 */
import { ref } from 'vue'

export const startNotice = ref<{ text: string, type: 'error' | 'warning' } | null>(null)
