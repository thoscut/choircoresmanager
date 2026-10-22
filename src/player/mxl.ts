// Reads the MusicXML document out of a compressed MusicXML file (.mxl, a ZIP archive)
//
// Verovio unpacks .mxl files itself for rendering. The playback order is computed from the
// MusicXML document, so the player needs the document as text, too. Entries are inflated with
// the platform's DecompressionStream; ZIP64 archives and encrypted entries are not supported.

const END_OF_CENTRAL_DIRECTORY = 0x06054B50
const CENTRAL_DIRECTORY_ENTRY = 0x02014B50
const LOCAL_FILE_HEADER = 0x04034B50
const METHOD_STORED = 0
const METHOD_DEFLATE = 8
const CONTAINER_PATH = 'META-INF/container.xml'
// End of central directory record without comment, plus the longest possible comment
const MAX_END_RECORD_SEARCH = 22 + 0xFFFF

interface ZipEntry {
	name: string
	method: number
	compressedSize: number
	localHeaderOffset: number
}

/**
 * Read the central directory of a ZIP archive
 *
 * @param data - The archive
 * @throws {Error} if the data is not a supported ZIP archive
 */
function readEntries(data: ArrayBuffer): ZipEntry[] {
	const view = new DataView(data)
	let endRecord = -1
	for (let offset = data.byteLength - 22; offset >= Math.max(0, data.byteLength - MAX_END_RECORD_SEARCH); offset--) {
		if (view.getUint32(offset, true) === END_OF_CENTRAL_DIRECTORY) {
			endRecord = offset
			break
		}
	}
	if (endRecord < 0) {
		throw new Error('Not a ZIP archive')
	}

	const count = view.getUint16(endRecord + 10, true)
	let offset = view.getUint32(endRecord + 16, true)
	const decoder = new TextDecoder()
	const entries: ZipEntry[] = []
	for (let index = 0; index < count; index++) {
		if (offset + 46 > data.byteLength || view.getUint32(offset, true) !== CENTRAL_DIRECTORY_ENTRY) {
			throw new Error('Invalid ZIP central directory')
		}
		const nameLength = view.getUint16(offset + 28, true)
		const extraLength = view.getUint16(offset + 30, true)
		const commentLength = view.getUint16(offset + 32, true)
		entries.push({
			name: decoder.decode(new Uint8Array(data, offset + 46, nameLength)),
			method: view.getUint16(offset + 10, true),
			// Sizes are read from the central directory, as the local header may leave them out
			compressedSize: view.getUint32(offset + 20, true),
			localHeaderOffset: view.getUint32(offset + 42, true),
		})
		offset += 46 + nameLength + extraLength + commentLength
	}
	return entries
}

/**
 * Unpack an entry of a ZIP archive
 *
 * @param data - The archive
 * @param entry - The entry
 * @throws {Error} if the entry cannot be unpacked
 */
async function readEntry(data: ArrayBuffer, entry: ZipEntry): Promise<Uint8Array> {
	const view = new DataView(data)
	const header = entry.localHeaderOffset
	if (header + 30 > data.byteLength || view.getUint32(header, true) !== LOCAL_FILE_HEADER) {
		throw new Error(`Invalid ZIP entry ${entry.name}`)
	}
	const start = header + 30 + view.getUint16(header + 26, true) + view.getUint16(header + 28, true)
	if (start + entry.compressedSize > data.byteLength) {
		throw new Error(`Truncated ZIP entry ${entry.name}`)
	}
	const compressed = new Uint8Array(data, start, entry.compressedSize)

	if (entry.method === METHOD_STORED) {
		return compressed.slice()
	}
	if (entry.method !== METHOD_DEFLATE) {
		throw new Error(`Unsupported compression method ${entry.method} of ZIP entry ${entry.name}`)
	}
	const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('deflate-raw'))
	return new Uint8Array(await new Response(stream).arrayBuffer())
}

/**
 * Path of the score in a compressed MusicXML file: the first rootfile of META-INF/container.xml
 *
 * @param container - Content of META-INF/container.xml
 */
function rootFilePath(container: string): string | null {
	const match = /<rootfile\b[^>]*?\bfull-path\s*=\s*(?:"([^"]*)"|'([^']*)')/.exec(container)
	const path = match?.[1] ?? match?.[2]
	return path === undefined
		? null
		: path.replace(/&quot;/g, '"').replace(/&apos;/g, '\'').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
}

/**
 * Read the MusicXML document of a compressed MusicXML file (.mxl)
 *
 * @param data - Content of the .mxl file
 * @return The MusicXML document as text
 * @throws {Error} if the file cannot be unpacked or contains no MusicXML document
 */
export async function extractMusicXml(data: ArrayBuffer): Promise<string> {
	const entries = readEntries(data)
	const decoder = new TextDecoder()

	let path: string | null = null
	const container = entries.find((entry) => entry.name === CONTAINER_PATH)
	if (container) {
		path = rootFilePath(decoder.decode(await readEntry(data, container)))
	}
	const score = entries.find((entry) => entry.name === path)
		?? entries.find((entry) => !entry.name.startsWith('META-INF/') && /\.(?:musicxml|xml)$/i.test(entry.name))
	if (!score) {
		throw new Error('The archive contains no MusicXML document')
	}
	return decoder.decode(await readEntry(data, score))
}
