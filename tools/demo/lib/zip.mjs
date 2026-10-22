// Minimal ZIP writer for compressed MusicXML (.mxl): deflated entries, a
// fixed timestamp so that repeated builds give identical files.
import { deflateRawSync } from 'node:zlib'

const CRC_TABLE = (() => {
	const table = new Uint32Array(256)
	for (let n = 0; n < 256; n++) {
		let c = n
		for (let k = 0; k < 8; k++) {
			c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1
		}
		table[n] = c >>> 0
	}
	return table
})()

/** CRC-32 of a buffer (as used by ZIP) */
export function crc32(buffer) {
	let crc = 0xFFFFFFFF
	for (const byte of buffer) {
		crc = CRC_TABLE[(crc ^ byte) & 0xFF] ^ (crc >>> 8)
	}
	return (crc ^ 0xFFFFFFFF) >>> 0
}

/** DOS date and time of the given day at noon */
function dosDateTime(isoDate) {
	const [year, month, day] = isoDate.split('-').map(Number)
	return { time: 12 << 11, date: ((year - 1980) << 9) | (month << 5) | day }
}

/**
 * @param {Array<{ name: string, data: Buffer, store?: boolean }>} entries
 * @param {string} isoDate date for all entries (YYYY-MM-DD)
 * @returns {Buffer} ZIP archive
 */
export function zip(entries, isoDate) {
	const { time, date } = dosDateTime(isoDate)
	const locals = []
	const centrals = []
	let offset = 0
	for (const entry of entries) {
		const name = Buffer.from(entry.name, 'utf8')
		const data = entry.store ? entry.data : deflateRawSync(entry.data, { level: 9 })
		const method = entry.store ? 0 : 8
		const crc = crc32(entry.data)
		const local = Buffer.alloc(30)
		local.writeUInt32LE(0x04034B50, 0)
		local.writeUInt16LE(20, 4)
		local.writeUInt16LE(0x0800, 6) // UTF-8 names
		local.writeUInt16LE(method, 8)
		local.writeUInt16LE(time, 10)
		local.writeUInt16LE(date, 12)
		local.writeUInt32LE(crc, 14)
		local.writeUInt32LE(data.length, 18)
		local.writeUInt32LE(entry.data.length, 22)
		local.writeUInt16LE(name.length, 26)
		local.writeUInt16LE(0, 28)
		locals.push(local, name, data)

		const central = Buffer.alloc(46)
		central.writeUInt32LE(0x02014B50, 0)
		central.writeUInt16LE(20, 4)
		central.writeUInt16LE(20, 6)
		central.writeUInt16LE(0x0800, 8)
		central.writeUInt16LE(method, 10)
		central.writeUInt16LE(time, 12)
		central.writeUInt16LE(date, 14)
		central.writeUInt32LE(crc, 16)
		central.writeUInt32LE(data.length, 20)
		central.writeUInt32LE(entry.data.length, 24)
		central.writeUInt16LE(name.length, 28)
		central.writeUInt32LE(offset, 42)
		centrals.push(central, name)
		offset += 30 + name.length + data.length
	}
	const centralSize = centrals.reduce((sum, part) => sum + part.length, 0)
	const end = Buffer.alloc(22)
	end.writeUInt32LE(0x06054B50, 0)
	end.writeUInt16LE(entries.length, 8)
	end.writeUInt16LE(entries.length, 10)
	end.writeUInt32LE(centralSize, 12)
	end.writeUInt32LE(offset, 16)
	return Buffer.concat([...locals, ...centrals, end])
}

/**
 * Compressed MusicXML: mimetype first (stored), container, then the score.
 *
 * @param {string} scoreName file name of the score inside the archive
 * @param {string} musicXml the MusicXML document
 * @param {string} isoDate fixed date of the entries
 */
export function mxl(scoreName, musicXml, isoDate) {
	const container = '<?xml version="1.0" encoding="UTF-8"?>\n'
		+ '<container><rootfiles>'
		+ `<rootfile full-path="${scoreName}" media-type="application/vnd.recordare.musicxml+xml"/>`
		+ '</rootfiles></container>\n'
	return zip([
		{ name: 'mimetype', data: Buffer.from('application/vnd.recordare.musicxml'), store: true },
		{ name: 'META-INF/container.xml', data: Buffer.from(container) },
		{ name: scoreName, data: Buffer.from(musicXml) },
	], isoDate)
}
