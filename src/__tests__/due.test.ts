// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { dueResult } from '../api.ts'
import type { DueConcert, DueResult } from '../types.ts'

describe('dueResult', () => {
	it('keeps the concerts with a deadline now', () => {
		const concert = { id: 7 } as unknown as DueConcert
		const result = dueResult({ cards: [], tasks: [], concerts: [concert] })
		expect(result.concerts).toEqual([concert])
	})

	it('gives empty lists for missing or broken parts', () => {
		const empty: DueResult = { cards: [], tasks: [], concerts: [] }
		expect(dueResult(undefined)).toEqual(empty)
		expect(dueResult({ cards: null } as unknown as Partial<DueResult>)).toEqual(empty)
	})
})
