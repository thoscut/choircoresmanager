/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Voices of a score: every MEI layer (= MusicXML voice) of every staff that
 * contains notes. Two voices on one staff are named from the part name:
 * in "Tenor I/II", "Tenor I. II." or "Bass 1./2." the first (upper) voice is
 * Tenor I, the second Tenor II. Voices with the same name on different staves
 * (e.g. Tenor II that has its own staff for a few measures) are one voice.
 */

export interface VoiceMember {
	staff: number
	layer: number
}

export interface Voice {
	/** stable key, e.g. "1.2" or "1.2+2.1" (staff.layer of all members) */
	key: string
	/** derived name, e.g. "Tenor II"; null if the part name does not tell */
	name: string | null
	/** name of the part the (first) member belongs to */
	part: string
	/** number of the voice within its part, for "Part, voice n" */
	number: number
	members: VoiceMember[]
	/** number of notes */
	notes: number
}

/** A part with the MEI staff numbers that belong to it. */
export interface PartStaves {
	name: string
	staves: number[]
}

const NUMERAL = '(?:IV|I{1,3}|[1-4])'

const NUMBERED_LIST = new RegExp(`^(.*?)[\\s,]*(${NUMERAL}(?:(?:[\\s./,&+-]|und|u\\.)+${NUMERAL})+)\\.?$`)

const ABBREVIATED = new RegExp(`^([A-Za-zÄÖÜäöüß]+?)\\.?\\s*(${NUMERAL})\\.?$`)

/** Abbreviations in part names like "TI/TII" or "BI/BII". */
const ABBREVIATIONS: Record<string, string> = {
	T: 'Tenor',
	B: 'Bass',
	S: 'Sopran',
	A: 'Alt',
	Bar: 'Bariton',
}

/**
 * Key of a staff/layer pair.
 *
 * @param staff - MEI staff number
 * @param layer - MEI layer number
 */
export function memberKey(staff: number, layer: number): string {
	return `${staff}.${layer}`
}

/**
 * Names of the voices a part name announces, in order; one name if the part
 * name does not name several voices.
 *
 * Examples: "Tenor I/II" → Tenor I, Tenor II; "Bass 1./2." → Bass 1, Bass 2;
 * "TI/TII" → Tenor I, Tenor II; "Sopran/Alt" → Sopran, Alt.
 *
 * @param raw - part name from the file
 */
export function splitPartName(raw: string): string[] {
	const name = raw.replace(/\s+/g, ' ').trim()
	if (name === '') {
		return []
	}
	const list = name.match(NUMBERED_LIST)
	if (list && list[1].trim() !== '') {
		const base = list[1].replace(/[\s,.]+$/, '')
		const numerals = list[2].match(new RegExp(NUMERAL, 'g')) ?? []
		return numerals.map((numeral) => `${base} ${numeral}`)
	}
	const tokens = name.split(/\s*[/&+]\s*/).filter((token) => token !== '')
	if (tokens.length >= 2) {
		return tokens.map((token) => {
			const match = token.match(ABBREVIATED)
			if (!match) {
				return token
			}
			return `${ABBREVIATIONS[match[1]] ?? match[1]} ${match[2]}`
		})
	}
	return [name]
}

/**
 * Normalized form of a voice name for comparing.
 *
 * @param name - voice name
 */
function normalizeName(name: string): string {
	return name.toLowerCase().replace(/ß/g, 'ss').replace(/\./g, ' ').replace(/\s+/g, ' ').trim()
}

/**
 * The voices of a score.
 *
 * @param parts - parts in score order with their staves
 * @param noteCounts - number of notes per `memberKey(staff, layer)`
 */
export function buildVoices(parts: PartStaves[], noteCounts: Map<string, number>): Voice[] {
	const voices: Voice[] = []
	for (const part of parts) {
		const members: VoiceMember[] = []
		for (const staff of part.staves) {
			const layers = [...noteCounts.keys()]
				.map((key) => key.split('.').map(Number))
				.filter(([s]) => s === staff)
				.map(([, layer]) => layer)
				.sort((a, b) => a - b)
			for (const layer of layers) {
				members.push({ staff, layer })
			}
		}
		if (members.length === 0) {
			continue
		}
		const names = splitPartName(part.name)
		members.forEach((member, index) => {
			let name: string | null = null
			if (members.length === 1) {
				name = part.name.trim() || null
			} else if (part.staves.length === 1 && names.length >= 2) {
				name = names[index] ?? null
			}
			voices.push({
				key: memberKey(member.staff, member.layer),
				name,
				part: part.name.trim(),
				number: index + 1,
				members: [member],
				notes: noteCounts.get(memberKey(member.staff, member.layer)) ?? 0,
			})
		})
	}

	// One voice per name: Tenor II on its own staff and in "Tenor I/II"
	const merged: Voice[] = []
	const byName = new Map<string, Voice>()
	for (const voice of voices) {
		const normalized = voice.name !== null ? normalizeName(voice.name) : ''
		const existing = normalized !== '' ? byName.get(normalized) : undefined
		if (existing) {
			existing.members.push(...voice.members)
			existing.notes += voice.notes
			existing.key = existing.members.map((member) => memberKey(member.staff, member.layer)).join('+')
			continue
		}
		const copy = { ...voice, members: [...voice.members] }
		merged.push(copy)
		if (normalized !== '') {
			byName.set(normalized, copy)
		}
	}
	return merged
}

/**
 * Display name of a voice.
 *
 * @param voice - the voice
 * @param fallback - formats "Part, voice n" (translated in the UI)
 */
export function voiceLabel(voice: Voice, fallback: (part: string, number: number, staff: number) => string): string {
	return voice.name ?? fallback(voice.part, voice.number, voice.members[0]?.staff ?? 0)
}
