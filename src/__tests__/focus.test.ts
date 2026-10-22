// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { elementOf, focusAfterUpdate, focusElement, focusWhenPresent } from '../focus.ts'

afterEach(() => {
	document.body.innerHTML = ''
})

describe('focus helpers', () => {
	it('takes the element of a component instance', () => {
		const button = document.createElement('button')
		expect(elementOf(button)).toBe(button)
		expect(elementOf({ $el: button })).toBe(button)
		expect(elementOf({ $el: 'text' })).toBeNull()
		expect(elementOf(null)).toBeNull()
	})

	it('focuses a button directly', () => {
		document.body.innerHTML = '<button id="b">Save</button>'
		expect(focusElement(document.getElementById('b'))).toBe(true)
		expect(document.activeElement?.id).toBe('b')
	})

	it('passes the focus of a wrapper to its first control', () => {
		document.body.innerHTML = '<div id="w"><label for="i">Title</label><input id="i"></div>'
		expect(focusElement({ $el: document.getElementById('w') })).toBe(true)
		expect(document.activeElement?.id).toBe('i')
	})

	it('makes a heading or a message focusable for scripts only', () => {
		document.body.innerHTML = '<h2 id="h">Concert <button>Edit</button></h2><div id="n" class="notecard">Done <a href="#">open</a></div>'
		expect(focusElement(document.getElementById('h'))).toBe(true)
		expect(document.activeElement?.id).toBe('h')
		expect(document.getElementById('h')?.getAttribute('tabindex')).toBe('-1')
		expect(focusElement(document.getElementById('n'))).toBe(true)
		expect(document.activeElement?.id).toBe('n')
	})

	it('skips disabled controls and elements that are gone', () => {
		document.body.innerHTML = '<div id="w"><button disabled>A</button><button id="b">B</button></div>'
		focusElement(document.getElementById('w'))
		expect(document.activeElement?.id).toBe('b')
		const gone = document.createElement('button')
		expect(focusElement(gone)).toBe(false)
	})

	it('accepts a getter after the next update', async () => {
		document.body.innerHTML = '<input id="i">'
		expect(await focusAfterUpdate(() => document.getElementById('i'))).toBe(true)
		expect(document.activeElement?.id).toBe('i')
	})

	it('waits for an element that appears later', async () => {
		const waiting = focusWhenPresent('late', 1000)
		const heading = document.createElement('h2')
		heading.id = 'late'
		document.body.append(heading)
		expect(await waiting).toBe(true)
		expect(document.activeElement?.id).toBe('late')
		expect(await focusWhenPresent('never', 20)).toBe(false)
	})
})
