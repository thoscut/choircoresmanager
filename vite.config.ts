/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: CC0-1.0
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'
import { build } from 'vite'

// Unit tests (npm test) run the pure logic in Node, without the build plugins;
// tools/l10n checks the translations
const testConfig = {
	test: {
		include: ['src/**/*.test.ts', 'tools/l10n/*.test.mjs'],
		environment: 'node',
	},
}

/**
 * The service worker of the pages that keep pieces on a device (milestone
 * 0.15): a classic script without imports, built after the app into
 * js/cantoria-offline-worker.js; OfflineController::worker serves it from
 * the app's path, so that it covers the pages.
 */
const offlineWorker = {
	name: 'cantoria-offline-worker',
	apply: 'build' as const,
	async closeBundle() {
		await build({
			configFile: false,
			logLevel: 'warn',
			build: {
				outDir: 'js',
				emptyOutDir: false,
				copyPublicDir: false,
				minify: true,
				lib: {
					entry: resolve(join('src', 'offline', 'worker.ts')),
					formats: ['iife'],
					name: 'cantoriaOfflineWorker',
					fileName: () => 'cantoria-offline-worker.js',
				},
			},
		})
	},
}

export default process.env.VITEST
	? testConfig
	: createAppConfig(
		{
			main: resolve(join('src', 'main.ts')),
			// Action "Open in music archive" in the Files app (small: no score viewer, no Vue)
			files: resolve(join('src', 'files.ts')),
			// Administration → Music archive: only the settings, without the
			// catalog and the score viewer
			'admin-settings': resolve(join('src', 'adminSettings.ts')),
			// Page "Shared with me" for choir members (milestone 0.7: excerpts only, no catalog)
			member: resolve(join('src', 'member.ts')),
			// Page of a public link (milestone 0.7: one excerpt, no further requests)
			public: resolve(join('src', 'public.ts')),
			// Concert folder of the conductor (milestone 0.15: programmes, also without a network)
			'concert-folder': resolve(join('src', 'concertFolder.ts')),
		},
		{
			createEmptyCSSEntryPoints: true,
			extractLicenseInformation: true,
			thirdPartyLicense: false,
			config: {
				plugins: [offlineWorker],
			},
		},
	)
