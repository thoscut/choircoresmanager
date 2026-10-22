/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Minimal type declarations for the parts of the verovio npm package
 * (LGPL-3.0-or-later) that the app uses. The package ships no types.
 */
declare module 'verovio/wasm' {
	/** Emscripten module factory; the WebAssembly binary and the music fonts are embedded. */
	const createVerovioModule: (options?: Record<string, unknown>) => Promise<unknown>
	export default createVerovioModule
}

declare module 'verovio/esm' {
	export class VerovioToolkit {

		constructor(module: unknown)
		destroy(): void
		getLog(): string
		getMEI(options?: Record<string, unknown>): string
		getMIDIValuesForElement(xmlId: string): { pitch: number, time: number, duration: number }
		getPageCount(): number
		getVersion(): string
		loadData(data: string): boolean
		redoLayout(options?: Record<string, unknown>): void
		renderToSVG(pageNo?: number, xmlDeclaration?: boolean): string
		renderToTimemap(options?: Record<string, unknown>): unknown
		select(selection: Record<string, unknown>): boolean
		setOptions(options: Record<string, unknown>): void

	}
	export function enableLogToBuffer(value: boolean, module: unknown): void
}
