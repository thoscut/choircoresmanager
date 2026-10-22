/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Small placeholder PDFs for the demo archive: an A4 page per printed page
// with the shelf mark, the title and a note that this is no real scan.
// Text uses the standard Helvetica font with WinAnsi encoding, so umlauts
// and the en dash work without embedding a font.

const WIN_ANSI = { '–': 0x96, '—': 0x97, '„': 0x84, '“': 0x93, '”': 0x94, '‚': 0x82, '‘': 0x91, '’': 0x92, '…': 0x85, '€': 0x80 }

/** A PDF string literal in WinAnsi encoding */
function pdfString(text) {
	let out = '('
	for (const char of text) {
		let code = WIN_ANSI[char] ?? char.codePointAt(0)
		if (code > 0xFF) {
			code = 0x3F // "?"
		}
		if (char === '(' || char === ')' || char === '\\') {
			out += '\\' + char
		} else if (code < 0x20 || code > 0x7E) {
			out += '\\' + code.toString(8).padStart(3, '0')
		} else {
			out += char
		}
	}
	return out + ')'
}

/** Usable line width on A4 with 72 pt margins */
const WIDTH = 451

/** Rough Helvetica width of a text in points (average glyph width 0.55 em) */
const textWidth = (text, size) => text.length * size * 0.55

/** Splits a text into lines that fit the page width at the given size */
function wrap(text, size) {
	const lines = []
	let line = ''
	for (const word of text.split(/\s+/)) {
		const candidate = line === '' ? word : `${line} ${word}`
		if (line !== '' && textWidth(candidate, size) > WIDTH) {
			lines.push(line)
			line = word
		} else {
			line = candidate
		}
	}
	return line === '' ? lines : [...lines, line]
}

/**
 * @param {{ shelfmark: string, title: string, subtitle?: string, lines: string[], pages: number }} info
 * @returns {Buffer} PDF document
 */
export function placeholderPdf(info) {
	const objects = []
	const add = (body) => {
		objects.push(body)
		return objects.length
	}
	const catalog = add(null)
	const pagesId = add(null)
	const font = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>')
	const bold = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>')
	const pageIds = []
	const heading = `Demo scan – ${info.title}`
	const headingSize = Math.min(28, Math.floor(WIDTH / (heading.length * 0.55)))
	const subtitle = info.subtitle ? wrap(info.subtitle, 12) : []
	for (let page = 1; page <= info.pages; page++) {
		const lines = [
			`BT /F2 ${headingSize} Tf 72 740 Td ${pdfString(heading)} Tj ET`,
			...subtitle.map((line, i) => `BT /F1 12 Tf 72 ${716 - i * 15} Td ${pdfString(line)} Tj ET`),
			`BT /F2 60 Tf 72 560 Td ${pdfString(info.shelfmark)} Tj ET`,
			...info.lines.map((line, i) => `BT /F1 12 Tf 72 ${480 - i * 18} Td ${pdfString(line)} Tj ET`),
			`BT /F1 10 Tf 72 60 Td ${pdfString(`Page ${page} of ${info.pages} – placeholder, no real scan (CC0)`)} Tj ET`,
			`0.6 w 72 ${700 - Math.max(0, subtitle.length - 1) * 15} m 523 ${700 - Math.max(0, subtitle.length - 1) * 15} l S`,
			'0.6 w 72 90 m 523 90 l S',
		].filter((line) => line !== '').join('\n')
		const content = add(`<< /Length ${Buffer.byteLength(lines, 'latin1')} >>\nstream\n${lines}\nendstream`)
		pageIds.push(add(`<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ${font} 0 R /F2 ${bold} 0 R >> >> /Contents ${content} 0 R >>`))
	}
	objects[catalog - 1] = `<< /Type /Catalog /Pages ${pagesId} 0 R >>`
	objects[pagesId - 1] = `<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageIds.length} >>`
	const info2 = add(`<< /Title ${pdfString(`Demo scan – ${info.title}`)} /Producer (notenarchiv tools/demo) >>`)

	let body = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n'
	const offsets = []
	objects.forEach((object, i) => {
		offsets.push(Buffer.byteLength(body, 'latin1'))
		body += `${i + 1} 0 obj\n${object}\nendobj\n`
	})
	const xref = Buffer.byteLength(body, 'latin1')
	body += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`
	for (const offset of offsets) {
		body += `${String(offset).padStart(10, '0')} 00000 n \n`
	}
	body += `trailer\n<< /Size ${objects.length + 1} /Root ${catalog} 0 R /Info ${info2} 0 R >>\nstartxref\n${xref}\n%%EOF\n`
	return Buffer.from(body, 'latin1')
}
