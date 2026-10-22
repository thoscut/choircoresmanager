/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { getLanguage, setLanguage } from '@nextcloud/l10n'
import { afterEach, describe, expect, it } from 'vitest'
import { ApiError, isPermanentHttpError } from '../api.ts'
import { ADMIN_GUIDE, adminGuideUrl, helpUrl } from '../docs.ts'

describe('isPermanentHttpError', () => {
	it('says that trying again cannot help without access or without the file', () => {
		expect(isPermanentHttpError(new ApiError('The file is not accessible for this account.', null, 403))).toBe(true)
		expect(isPermanentHttpError(new ApiError('Not found', null, 404))).toBe(true)
	})

	it('lets server errors, rate limits and network errors be tried again', () => {
		expect(isPermanentHttpError(new ApiError('Server error', null, 500))).toBe(false)
		expect(isPermanentHttpError(new ApiError('Too many requests', null, 429))).toBe(false)
		expect(isPermanentHttpError(new ApiError('The server cannot be reached.', null, 0))).toBe(false)
		expect(isPermanentHttpError(new Error('anything'))).toBe(false)
		expect(isPermanentHttpError(null)).toBe(false)
	})

	it('keeps the message for the person', () => {
		const error = new ApiError('Die Datei ist für dieses Konto nicht zugänglich.', null, 403)
		expect(error).toBeInstanceOf(Error)
		expect(error.message).toBe('Die Datei ist für dieses Konto nicht zugänglich.')
		expect(error.status).toBe(403)
	})
})

describe('documentation links', () => {
	const before = getLanguage()

	afterEach(() => {
		setLanguage(before)
	})

	it('opens the German quick start for a German interface', () => {
		setLanguage('de')
		expect(helpUrl()).toBe('https://github.com/thoscut/choircoresmanager/blob/main/docs/de/schnellstart.md')
		setLanguage('de_DE')
		expect(helpUrl()).toBe('https://github.com/thoscut/choircoresmanager/blob/main/docs/de/schnellstart.md')
	})

	it('opens the English user guide otherwise', () => {
		setLanguage('en')
		expect(helpUrl()).toBe('https://github.com/thoscut/choircoresmanager/blob/main/docs/user-guide.md')
	})

	it('links a section of the admin guide', () => {
		expect(adminGuideUrl(ADMIN_GUIDE.archive)).toBe('https://github.com/thoscut/choircoresmanager/blob/main/docs/admin-guide.md#3-archive-folder')
	})
})
