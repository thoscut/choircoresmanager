/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Cleaning of the SVG that Verovio renders from a MusicXML file before it
 * is put into the page. Verovio escapes texts of the file, but the file is
 * user content: the SVG is cleaned anyway (defence in depth). Kept is what
 * Verovio's SVG needs – shapes, texts, groups with data attributes, <use>
 * references to glyphs inside the same SVG and the embedded music font –
 * and removed is everything that could run code or load something from
 * elsewhere: scripts, event handlers, links, foreign objects, images,
 * animations and references or CSS URLs outside the SVG.
 */
import type { Config, DOMPurify as Purifier } from 'dompurify'

import createPurifier from 'dompurify'

/** Elements Verovio never renders that could carry active or external content. */
const FORBID_TAGS = [
	'script', 'foreignObject', 'foreignobject', 'a', 'image', 'iframe', 'embed', 'object', 'audio', 'video',
	'animate', 'animateMotion', 'animatemotion', 'animateTransform', 'animatetransform', 'animateColor', 'animatecolor',
	'set', 'feImage', 'feimage', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'frame',
]

/** Attributes holding a reference to another resource. */
const REFERENCE_ATTRIBUTES = new Set(['href', 'xlink:href', 'src', 'srcset', 'action', 'formaction', 'data', 'poster'])

const CONFIG: Config = {
	USE_PROFILES: { svg: true, svgFilters: false },
	// glyphs are <use> references to <symbol>s in the same SVG; the hook below allows only "#…"
	ADD_TAGS: ['use'],
	FORBID_TAGS,
	FORBID_ATTR: ['srcset', 'action', 'formaction', 'ping'],
	ALLOW_UNKNOWN_PROTOCOLS: false,
}

/**
 * Whether a reference points inside the same document ("#id").
 *
 * @param value - attribute value
 */
export function isLocalReference(value: string): boolean {
	return /^\s*#[^\s]*\s*$/.test(value)
}

/**
 * Whether CSS (a style element or attribute) only uses local or embedded
 * resources: url(#…) and url(data:…), no @import, no script URLs.
 *
 * @param css - CSS text
 */
export function isSafeCss(css: string): boolean {
	// escapes could hide a forbidden word: refuse them altogether
	if (/\\/.test(css) || /@import|expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding/i.test(css)) {
		return false
	}
	for (const match of css.matchAll(/url\s*\(\s*(['"]?)([^'")]*)\1\s*\)/gi)) {
		const target = match[2].trim()
		if (!target.startsWith('#') && !/^data:(font|application\/(font|x-font)|image\/(png|jpeg|gif|webp))[^,]*,/i.test(target)) {
			return false
		}
	}
	// every url( must have been recognised above
	return (css.match(/url\s*\(/gi)?.length ?? 0) === [...css.matchAll(/url\s*\(\s*(['"]?)([^'")]*)\1\s*\)/gi)].length
}

let purifier: Purifier | null = null

/** A DOMPurify instance of its own, so that its hooks do not touch other users. */
function instance(): Purifier {
	if (purifier) {
		return purifier
	}
	const created = createPurifier(window)
	created.addHook('uponSanitizeElement', (node, data) => {
		if (data.tagName === 'style' && !isSafeCss(node.textContent ?? '')) {
			node.textContent = ''
		}
	})
	created.addHook('uponSanitizeAttribute', (_node, data) => {
		const name = data.attrName.toLowerCase()
		if (name.startsWith('on')) {
			data.keepAttr = false
		} else if (REFERENCE_ATTRIBUTES.has(name)) {
			data.keepAttr = isLocalReference(data.attrValue)
		} else if (name === 'style') {
			data.keepAttr = isSafeCss(data.attrValue)
		} else if (/url\s*\(/i.test(data.attrValue)) {
			// e.g. fill="url(#gradient)" – only local references
			data.keepAttr = isSafeCss(data.attrValue)
		}
	})
	purifier = created
	return created
}

/**
 * SVG text that is safe to put into the page with v-html. Fails closed:
 * without a DOM to clean in, nothing is returned.
 *
 * @param svg - SVG rendered by Verovio
 */
export function sanitizeSvg(svg: string): string {
	if (typeof window === 'undefined') {
		return ''
	}
	const cleaner = instance()
	if (!cleaner.isSupported) {
		return ''
	}
	return String(cleaner.sanitize(svg, { ...CONFIG, RETURN_TRUSTED_TYPE: false }))
}
