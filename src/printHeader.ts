/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The header and the page numbers of printouts (milestone 0.12): a printed
 * list says which choir, which view, which filter and which day it shows,
 * and each page its number, so that loose sheets can be put in order again.
 */
import { t } from './l10n.ts'

export interface PrintHeader {
	/** name of the Nextcloud (Theming), usually the choir; may be empty */
	site: string
	/** title of the view */
	view: string
	/** the filters that are on, in words; empty without */
	filter: string
	/** the day of printing, already formatted */
	date: string
}

/**
 * The line at the top of a printout: choir · view · filter · date.
 *
 * @param header - the parts
 */
export function printHeaderLine(header: PrintHeader): string {
	return [
		header.site.trim(),
		header.view.trim(),
		header.filter.trim() === '' ? '' : t('notenarchiv', 'Filter: {filter}', { filter: header.filter.trim() }),
		t('notenarchiv', 'Printed on {date}', { date: header.date }),
	].filter((part) => part !== '').join(' · ')
}

/**
 * A text for CSS `content` with the page counters: "Page {page} of {pages}"
 * becomes `"Page " counter(page) " of " counter(pages)`.
 *
 * @param template - translated text with {page} and {pages}
 */
export function pageCounterContent(template: string): string {
	const quote = (text: string) => '"' + text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ') + '"'
	return template.split(/(\{page\}|\{pages\})/)
		.filter((part) => part !== '')
		.map((part) => part === '{page}' ? 'counter(page)' : part === '{pages}' ? 'counter(pages)' : quote(part))
		.join(' ')
}

const STYLE_ID = 'notenarchiv-page-numbers'

/**
 * Page numbers at the foot of every printed page, once per document.
 * Browsers without page margin boxes print without them.
 */
export function addPageNumbers(): void {
	if (document.getElementById(STYLE_ID) !== null) {
		return
	}
	const style = document.createElement('style')
	style.id = STYLE_ID
	// {page} and {pages} are kept as they are: they become the counters
	const content = pageCounterContent(t('notenarchiv', 'Page {page} of {pages}', { page: '{page}', pages: '{pages}' }))
	style.textContent = `@media print { @page { @bottom-center { content: ${content}; font-size: 9pt; } } }`
	document.head.appendChild(style)
}
