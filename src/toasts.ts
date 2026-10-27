/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Error messages as toasts that stay until they are closed (milestone
 * 0.12): a message that vanishes after a few seconds cannot be read to the
 * end, least of all with a screen reader or a magnifier. Success messages
 * still go by themselves (showSuccess of @nextcloud/dialogs).
 */
import { showError as showToastError, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs'

/**
 * Shows an error message that stays until it is closed.
 *
 * @param text - the message, saying what to do
 */
export function showError(text: string): void {
	showToastError(text, { timeout: TOAST_PERMANENT_TIMEOUT })
}
