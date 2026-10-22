// CSV as the catalog import expects it: UTF-8 with BOM, ";" as separator,
// CRLF line ends and quotes only where needed (like Python's csv module
// with QUOTE_MINIMAL), see lib/Service/CatalogCsv.php.

export const BOM = '﻿'

/** One cell, quoted when it contains ";", a quote or a line break */
export function cell(value) {
	const text = value === null || value === undefined ? '' : String(value)
	return /[;"\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

/**
 * @param {string[]} header
 * @param {Array<Array<string|number|null>>} rows
 * @returns {string} file content
 */
export function writeCsv(header, rows) {
	for (const row of rows) {
		if (row.length !== header.length) {
			throw new Error(`CSV row with ${row.length} cells for ${header.length} columns: ${row.join(';')}`)
		}
	}
	return BOM + [header, ...rows].map((row) => row.map(cell).join(';') + '\r\n').join('')
}
