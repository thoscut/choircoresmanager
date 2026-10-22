/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { onEscapeKeydown } from '../escapeClose.ts'

/**
 * A dialog as rendered by @nextcloud/vue: mask, content and close button.
 *
 * @param id - id of the mask
 * @param content - inner HTML of the dialog
 */
function dialog(id: string, content: string): string {
	return `<div class="modal-mask" id="${id}"><div class="modal-container">${content}<button class="modal-container__close">Close</button></div></div>`
}

/**
 * Press Escape on an element.
 *
 * @param target - focused element
 */
function escape(target: HTMLElement): KeyboardEvent {
	const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
	target.addEventListener('keydown', onEscapeKeydown)
	target.dispatchEvent(event)
	target.removeEventListener('keydown', onEscapeKeydown)
	return event
}

beforeEach(() => {
	// jsdom has no layout: every element counts as visible
	vi.spyOn(Element.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
})

afterEach(() => {
	vi.restoreAllMocks()
	document.body.innerHTML = ''
})

describe('Escape in form fields of dialogs', () => {
	it('closes the dialog from a text field', () => {
		document.body.innerHTML = dialog('d', '<input id="i">')
		const close = vi.fn()
		document.querySelector('.modal-container__close')?.addEventListener('click', close)
		const event = escape(document.getElementById('i') as HTMLElement)
		expect(close).toHaveBeenCalledOnce()
		expect(event.defaultPrevented).toBe(true)
	})

	it('leaves Escape to an open list of options', () => {
		document.body.innerHTML = dialog('d', '<div class="v-select vs--open"><input id="i"></div>')
		const close = vi.fn()
		document.querySelector('.modal-container__close')?.addEventListener('click', close)
		escape(document.getElementById('i') as HTMLElement)
		expect(close).not.toHaveBeenCalled()
	})

	it('only closes the topmost dialog', () => {
		document.body.innerHTML = dialog('below', '<textarea id="t"></textarea>') + dialog('top', '<p>File picker</p>')
		const below = vi.fn()
		document.querySelector('#below .modal-container__close')?.addEventListener('click', below)
		escape(document.getElementById('t') as HTMLElement)
		expect(below).not.toHaveBeenCalled()
	})

	it('ignores fields outside dialogs and other keys', () => {
		document.body.innerHTML = '<input id="i">' + dialog('d', '<input id="j">')
		const close = vi.fn()
		document.querySelector('.modal-container__close')?.addEventListener('click', close)
		escape(document.getElementById('i') as HTMLElement)
		const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })
		const field = document.getElementById('j') as HTMLElement
		field.addEventListener('keydown', onEscapeKeydown)
		field.dispatchEvent(enter)
		expect(close).not.toHaveBeenCalled()
	})

	it('does nothing when the dialog may not be closed (no close button)', () => {
		document.body.innerHTML = '<div class="modal-mask"><div class="modal-container"><input id="i"></div></div>'
		const event = escape(document.getElementById('i') as HTMLElement)
		expect(event.defaultPrevented).toBe(false)
	})
})
