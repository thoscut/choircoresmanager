/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Writing ZIP archives with stored (uncompressed) entries, e.g. the practice
 * audio files of all voices in one download. WAV and MIDI files hardly
 * shrink when compressed, so storing them is enough. Reading archives is in
 * mxl.ts.
 */

export interface ZipEntry {
	/** file name inside the archive */
	name: string
	data: Uint8Array
}

const CRC_TABLE = (() => {
	const table = new Uint32Array(256)
	for (let n = 0; n < 256; n++) {
		let c = n
		for (let k = 0; k < 8; k++) {
			c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
		}
		table[n] = c >>> 0
	}
	return table
})()

/**
 * CRC-32 as used by ZIP.
 *
 * @param data - bytes
 */
export function crc32(data: Uint8Array): number {
	let crc = 0xffffffff
	for (let i = 0; i < data.length; i++) {
		crc = CRC_TABLE[(crc ^ data[i]) & 0xff] ^ (crc >>> 8)
	}
	return (crc ^ 0xffffffff) >>> 0
}

/**
 * Size of a stored archive in bytes.
 *
 * @param entries - names (as UTF-8 byte length) and data sizes
 */
export function zipBytes(entries: { nameBytes: number, dataBytes: number }[]): number {
	return entries.reduce((sum, entry) => sum + 30 + 46 + 2 * entry.nameBytes + entry.dataBytes, 22)
}

/**
 * DOS date and time of a moment (local time, two-second steps).
 *
 * @param date - the moment
 */
function dosDateTime(date: Date): { time: number, date: number } {
	const year = Math.min(2107, Math.max(1980, date.getFullYear()))
	return {
		time: (date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1),
		date: ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate(),
	}
}

/**
 * A ZIP archive with stored entries.
 *
 * @param entries - files in the order they are written
 * @param modified - modification time of all entries
 */
export function zipStore(entries: ZipEntry[], modified: Date = new Date()): Uint8Array {
	if (entries.length > 0xffff) {
		throw new RangeError('Too many files for a ZIP archive.')
	}
	const encoder = new TextEncoder()
	const names = entries.map((entry) => encoder.encode(entry.name))
	const total = zipBytes(entries.map((entry, i) => ({ nameBytes: names[i].length, dataBytes: entry.data.length })))
	if (total > 0xffffffff) {
		throw new RangeError('The files are too large for a ZIP archive.')
	}
	const { time, date } = dosDateTime(modified)
	const bytes = new Uint8Array(total)
	const view = new DataView(bytes.buffer)
	const crcs = entries.map((entry) => crc32(entry.data))
	const offsets: number[] = []
	let offset = 0

	// Local file headers with the data
	entries.forEach((entry, i) => {
		offsets.push(offset)
		view.setUint32(offset, 0x04034b50, true)
		view.setUint16(offset + 4, 20, true)
		// bit 11: names are UTF-8
		view.setUint16(offset + 6, 0x0800, true)
		view.setUint16(offset + 8, 0, true)
		view.setUint16(offset + 10, time, true)
		view.setUint16(offset + 12, date, true)
		view.setUint32(offset + 14, crcs[i], true)
		view.setUint32(offset + 18, entry.data.length, true)
		view.setUint32(offset + 22, entry.data.length, true)
		view.setUint16(offset + 26, names[i].length, true)
		view.setUint16(offset + 28, 0, true)
		bytes.set(names[i], offset + 30)
		bytes.set(entry.data, offset + 30 + names[i].length)
		offset += 30 + names[i].length + entry.data.length
	})

	// Central directory
	const directory = offset
	entries.forEach((entry, i) => {
		view.setUint32(offset, 0x02014b50, true)
		view.setUint16(offset + 4, 20, true)
		view.setUint16(offset + 6, 20, true)
		view.setUint16(offset + 8, 0x0800, true)
		view.setUint16(offset + 10, 0, true)
		view.setUint16(offset + 12, time, true)
		view.setUint16(offset + 14, date, true)
		view.setUint32(offset + 16, crcs[i], true)
		view.setUint32(offset + 20, entry.data.length, true)
		view.setUint32(offset + 24, entry.data.length, true)
		view.setUint16(offset + 28, names[i].length, true)
		// extra field, comment, disk number, internal and external attributes: 0
		view.setUint32(offset + 42, offsets[i], true)
		bytes.set(names[i], offset + 46)
		offset += 46 + names[i].length
	})

	// End of central directory
	view.setUint32(offset, 0x06054b50, true)
	view.setUint16(offset + 8, entries.length, true)
	view.setUint16(offset + 10, entries.length, true)
	view.setUint32(offset + 12, offset - directory, true)
	view.setUint32(offset + 16, directory, true)
	return bytes
}
