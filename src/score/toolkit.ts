/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Loading Verovio (music engraving, LGPL-3.0-or-later). The package embeds
 * its WebAssembly binary and the music fonts in its JavaScript module, so
 * everything comes from the app's own bundle – no CDN, no other server. The
 * module is imported dynamically: it only loads when a score is opened.
 */
import type { VerovioToolkit } from 'verovio/esm'

export type { VerovioToolkit }

let modulePromise: Promise<unknown> | null = null

/**
 * Load the modules of Verovio without starting it, so that the service
 * worker keeps them for practising without a network (milestone 0.15).
 */
export async function preloadToolkit(): Promise<void> {
	await Promise.all([import('verovio/wasm'), import('verovio/esm')])
}

/**
 * A new toolkit instance. Destroy it with `toolkit.destroy()` when done.
 */
export async function createToolkit(): Promise<VerovioToolkit> {
	if (modulePromise === null) {
		modulePromise = (async () => {
			const [{ default: createVerovioModule }, { enableLogToBuffer }] = await Promise.all([
				import('verovio/wasm'),
				import('verovio/esm'),
			])
			const module = await createVerovioModule()
			// Keep Verovio's warnings out of the console; they are available via getLog()
			enableLogToBuffer(true, module)
			return module
		})()
		modulePromise.catch(() => {
			modulePromise = null
		})
	}
	const [module, { VerovioToolkit: Toolkit }] = await Promise.all([modulePromise, import('verovio/esm')])
	return new Toolkit(module)
}
