/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Emphasis of the focus voice in the rendered score. The focus voice is
 * marked with FOCUS_CLASS (the view draws it in color with bolder note
 * heads and stems), everything that belongs to the other voices with
 * MUTED_CLASS (drawn lighter, still readable). So the focus voice stands
 * out by more than its hue (WCAG 1.4.1).
 *
 * Belongs to the other voices: their layers (notes, rests, beams, lyrics),
 * control events of staves without the focus voice (dynamics, directions,
 * hairpins, fermatas, ornaments; `data-staff`, see VEROVIO_OPTIONS) and
 * slurs and ties that start at one of their notes (`data-startid`). Staff
 * lines, clefs, signatures, bar lines and measure numbers stay as they are.
 *
 * Lyrics, like dynamics, belong to the staff: a choir score often prints the
 * text once, with the upper voice, for both voices of the staff. Lyrics of
 * another voice on a staff of the focus voice are marked with SHARED_CLASS
 * and stay in the normal ink.
 */
import type { VoiceMember } from './voices.ts'

import { memberKey } from './voices.ts'

export const FOCUS_CLASS = 'score-focus'
export const MUTED_CLASS = 'score-muted'
export const SHARED_CLASS = 'score-shared'

/**
 * Mark the focus voice and the other voices in rendered SVG pages.
 *
 * @param root - element that contains the SVG pages
 * @param members - staff/layer pairs of the focus voice; empty: no focus voice
 */
export function markFocus(root: ParentNode, members: readonly VoiceMember[]): void {
	for (const element of root.querySelectorAll(`.${FOCUS_CLASS}, .${MUTED_CLASS}, .${SHARED_CLASS}`)) {
		element.classList.remove(FOCUS_CLASS, MUTED_CLASS, SHARED_CLASS)
	}
	if (members.length === 0) {
		return
	}
	const focus = new Set(members.map((member) => memberKey(member.staff, member.layer)))
	const focusStaves = new Set(members.map((member) => String(member.staff)))
	// ids of everything inside the other voices, for slurs and ties
	const mutedIds = new Set<string>()

	for (const layer of root.querySelectorAll('g.layer')) {
		const staff = layer.closest('g.staff')?.getAttribute('data-n') ?? ''
		const key = memberKey(Number(staff), Number(layer.getAttribute('data-n') ?? ''))
		if (focus.has(key)) {
			layer.classList.add(FOCUS_CLASS)
			continue
		}
		layer.classList.add(MUTED_CLASS)
		for (const element of layer.querySelectorAll('[data-id]')) {
			mutedIds.add(element.getAttribute('data-id') ?? '')
		}
		if (focusStaves.has(staff)) {
			for (const verse of layer.querySelectorAll('g.verse')) {
				verse.classList.add(SHARED_CLASS)
			}
		}
	}

	// control events: lighter unless one of their staves has the focus voice
	for (const element of root.querySelectorAll('g[data-staff]')) {
		const staves = (element.getAttribute('data-staff') ?? '').trim().split(/\s+/)
		if (!staves.some((staff) => focusStaves.has(staff))) {
			element.classList.add(MUTED_CLASS)
		}
	}

	// slurs and ties follow the note they start at
	for (const element of root.querySelectorAll('g[data-startid]')) {
		const start = (element.getAttribute('data-startid') ?? '').replace(/^#/, '')
		if (mutedIds.has(start)) {
			element.classList.add(MUTED_CLASS)
		}
	}
}
