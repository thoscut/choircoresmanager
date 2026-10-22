/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Voice } from '../score/voices.ts'

import { describe, expect, it } from 'vitest'
import { excerptTempo, voiceForServer, voiceOfLink } from '../sharing/excerpt.ts'

const voice = (key: string, name: string | null, part: string, number = 1): Voice => ({
	key, name, part, number, members: [{ staff: Number(key.split('.')[0]), layer: Number(key.split('.')[1]) }], notes: 10,
})

const voices = [voice('1.1', 'Tenor I', 'Tenor I/II'), voice('1.2', 'Tenor II', 'Tenor I/II', 2), voice('2.1', null, 'Bass')]
const labelOf = (v: Voice): string => v.name ?? `${v.part}, voice ${v.number}`

describe('excerptTempo', () => {
	it('keeps the tempo of a link within the slider and in steps of 5', () => {
		expect(excerptTempo(null)).toBe(100)
		expect(excerptTempo(undefined)).toBe(100)
		expect(excerptTempo(80)).toBe(80)
		expect(excerptTempo(83)).toBe(85)
		expect(excerptTempo(20)).toBe(25)
		expect(excerptTempo(300)).toBe(200)
		expect(excerptTempo(0)).toBe(100)
		expect(excerptTempo(Number.NaN)).toBe(100)
	})
})

describe('voiceOfLink', () => {
	it('finds the voice by its display name, ignoring case and spaces', () => {
		expect(voiceOfLink(voices, ' tenor ii ', labelOf)?.key).toBe('1.2')
		expect(voiceOfLink(voices, 'Bass, voice 1', labelOf)?.key).toBe('2.1')
	})

	it('gives no voice for an empty or unknown name', () => {
		expect(voiceOfLink(voices, null, labelOf)).toBeNull()
		expect(voiceOfLink(voices, '', labelOf)).toBeNull()
		expect(voiceOfLink(voices, 'Sopran', labelOf)).toBeNull()
	})
})

describe('voiceForServer', () => {
	it('drops control characters and keeps at most 100 characters', () => {
		expect(voiceForServer(' Tenor\u0007 II ')).toBe('Tenor II')
		expect(voiceForServer('x'.repeat(120))).toHaveLength(100)
	})
})
