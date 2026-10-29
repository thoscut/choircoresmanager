/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Reading score files: compressed MusicXML (.mxl, a ZIP archive whose
 * META-INF/container.xml names the score) or plain MusicXML. Unpacking uses
 * the platform's DecompressionStream, available in current browsers and in
 * Node, so no extra library is needed. Limits protect against archives that
 * unpack to huge sizes ("ZIP bombs"): the server delivers at most 32 MB, a
 * score unpacks to far less than the limit here.
 */
import { descendants, parseXml } from './xml.ts'

/** Most entries an archive may list. */
export const MAX_ZIP_ENTRIES = 1000

/** Largest unpacked size of one file in bytes (64 MB). */
export const MAX_UNPACKED_BYTES = 64 * 1024 * 1024

/** The archive exceeds one of the limits above. */
export class ArchiveLimitError extends Error {}

export interface ArchiveLimits {
	entries: number
	bytes: number
}

const LIMITS: ArchiveLimits = { entries: MAX_ZIP_ENTRIES, bytes: MAX_UNPACKED_BYTES }

interface ZipEntry {
	name: string
	method: number
	compressedSize: number
	localOffset: number
}

/**
 * Whether the data starts with a ZIP local file header.
 *
 * @param data - file content
 */
export function isZip(data: Uint8Array): boolean {
	return data.length > 4 && data[0] === 0x50 && data[1] === 0x4b && data[2] === 0x03 && data[3] === 0x04
}

/**
 * Text of an XML file; honours a UTF-16 byte order mark, otherwise UTF-8.
 *
 * @param data - raw bytes
 */
export function decodeText(data: Uint8Array): string {
	if (data[0] === 0xff && data[1] === 0xfe) {
		return new TextDecoder('utf-16le').decode(data)
	}
	if (data[0] === 0xfe && data[1] === 0xff) {
		return new TextDecoder('utf-16be').decode(data)
	}
	return new TextDecoder('utf-8').decode(data)
}

function readEntries(data: Uint8Array, limits: ArchiveLimits): ZipEntry[] {
	const view = new DataView(data.buffer, data.byteOffset, data.byteLength)
	let end = -1
	for (let i = data.length - 22; i >= Math.max(0, data.length - 22 - 0xffff); i--) {
		if (view.getUint32(i, true) === 0x06054b50) {
			end = i
			break
		}
	}
	if (end < 0) {
		throw new Error('The file is not a valid ZIP archive.')
	}
	const count = view.getUint16(end + 10, true)
	if (count > limits.entries) {
		throw new ArchiveLimitError('The ZIP archive has too many entries.')
	}
	let offset = view.getUint32(end + 16, true)
	const decoder = new TextDecoder()
	const entries: ZipEntry[] = []
	for (let n = 0; n < count; n++) {
		if (offset + 46 > data.length || view.getUint32(offset, true) !== 0x02014b50) {
			throw new Error('The ZIP directory is damaged.')
		}
		const nameLength = view.getUint16(offset + 28, true)
		const extraLength = view.getUint16(offset + 30, true)
		const commentLength = view.getUint16(offset + 32, true)
		entries.push({
			method: view.getUint16(offset + 10, true),
			compressedSize: view.getUint32(offset + 20, true),
			localOffset: view.getUint32(offset + 42, true),
			name: decoder.decode(data.subarray(offset + 46, offset + 46 + nameLength)),
		})
		offset += 46 + nameLength + extraLength + commentLength
	}
	return entries
}

async function extract(data: Uint8Array, entry: ZipEntry, limits: ArchiveLimits): Promise<Uint8Array> {
	const view = new DataView(data.buffer, data.byteOffset, data.byteLength)
	const local = entry.localOffset
	if (local + 30 > data.length || view.getUint32(local, true) !== 0x04034b50) {
		throw new Error('The ZIP archive is damaged.')
	}
	const start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true)
	if (start + entry.compressedSize > data.length) {
		throw new Error('The ZIP archive is damaged.')
	}
	const raw = data.subarray(start, start + entry.compressedSize)
	if (entry.method === 0) {
		if (raw.length > limits.bytes) {
			throw new ArchiveLimitError('The score in the archive is too large.')
		}
		return raw
	}
	if (entry.method !== 8) {
		throw new Error(`Unsupported ZIP compression method ${entry.method}.`)
	}
	const copy = new Uint8Array(raw.length)
	copy.set(raw)
	const stream = new Blob([copy]).stream().pipeThrough(new DecompressionStream('deflate-raw'))
	// read chunk by chunk and stop at the limit: the sizes in the archive may lie
	const reader = stream.getReader()
	const chunks: Uint8Array[] = []
	let total = 0
	for (;;) {
		const { done, value } = await reader.read()
		if (done) {
			break
		}
		total += value.length
		if (total > limits.bytes) {
			await reader.cancel()
			throw new ArchiveLimitError('The score in the archive is too large.')
		}
		chunks.push(value)
	}
	const result = new Uint8Array(total)
	let position = 0
	for (const chunk of chunks) {
		result.set(chunk, position)
		position += chunk.length
	}
	return result
}

/**
 * The MusicXML text of a score file (.mxl or .musicxml/.xml).
 *
 * @param content - file content as delivered by the server
 * @param limits - most entries and unpacked bytes (tests use smaller ones)
 */
export async function readScoreText(content: ArrayBuffer | Uint8Array, limits: ArchiveLimits = LIMITS): Promise<string> {
	const data = content instanceof Uint8Array ? content : new Uint8Array(content)
	if (!isZip(data)) {
		return decodeText(data)
	}
	const entries = readEntries(data, limits)
	let path = ''
	const container = entries.find((entry) => entry.name === 'META-INF/container.xml')
	if (container) {
		const xml = parseXml(decodeText(await extract(data, container, limits)))
		const rootfiles = descendants(xml, 'rootfile')
		const musicxml = rootfiles.find((file) => {
			const type = file.attrs['media-type'] ?? ''
			return type === '' || type.includes('musicxml')
		}) ?? rootfiles[0]
		path = musicxml?.attrs['full-path'] ?? ''
	}
	let entry = entries.find((candidate) => candidate.name === path)
	if (!entry) {
		entry = entries.find((candidate) => !candidate.name.startsWith('META-INF/') && /\.(musicxml|xml)$/i.test(candidate.name))
	}
	if (!entry) {
		throw new Error('The archive contains no MusicXML file.')
	}
	return decodeText(await extract(data, entry, limits))
}
