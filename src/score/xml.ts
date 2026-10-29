/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A small, non-validating XML parser for well-formed documents: MusicXML
 * files and the MEI that Verovio writes. It has no browser dependencies, so
 * the check script in tools/score-check runs exactly the same code as the UI.
 */

export interface XmlElement {
	name: string
	attrs: Record<string, string>
	/** child elements and (non-blank) text nodes */
	children: (XmlElement | string)[]
}

const ENTITIES: Record<string, string> = { amp: '&', lt: '<', gt: '>', quot: '"', apos: '\'' }

/**
 * Replace character and the five predefined entity references.
 *
 * @param text - raw text or attribute value
 */
export function decodeEntities(text: string): string {
	if (!text.includes('&')) {
		return text
	}
	return text.replace(/&(#[xX][0-9a-fA-F]+|#\d+|\w+);/g, (match, code: string) => {
		if (code.startsWith('#')) {
			const value = code[1] === 'x' || code[1] === 'X' ? parseInt(code.slice(2), 16) : parseInt(code.slice(1), 10)
			return Number.isNaN(value) ? match : String.fromCodePoint(value)
		}
		return ENTITIES[code] ?? match
	})
}

/**
 * Whether a character code is XML white space.
 *
 * @param code - UTF-16 code unit
 */
function isSpace(code: number): boolean {
	return code === 0x20 || code === 0x09 || code === 0x0a || code === 0x0d
}

/**
 * Parse a document and return its root element.
 *
 * @param source - the XML text
 */
export function parseXml(source: string): XmlElement {
	const documentNode: XmlElement = { name: '#document', attrs: {}, children: [] }
	const stack: XmlElement[] = [documentNode]
	const length = source.length
	let pos = 0

	const addText = (raw: string) => {
		if (raw.trim() !== '') {
			stack[stack.length - 1].children.push(decodeEntities(raw))
		}
	}

	while (pos < length) {
		const lt = source.indexOf('<', pos)
		if (lt === -1) {
			addText(source.slice(pos))
			break
		}
		if (lt > pos) {
			addText(source.slice(pos, lt))
		}
		if (source.startsWith('<!--', lt)) {
			const end = source.indexOf('-->', lt + 4)
			pos = end === -1 ? length : end + 3
			continue
		}
		if (source.startsWith('<![CDATA[', lt)) {
			const end = source.indexOf(']]>', lt + 9)
			const text = source.slice(lt + 9, end === -1 ? length : end)
			if (text.trim() !== '') {
				stack[stack.length - 1].children.push(text)
			}
			pos = end === -1 ? length : end + 3
			continue
		}
		if (source.startsWith('<?', lt)) {
			const end = source.indexOf('?>', lt + 2)
			pos = end === -1 ? length : end + 2
			continue
		}
		if (source.startsWith('<!', lt)) {
			// DOCTYPE, possibly with an internal subset in brackets; quoted
			// literals (system ids, entity values) may contain ">", "[" or "]",
			// comments inside the subset too
			let i = lt + 2
			let depth = 0
			while (i < length) {
				const char = source[i]
				if (char === '"' || char === '\'') {
					const close = source.indexOf(char, i + 1)
					i = close === -1 ? length : close + 1
					continue
				}
				if (source.startsWith('<!--', i)) {
					const close = source.indexOf('-->', i + 4)
					i = close === -1 ? length : close + 3
					continue
				}
				if (char === '[') {
					depth++
				} else if (char === ']') {
					depth--
				} else if (char === '>' && depth <= 0) {
					break
				}
				i++
			}
			pos = i + 1
			continue
		}
		if (source[lt + 1] === '/') {
			const end = source.indexOf('>', lt)
			const name = source.slice(lt + 2, end === -1 ? length : end).trim()
			for (let i = stack.length - 1; i > 0; i--) {
				if (stack[i].name === name) {
					stack.length = i
					break
				}
			}
			pos = end === -1 ? length : end + 1
			continue
		}

		// Start tag
		let i = lt + 1
		while (i < length) {
			const code = source.charCodeAt(i)
			if (isSpace(code) || code === 0x2f || code === 0x3e) {
				break
			}
			i++
		}
		const element: XmlElement = { name: source.slice(lt + 1, i), attrs: {}, children: [] }
		let selfClosing = false
		while (i < length) {
			const code = source.charCodeAt(i)
			if (code === 0x3e) { // >
				i++
				break
			}
			if (code === 0x2f && source.charCodeAt(i + 1) === 0x3e) { // />
				selfClosing = true
				i += 2
				break
			}
			if (isSpace(code) || code === 0x2f) {
				i++
				continue
			}
			let j = i
			while (j < length) {
				const c = source.charCodeAt(j)
				if (isSpace(c) || c === 0x3d || c === 0x3e || c === 0x2f) {
					break
				}
				j++
			}
			const attrName = source.slice(i, j)
			i = j
			while (i < length && isSpace(source.charCodeAt(i))) {
				i++
			}
			if (source[i] !== '=') {
				element.attrs[attrName] = ''
				continue
			}
			i++
			while (i < length && isSpace(source.charCodeAt(i))) {
				i++
			}
			const quote = source[i]
			if (quote === '"' || quote === '\'') {
				const end = source.indexOf(quote, i + 1)
				const stop = end === -1 ? length : end
				element.attrs[attrName] = decodeEntities(source.slice(i + 1, stop))
				i = stop + 1
			} else {
				let k = i
				while (k < length && !isSpace(source.charCodeAt(k)) && source[k] !== '>') {
					k++
				}
				element.attrs[attrName] = decodeEntities(source.slice(i, k))
				i = k
			}
		}
		stack[stack.length - 1].children.push(element)
		if (!selfClosing) {
			stack.push(element)
		}
		pos = i
	}

	const root = documentNode.children.find((node): node is XmlElement => typeof node !== 'string')
	if (!root) {
		throw new Error('The document contains no XML element.')
	}
	return root
}

/**
 * Child elements, optionally only those with a given name.
 *
 * @param element - parent
 * @param name - element name to keep
 */
export function childElements(element: XmlElement, name?: string): XmlElement[] {
	const result: XmlElement[] = []
	for (const child of element.children) {
		if (typeof child !== 'string' && (name === undefined || child.name === name)) {
			result.push(child)
		}
	}
	return result
}

/**
 * The first child element with a given name.
 *
 * @param element - parent
 * @param name - element name
 */
export function firstChild(element: XmlElement, name: string): XmlElement | undefined {
	for (const child of element.children) {
		if (typeof child !== 'string' && child.name === name) {
			return child
		}
	}
	return undefined
}

/**
 * All text below an element, concatenated and trimmed.
 *
 * @param element - the element
 */
export function textOf(element: XmlElement | undefined): string {
	if (!element) {
		return ''
	}
	let text = ''
	for (const child of element.children) {
		text += typeof child === 'string' ? child : textOf(child)
	}
	return text.trim()
}

/**
 * All descendant elements with a given name, in document order.
 *
 * @param element - where to search
 * @param name - element name
 */
export function descendants(element: XmlElement, name: string): XmlElement[] {
	const result: XmlElement[] = []
	const visit = (node: XmlElement) => {
		for (const child of node.children) {
			if (typeof child !== 'string') {
				if (child.name === name) {
					result.push(child)
				}
				visit(child)
			}
		}
	}
	visit(element)
	return result
}
