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
		},
		{
			createEmptyCSSEntryPoints: true,
			extractLicenseInformation: true,
			thirdPartyLicense: false,
		},
	)
