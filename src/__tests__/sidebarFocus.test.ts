/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

vi.mock('../api.ts', async (importOriginal) => ({
	...await importOriginal<typeof import('../api.ts')>(),
	fetchEntry: vi.fn(() => new Promise(() => {})),
}))

const { closeEntry, openEntry, SIDEBAR_ID, sidebarTakesFocus } = await import('../store.ts')

afterEach(() => {
	closeEntry()
	document.body.innerHTML = ''
})

describe('focus of the sidebar', () => {
	it('goes back to the row the sidebar was opened from', async () => {
		document.body.innerHTML = `<button id="row">2-1</button><aside id="${SIDEBAR_ID}"><button id="inside">x</button></aside>`
		const row = document.getElementById('row') as HTMLElement
		row.focus()
		openEntry(35)
		expect(sidebarTakesFocus.value).toBe(true)
		// another copy chosen inside the sidebar keeps the row as the way back
		;(document.getElementById('inside') as HTMLElement).focus()
		openEntry(36)
		closeEntry()
		await nextTick()
		expect(document.activeElement).toBe(row)
		expect(sidebarTakesFocus.value).toBe(false)
	})

	it('does not take the focus when opened without a control, e.g. from the address', async () => {
		openEntry(35)
		expect(sidebarTakesFocus.value).toBe(false)
		closeEntry()
		await nextTick()
		expect(document.activeElement).toBe(document.body)
	})
})
