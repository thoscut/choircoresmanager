#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Entry point: reads the configuration from the environment and serves the
 * tools over stdio. Messages go to stderr (stdout belongs to the protocol)
 * and never contain the app password or the account.
 */
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js'
import { NotenarchivClient } from './client.js'
import { ConfigError, readConfig } from './config.js'
import { createServer } from './server.js'

try {
	const config = readConfig(process.env)
	const server = createServer(new NotenarchivClient(config))
	await server.connect(new StdioServerTransport())
	process.stderr.write(`notenarchiv-mcp: ready for ${new URL(config.baseUrl).host}${config.readOnly ? ' (read-only)' : ''}\n`)
} catch (error) {
	process.stderr.write(`notenarchiv-mcp: ${error instanceof ConfigError ? error.message : 'could not start.'}\n`)
	process.exit(2)
}
