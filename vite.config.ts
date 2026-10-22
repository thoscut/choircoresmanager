/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: CC0-1.0
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

// Unit tests (npm test) run the pure logic in Node, without the build plugins
const testConfig = {
	test: {
		include: ['src/**/*.test.ts'],
		environment: 'node',
	},
}

export default process.env.VITEST
	? testConfig
	: createAppConfig(
		{
			main: resolve(join('src', 'main.ts')),
			// Action "Open in music archive" in the Files app (small: no score viewer, no Vue)
			files: resolve(join('src', 'files.ts')),
		},
		{
			createEmptyCSSEntryPoints: true,
			extractLicenseInformation: true,
			thirdPartyLicense: false,
		},
	)
