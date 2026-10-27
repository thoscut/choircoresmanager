/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Messages for error responses that carry no message of the app. Kept apart
 * from api.ts so that the small script of the Files app can use them too.
 */
import { t } from './l10n.ts'

/**
 * What to do after an error response without a message of the app, by its
 * HTTP status (milestone 0.12: no bare "HTTP 500").
 *
 * @param status - HTTP status
 * @param requestId - request ID of Nextcloud, to find the entry in the log
 */
export function statusMessage(status: number, requestId = ''): string {
	if (status === 401) {
		return t('notenarchiv', 'You are no longer logged in. Please reload the page and log in again.')
	}
	if (status === 403) {
		return t('notenarchiv', 'Your account may not do this. Please ask the music librarians for the role you need.')
	}
	if (status === 404) {
		return t('notenarchiv', 'This no longer exists or is switched off. Please reload the page.')
	}
	if (status === 413) {
		return t('notenarchiv', 'The file is too large for the server. Please choose a smaller file or ask the administration to allow larger uploads.')
	}
	if (status >= 500) {
		return requestId !== ''
			? t('notenarchiv', 'The server could not finish this. Please try again in a moment. If it happens again, tell the administration this request ID for the Nextcloud log: {id}', { id: requestId })
			: t('notenarchiv', 'The server could not finish this. Please try again in a moment. If it happens again, tell the administration the time, so that it finds the error in the Nextcloud log.')
	}
	return t('notenarchiv', 'The server refused the request (HTTP {status}). Please reload the page and try again.', { status })
}
