/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { addMark, MAX_MARKS, MAX_NAME, storedMarks, storeMarks } from '../score/marks.ts'

describe('passages to practise', () => {
	afterEach(() => window.localStorage.clear())

	it('are remembered per piece in this browser', () => {
		storeMarks('copy:1', [{ name: 'Fuge', from: '5', to: '12' }])
		expect(storedMarks('copy:1')).toEqual([{ name: 'Fuge', from: '5', to: '12' }])
		expect(storedMarks('copy:2')).toEqual([])
		storeMarks('copy:1', [])
		expect(window.localStorage.getItem('notenarchiv.marks.copy:1')).toBeNull()
	})

	it('ignore broken entries in the storage', () => {
		window.localStorage.setItem('notenarchiv.marks.copy:1', '{no json')
		expect(storedMarks('copy:1')).toEqual([])
		window.localStorage.setItem('notenarchiv.marks.copy:1', JSON.stringify([{ name: 'A', from: '1', to: '2' }, { name: 3 }, null]))
		expect(storedMarks('copy:1')).toEqual([{ name: 'A', from: '1', to: '2' }])
	})

	it('take the measures as name when none is given and rename the same measures', () => {
		let marks = addMark([], { name: '  ', from: '5', to: '12' }, 'm. 5–12')
		expect(marks).toEqual([{ name: 'm. 5–12', from: '5', to: '12' }])
		marks = addMark(marks, { name: 'Coda', from: '20', to: '24' }, 'm. 20–24')
		marks = addMark(marks, { name: 'Fuge', from: '5', to: '12' }, 'm. 5–12')
		expect(marks.map((mark) => mark.name)).toEqual(['Coda', 'Fuge'])
		expect(addMark([], { name: 'x'.repeat(100), from: '1', to: '1' }, '')[0].name).toHaveLength(MAX_NAME)
	})

	it('keep at most MAX_MARKS, dropping the oldest', () => {
		let marks = addMark([], { name: 'first', from: '0', to: '0' }, '')
		for (let i = 1; i <= MAX_MARKS; i++) {
			marks = addMark(marks, { name: `m${i}`, from: String(i), to: String(i) }, '')
		}
		expect(marks).toHaveLength(MAX_MARKS)
		expect(marks[0].name).toBe('m1')
	})
})
