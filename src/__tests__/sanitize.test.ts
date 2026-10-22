/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { renderPages } from '../score/model.ts'
import { isLocalReference, isSafeCss, sanitizeSvg } from '../score/sanitize.ts'
import { createToolkit } from '../score/toolkit.ts'

describe('isLocalReference', () => {
	it('accepts references inside the document', () => {
		expect(isLocalReference('#E0A4-1')).toBe(true)
	})

	it('refuses everything else', () => {
		expect(isLocalReference('https://example.org/x.svg#a')).toBe(false)
		expect(isLocalReference('javascript:alert(1)')).toBe(false)
		expect(isLocalReference('data:image/svg+xml,<svg/>')).toBe(false)
		expect(isLocalReference('# x')).toBe(false)
	})
})

describe('isSafeCss', () => {
	it('accepts embedded fonts and local references', () => {
		expect(isSafeCss('@font-face { font-family: Leland; src: url(data:font/woff2;base64,AAEC+/==) format("woff2") }')).toBe(true)
		expect(isSafeCss('fill: url(#gradient); stroke: #000')).toBe(true)
		expect(isSafeCss('.measure { fill: currentColor }')).toBe(true)
	})

	it('refuses external resources and script', () => {
		expect(isSafeCss('@import url(https://example.org/a.css)')).toBe(false)
		expect(isSafeCss('background: url(https://example.org/track.png)')).toBe(false)
		expect(isSafeCss('background: url( "//example.org/x" )')).toBe(false)
		expect(isSafeCss('width: expression(alert(1))')).toBe(false)
		expect(isSafeCss('background: u\\72l(https://example.org)')).toBe(false)
		expect(isSafeCss('background: url(javascript:alert(1))')).toBe(false)
	})
})

describe('sanitizeSvg', () => {
	it('removes active and external content', () => {
		const dirty = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">
			<script>alert(1)</script>
			<a href="javascript:alert(1)"><text>x</text></a>
			<foreignObject><iframe src="https://example.org"></iframe></foreignObject>
			<image href="https://example.org/track.png" />
			<use xlink:href="https://example.org/sprite.svg#a" />
			<use href="data:image/svg+xml,&lt;svg onload=alert(1)&gt;" />
			<animate attributeName="href" to="javascript:alert(1)" />
			<set attributeName="onmouseover" to="alert(1)" />
			<rect style="background: url(https://example.org/x.png)" onclick="alert(1)" width="1" height="1" />
			<style>@import url(https://example.org/a.css);</style>
		</svg>`
		const clean = sanitizeSvg(dirty)
		for (const forbidden of ['script', 'alert', 'javascript', 'foreignObject', 'iframe', '<image', '<a', 'example.org', 'onload', 'onclick', '<animate', '<set', '@import']) {
			expect(clean, forbidden).not.toContain(forbidden)
		}
		expect(clean).toContain('<rect')
	})

	it('keeps what Verovio renders', async () => {
		const toolkit = await createToolkit()
		try {
			toolkit.setOptions({ inputFrom: 'musicxml', svgHtml5: true, svgAdditionalAttribute: ['staff@n', 'layer@n'], smuflTextFont: 'embedded', adjustPageHeight: true })
			const loaded = toolkit.loadData(`<?xml version="1.0" encoding="UTF-8"?>
<score-partwise version="4.0"><part-list><score-part id="P1"><part-name>Tenor</part-name></score-part></part-list>
<part id="P1"><measure number="1"><attributes><divisions>1</divisions><key><fifths>-1</fifths></key><time><beats>2</beats><beat-type>4</beat-type></time><clef><sign>G</sign><line>2</line></clef></attributes>
<direction placement="above"><direction-type><words>Tom &amp; Jerry &lt;b&gt;</words></direction-type></direction>
<direction placement="below"><direction-type><words>dolce </words><dynamics><p/></dynamics></direction-type></direction>
<note><pitch><step>G</step><octave>4</octave></pitch><duration>1</duration><type>quarter</type><accidental>sharp</accidental><lyric><text>Lied</text></lyric></note>
<note><pitch><step>B</step><alter>-1</alter><octave>4</octave></pitch><duration>1</duration><type>quarter</type></note></measure></part></score-partwise>`)
			expect(loaded).toBeTruthy()
			const [raw] = renderPages(toolkit)
			const clean = sanitizeSvg(raw)
			const count = (svg: string, pattern: RegExp) => svg.match(pattern)?.length ?? 0
			// glyph references, measures with their ids, staff numbers and texts survive
			expect(count(clean, /<use\b/g)).toBe(count(raw, /<use\b/g))
			expect(count(raw, /<use\b/g)).toBeGreaterThan(0)
			expect(count(clean, /data-id="/g)).toBe(count(raw, /data-id="/g))
			expect(count(clean, /data-n="/g)).toBe(count(raw, /data-n="/g))
			expect(clean).toContain('Lied')
			expect(clean).toContain('Tom &amp; Jerry &lt;b&gt;')
			expect(clean).not.toContain('<b>')
			// the embedded music font stays
			expect(raw).toContain('@font-face')
			expect(clean).toContain('@font-face')
			expect(clean.length).toBeGreaterThan(raw.length * 0.9)
		} finally {
			toolkit.destroy()
		}
	}, 30000)
})
