/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { statusMessage } from '../serverErrors.ts'

describe('messages for server errors', () => {
	it('say what to do instead of a bare status', () => {
		expect(statusMessage(401)).toContain('log in again')
		expect(statusMessage(403)).toContain('role')
		expect(statusMessage(404)).toContain('reload the page')
		expect(statusMessage(413)).toContain('smaller file')
		for (const status of [401, 403, 404, 413, 500, 503]) {
			expect(statusMessage(status)).not.toMatch(/HTTP/)
		}
	})

	it('give the request ID of a server error for the log', () => {
		expect(statusMessage(500, 'abc123')).toContain('abc123')
		expect(statusMessage(502)).toContain('the time')
	})

	it('name the status only when there is nothing better to say', () => {
		expect(statusMessage(409)).toContain('HTTP 409')
	})
})
