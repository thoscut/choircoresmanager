/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import security from '../../SECURITY.md?raw'

/** The test files of the server and of the MCP server, by path relative to the repository. */
const TESTS = Object.fromEntries(Object.entries(import.meta.glob([
	'../../tests/Unit/**/*Test.php',
	'../../tests/Integration/*_test.php',
	'../../tools/mcp/test/*.test.ts',
], { query: '?raw', import: 'default', eager: true }))
	.map(([path, text]) => [path.replace('../../', ''), text as string]))

/** The rows of the table "Promises and their tests": promise and the cell with the tests. */
function rows(): [string, string][] {
	const section = security.split('## Promises and their tests')[1]?.split('\n## ')[0] ?? ''
	return section.split('\n')
		.filter((line) => line.startsWith('| ') && !line.startsWith('| Promise') && !line.startsWith('|---'))
		.map((line) => {
			const cells = line.split(' | ')
			return [cells[0]!.slice(2), cells.slice(1).join(' | ').replace(/ \|$/, '')]
		})
}

describe('the table "Promises and their tests" in SECURITY.md', () => {
	const table = rows()

	it('has rows', () => {
		expect(table.length).toBeGreaterThan(20)
	})

	it('names only test methods that exist', () => {
		const sources = Object.values(TESTS).join('\n')
		const missing: string[] = []
		for (const [promise, tests] of table) {
			for (const [, name] of tests.matchAll(/`(?:\w+::)?(test\w+)`/g)) {
				if (!sources.includes('function ' + name + '(')) {
					missing.push(promise + ': ' + name)
				}
			}
			for (const [, cls, name] of tests.matchAll(/`(\w+Test)::(test\w+)`/g)) {
				const file = Object.entries(TESTS).find(([path]) => path.endsWith('/' + cls + '.php'))
				if (file === undefined || !file[1].includes('function ' + name + '(')) {
					missing.push(promise + ': ' + cls + '::' + name)
				}
			}
		}
		expect(missing).toEqual([])
	})

	it('names only integration tests and test files that exist', () => {
		const missing: string[] = []
		for (const [promise, tests] of table) {
			for (const [, name] of tests.matchAll(/`(\w+_test)`/g)) {
				if (TESTS['tests/Integration/' + name + '.php'] === undefined) {
					missing.push(promise + ': ' + name)
				}
			}
			for (const [, cls] of tests.matchAll(/`(\w+Test)(?:::|`)/g)) {
				if (!Object.keys(TESTS).some((path) => path.endsWith('/' + cls + '.php'))) {
					missing.push(promise + ': ' + cls)
				}
			}
		}
		expect(missing).toEqual([])
	})

	it('quotes only names of tests and checks that exist', () => {
		const sources = Object.values(TESTS).join('\n')
		const missing: string[] = []
		for (const [promise, tests] of table) {
			for (const [, quoted] of tests.matchAll(/"([^"]+)"/g)) {
				if (!sources.includes(quoted!)) {
					missing.push(promise + ': ' + quoted)
				}
			}
		}
		expect(missing).toEqual([])
	})

	it('gives every promise a test or says that it is checked by review', () => {
		const untested = table.filter(([, tests]) => !/`[\w:/.]+`|^review:/.test(tests)).map(([promise]) => promise)
		expect(untested).toEqual([])
	})
})
