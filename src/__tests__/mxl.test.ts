/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { ArchiveLimitError, readScoreText } from '../score/mxl.ts'

const SCORE = '<?xml version="1.0"?><score-partwise version="4.0"><part-list/></score-partwise>'

/**
 * Raw deflate of a text, as in ZIP entries with method 8.
 *
 * @param text - content
 */
async function deflate(text: string): Promise<Uint8Array> {
	const stream = new Blob([new TextEncoder().encode(text)]).stream().pipeThrough(new CompressionStream('deflate-raw'))
	return new Uint8Array(await new Response(stream).arrayBuffer())
}

/**
 * A ZIP archive with the given entries.
 *
 * @param files - name => [method, content bytes, uncompressed size]
 */
function zip(files: [string, number, Uint8Array, number][]): Uint8Array {
	const encoder = new TextEncoder()
	const parts: number[] = []
	const central: number[] = []
	const u16 = (value: number) => [value & 0xff, (value >> 8) & 0xff]
	const u32 = (value: number) => [value & 0xff, (value >> 8) & 0xff, (value >> 16) & 0xff, (value >>> 24) & 0xff]
	for (const [name, method, data, size] of files) {
		const nameBytes = [...encoder.encode(name)]
		const offset = parts.length
		parts.push(...u32(0x04034b50), ...u16(20), ...u16(0), ...u16(method), ...u16(0), ...u16(0), ...u32(0), ...u32(data.length), ...u32(size), ...u16(nameBytes.length), ...u16(0), ...nameBytes, ...data)
		central.push(...u32(0x02014b50), ...u16(20), ...u16(20), ...u16(0), ...u16(method), ...u16(0), ...u16(0), ...u32(0), ...u32(data.length), ...u32(size), ...u16(nameBytes.length), ...u16(0), ...u16(0), ...u16(0), ...u16(0), ...u32(0), ...u32(offset), ...nameBytes)
	}
	const centralOffset = parts.length
	const end = [...u32(0x06054b50), ...u16(0), ...u16(0), ...u16(files.length), ...u16(files.length), ...u32(central.length), ...u32(centralOffset), ...u16(0)]
	return new Uint8Array([...parts, ...central, ...end])
}

describe('readScoreText limits', () => {
	it('unpacks a deflated score', async () => {
		const data = await deflate(SCORE)
		expect(await readScoreText(zip([['score.musicxml', 8, data, SCORE.length]]))).toBe(SCORE)
	})

	it('stops unpacking at the size limit, whatever the archive claims', async () => {
		const big = SCORE + ' '.repeat(5000)
		const data = await deflate(big)
		// the directory claims a small size; the limit applies to the real output
		await expect(readScoreText(zip([['score.musicxml', 8, data, 10]]), { entries: 10, bytes: 1000 }))
			.rejects.toBeInstanceOf(ArchiveLimitError)
	})

	it('refuses a stored entry above the limit', async () => {
		const data = new TextEncoder().encode(SCORE + ' '.repeat(2000))
		await expect(readScoreText(zip([['score.musicxml', 0, data, data.length]]), { entries: 10, bytes: 1000 }))
			.rejects.toBeInstanceOf(ArchiveLimitError)
	})

	it('refuses archives with too many entries', async () => {
		const data = new TextEncoder().encode(SCORE)
		const files: [string, number, Uint8Array, number][] = Array.from({ length: 5 }, (_, index) => [`f${index}.xml`, 0, data, data.length])
		await expect(readScoreText(zip(files), { entries: 4, bytes: 100000 })).rejects.toBeInstanceOf(ArchiveLimitError)
	})

	it('refuses an entry that points outside the archive', async () => {
		const data = new TextEncoder().encode(SCORE)
		const archive = zip([['score.musicxml', 0, data, data.length]])
		// compressed size in the central directory far beyond the end
		const view = new DataView(archive.buffer)
		const centralStart = view.getUint32(archive.length - 6, true)
		view.setUint32(centralStart + 20, 0x7fffffff, true)
		await expect(readScoreText(archive)).rejects.toThrow('damaged')
	})
})
