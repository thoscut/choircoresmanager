/// <reference types="node" />
// Run with: npm run test:player

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { extractMusicXml } from './mxl.ts'

interface ZipFile {
	name: string
	content: string
	/** Store the file uncompressed */
	stored?: boolean
	/** Leave the sizes out of the local header and write a data descriptor, as streaming writers do */
	dataDescriptor?: boolean
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
	let c = n
	for (let k = 0; k < 8; k++) {
		c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1
	}
	return c >>> 0
})

/**
 * @param data - Bytes to check
 */
function crc32(data: Uint8Array): number {
	let crc = 0xFFFFFFFF
	for (const byte of data) {
		crc = CRC_TABLE[(crc ^ byte) & 0xFF] ^ (crc >>> 8)
	}
	return (crc ^ 0xFFFFFFFF) >>> 0
}

/**
 * @param data - Bytes to compress
 */
async function deflateRaw(data: Uint8Array<ArrayBuffer>): Promise<Uint8Array<ArrayBuffer>> {
	const stream = new Blob([data]).stream().pipeThrough(new CompressionStream('deflate-raw'))
	return new Uint8Array(await new Response(stream).arrayBuffer())
}

/**
 * Write a ZIP archive
 *
 * @param files - The files in the archive
 */
async function zip(files: ZipFile[]): Promise<ArrayBuffer> {
	const encoder = new TextEncoder()
	const local: Uint8Array<ArrayBuffer>[] = []
	const central: Uint8Array<ArrayBuffer>[] = []
	let offset = 0
	for (const file of files) {
		const name = new Uint8Array(encoder.encode(file.name))
		const raw = new Uint8Array(encoder.encode(file.content))
		const data = file.stored ? raw : await deflateRaw(raw)
		const crc = crc32(raw)
		const flags = file.dataDescriptor ? 0x0008 : 0
		const method = file.stored ? 0 : 8

		const header = new DataView(new ArrayBuffer(30))
		header.setUint32(0, 0x04034B50, true)
		header.setUint16(4, 20, true)
		header.setUint16(6, flags, true)
		header.setUint16(8, method, true)
		if (!file.dataDescriptor) {
			header.setUint32(14, crc, true)
			header.setUint32(18, data.length, true)
			header.setUint32(22, raw.length, true)
		}
		header.setUint16(26, name.length, true)
		local.push(new Uint8Array(header.buffer), name, data)
		let size = 30 + name.length + data.length
		if (file.dataDescriptor) {
			const descriptor = new DataView(new ArrayBuffer(16))
			descriptor.setUint32(0, 0x08074B50, true)
			descriptor.setUint32(4, crc, true)
			descriptor.setUint32(8, data.length, true)
			descriptor.setUint32(12, raw.length, true)
			local.push(new Uint8Array(descriptor.buffer))
			size += 16
		}

		const entry = new DataView(new ArrayBuffer(46))
		entry.setUint32(0, 0x02014B50, true)
		entry.setUint16(4, 20, true)
		entry.setUint16(6, 20, true)
		entry.setUint16(8, flags, true)
		entry.setUint16(10, method, true)
		entry.setUint32(16, crc, true)
		entry.setUint32(20, data.length, true)
		entry.setUint32(24, raw.length, true)
		entry.setUint16(28, name.length, true)
		entry.setUint32(42, offset, true)
		central.push(new Uint8Array(entry.buffer), name)
		offset += size
	}

	const centralSize = central.reduce((sum, part) => sum + part.length, 0)
	const end = new DataView(new ArrayBuffer(22))
	end.setUint32(0, 0x06054B50, true)
	end.setUint16(8, files.length, true)
	end.setUint16(10, files.length, true)
	end.setUint32(12, centralSize, true)
	end.setUint32(16, offset, true)
	return new Blob([...local, ...central, new Uint8Array(end.buffer)]).arrayBuffer()
}

const SCORE = '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="4.0"><work><work-title>Grüß Gott</work-title></work></score-partwise>'
const CONTAINER = '<?xml version="1.0" encoding="UTF-8"?><container><rootfiles><rootfile full-path="lieder/score.musicxml" media-type="application/vnd.recordare.musicxml+xml"/></rootfiles></container>'

describe('compressed MusicXML', () => {
	it('reads the root file named in META-INF/container.xml', async () => {
		const data = await zip([
			{ name: 'mimetype', content: 'application/vnd.recordare.musicxml', stored: true },
			{ name: 'META-INF/container.xml', content: CONTAINER },
			{ name: 'other.xml', content: '<other/>' },
			{ name: 'lieder/score.musicxml', content: SCORE },
		])
		assert.equal(await extractMusicXml(data), SCORE)
	})

	it('reads entries whose sizes are only in the central directory', async () => {
		const data = await zip([
			{ name: 'META-INF/container.xml', content: CONTAINER, dataDescriptor: true },
			{ name: 'lieder/score.musicxml', content: SCORE, dataDescriptor: true },
		])
		assert.equal(await extractMusicXml(data), SCORE)
	})

	it('reads stored entries', async () => {
		const data = await zip([
			{ name: 'META-INF/container.xml', content: CONTAINER, stored: true },
			{ name: 'lieder/score.musicxml', content: SCORE, stored: true },
		])
		assert.equal(await extractMusicXml(data), SCORE)
	})

	it('falls back to the first MusicXML file without container', async () => {
		const data = await zip([{ name: 'META-INF/other.xml', content: '<x/>' }, { name: 'score.xml', content: SCORE }])
		assert.equal(await extractMusicXml(data), SCORE)
	})

	it('rejects data that is not a ZIP archive', async () => {
		await assert.rejects(extractMusicXml(new TextEncoder().encode(SCORE).buffer as ArrayBuffer), /Not a ZIP archive/)
	})

	it('rejects archives without MusicXML document', async () => {
		await assert.rejects(extractMusicXml(await zip([{ name: 'readme.txt', content: 'hello' }])), /no MusicXML document/)
	})
})
