/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { FOCUS_CLASS, markFocus, MUTED_CLASS, SHARED_CLASS } from '../score/emphasis.ts'
import { renderPages, VEROVIO_OPTIONS } from '../score/model.ts'
import { sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'

/** Tenor I and II on one staff (lyrics with Tenor I, slur in Tenor II), Bass with a tie. */
const SCORE = `<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0">
<part-list>
<score-part id="P1"><part-name>Tenor I/II</part-name></score-part>
<score-part id="P2"><part-name>Bass</part-name></score-part>
</part-list>
<part id="P1"><measure number="1">
<attributes><divisions>1</divisions><key><fifths>0</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line><clef-octave-change>-1</clef-octave-change></clef></attributes>
<direction placement="below"><direction-type><dynamics><f/></dynamics></direction-type></direction>
<note><pitch><step>E</step><octave>4</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type><stem>up</stem><lyric><text>Lied</text></lyric></note>
<note><pitch><step>F</step><octave>4</octave></pitch><duration>1</duration><voice>1</voice><type>quarter</type><stem>up</stem><lyric><text>chen</text></lyric></note>
<backup><duration>2</duration></backup>
<note><pitch><step>C</step><octave>4</octave></pitch><duration>1</duration><voice>2</voice><type>quarter</type><stem>down</stem><notations><slur type="start" number="1"/></notations></note>
<note><pitch><step>D</step><octave>4</octave></pitch><duration>1</duration><voice>2</voice><type>quarter</type><stem>down</stem><notations><slur type="stop" number="1"/></notations></note>
</measure></part>
<part id="P2"><measure number="1">
<attributes><divisions>1</divisions><key><fifths>0</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>F</sign><line>4</line></clef></attributes>
<direction placement="below"><direction-type><dynamics><p/></dynamics></direction-type></direction>
<note><pitch><step>C</step><octave>3</octave></pitch><duration>1</duration><tie type="start"/><voice>1</voice><type>quarter</type><notations><tied type="start"/></notations></note>
<note><pitch><step>C</step><octave>3</octave></pitch><duration>1</duration><tie type="stop"/><voice>1</voice><type>quarter</type><notations><tied type="stop"/></notations></note>
</measure></part>
</score-partwise>`

/** The rendered, cleaned score in a container, as in the score view. */
async function renderedScore(): Promise<HTMLElement> {
	const toolkit = await createToolkit()
	try {
		toolkit.setOptions(VEROVIO_OPTIONS)
		expect(toolkit.loadData(SCORE)).toBeTruthy()
		const container = document.createElement('div')
		container.innerHTML = renderPages(toolkit).map(sanitizeSvg).join('')
		return container
	} finally {
		toolkit.destroy()
	}
}

const layer = (root: HTMLElement, staff: number, n: number) => root.querySelector(`g.staff[data-n="${staff}"] g.layer[data-n="${n}"]`)

describe('markFocus', () => {
	it('emphasises the focus voice within a staff and mutes the other voices with their marks', async () => {
		const root = await renderedScore()
		// Tenor II: second layer of the first staff
		markFocus(root, [{ staff: 1, layer: 2 }])

		expect(layer(root, 1, 2)?.classList.contains(FOCUS_CLASS)).toBe(true)
		expect(layer(root, 1, 2)?.classList.contains(MUTED_CLASS)).toBe(false)
		expect(layer(root, 1, 1)?.classList.contains(MUTED_CLASS)).toBe(true)
		expect(layer(root, 2, 1)?.classList.contains(MUTED_CLASS)).toBe(true)
		expect(root.querySelectorAll(`.${FOCUS_CLASS}`)).toHaveLength(1)

		// the lyrics printed with Tenor I are also the text of Tenor II: they stay
		const verses = [...root.querySelectorAll('g.verse')]
		expect(verses.length).toBe(2)
		expect(verses.every((verse) => verse.closest(`.${MUTED_CLASS}`) !== null && verse.classList.contains(SHARED_CLASS))).toBe(true)

		// dynamics: the one of the shared staff stays, the one of the bass staff is muted
		const dynamics = [...root.querySelectorAll('g.dynam')]
		expect(dynamics.map((dynam) => dynam.getAttribute('data-staff'))).toEqual(['1', '2'])
		expect(dynamics.map((dynam) => dynam.classList.contains(MUTED_CLASS))).toEqual([false, true])

		// slurs and ties follow the voice of their first note
		expect(root.querySelector('g.slur')?.classList.contains(MUTED_CLASS)).toBe(false)
		expect(root.querySelector('g.tie')?.classList.contains(MUTED_CLASS)).toBe(true)

		// staff lines, clefs and bar lines are not part of a voice
		for (const element of root.querySelectorAll('g.clef, g.barLine, g.staff')) {
			expect(element.classList.contains(MUTED_CLASS)).toBe(false)
		}
	}, 30000)

	it('mutes the marks of a staff only when no focus member is on the staff', async () => {
		const root = await renderedScore()
		markFocus(root, [{ staff: 2, layer: 1 }])

		expect(layer(root, 2, 1)?.classList.contains(FOCUS_CLASS)).toBe(true)
		expect(layer(root, 1, 1)?.classList.contains(MUTED_CLASS)).toBe(true)
		expect(layer(root, 1, 2)?.classList.contains(MUTED_CLASS)).toBe(true)
		const dynamics = [...root.querySelectorAll('g.dynam')]
		expect(dynamics.map((dynam) => dynam.classList.contains(MUTED_CLASS))).toEqual([true, false])
		expect(root.querySelector('g.slur')?.classList.contains(MUTED_CLASS)).toBe(true)
		expect(root.querySelector('g.tie')?.classList.contains(MUTED_CLASS)).toBe(false)
		// the tenors' lyrics are on another staff: muted with their voice
		const verses = [...root.querySelectorAll('g.verse')]
		expect(verses.every((verse) => verse.closest(`.${MUTED_CLASS}`) !== null && !verse.classList.contains(SHARED_CLASS))).toBe(true)
	}, 30000)

	it('removes every mark when the focus voice is cleared or changed', async () => {
		const root = await renderedScore()
		markFocus(root, [{ staff: 1, layer: 1 }])
		expect(root.querySelectorAll(`.${MUTED_CLASS}`).length).toBeGreaterThan(0)

		markFocus(root, [{ staff: 1, layer: 2 }])
		expect(layer(root, 1, 1)?.classList.contains(FOCUS_CLASS)).toBe(false)
		expect(layer(root, 1, 1)?.classList.contains(MUTED_CLASS)).toBe(true)

		markFocus(root, [])
		expect(root.querySelectorAll(`.${FOCUS_CLASS}, .${MUTED_CLASS}, .${SHARED_CLASS}`)).toHaveLength(0)
	}, 30000)
})
