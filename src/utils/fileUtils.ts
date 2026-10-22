/**
 * Offer text content as a file download
 *
 * @param fileName - The suggested file name
 * @param content - The file content
 * @param mimeType - The MIME type of the file
 */
export function downloadTextFile(fileName: string, content: string, mimeType: string): void {
	const blob = new Blob([content], { type: mimeType })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = fileName
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}

/**
 * Read a file as UTF-8 text
 *
 * Unlike File.text(), invalid UTF-8 is rejected instead of being replaced silently,
 * so that a file saved in another encoding is noticed before it is imported.
 *
 * @param file - The file to read
 * @return The text content without byte order mark
 * @throws { TypeError } if the file is not valid UTF-8
 */
export async function readUtf8File(file: File): Promise<string> {
	const buffer = await file.arrayBuffer()
	return new TextDecoder('utf-8', { fatal: true }).decode(buffer)
}
