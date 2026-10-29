/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The MCP server: registers the tools (only the reading ones in read-only
 * mode) and turns their results and errors into compact tool results.
 */
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js'
import type { CantoriaClient } from './client.js'
import type { ToolDefinition } from './tools.js'

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { ApiError } from './client.js'
import { INSTRUCTIONS, TOOLS } from './tools.js'

export const SERVER_NAME = 'cantoria'
export const SERVER_VERSION = '1.0.0'

/**
 * The tools this server offers: all, or only the reading ones.
 *
 * @param readOnly - whether writing tools are left out
 */
export function availableTools(readOnly: boolean): ToolDefinition[] {
	return TOOLS.filter((tool) => !readOnly || !tool.writes)
}

/**
 * Drops null values from objects (not from lists), so that results stay
 * short; a missing field means "no value".
 *
 * @param value - data of an answer
 */
export function compact(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.map(compact)
	}
	if (typeof value === 'object' && value !== null) {
		return Object.fromEntries(Object.entries(value)
			.filter(([, entry]) => entry !== null)
			.map(([key, entry]) => [key, compact(entry)]))
	}
	return value
}

/**
 * Runs a tool and builds its result. Errors become results with isError,
 * so that the agent reads the reason; their text passes redact().
 *
 * @param tool - the tool
 * @param client - the API client
 * @param args - validated arguments
 */
export async function runTool(tool: ToolDefinition, client: CantoriaClient, args: Record<string, unknown>): Promise<CallToolResult> {
	try {
		if (tool.writes && client.readOnly) {
			throw new ApiError('read_only', 0, 'The server runs read-only (CANTORIA_READ_ONLY=1): nothing is written.')
		}
		// Redacted as JSON text, so that text and structured content are the same.
		const json = client.redact(JSON.stringify(compact(await tool.run(client, args)) ?? null))
		const data: unknown = JSON.parse(json)
		return {
			content: [{ type: 'text', text: json }],
			structuredContent: typeof data === 'object' && data !== null && !Array.isArray(data)
				? data as Record<string, unknown>
				: { items: data },
		}
	} catch (error) {
		const message = error instanceof ApiError
			? error.message
			: 'The tool failed unexpectedly: ' + (error instanceof Error ? error.message : String(error))
		return { isError: true, content: [{ type: 'text', text: client.redact(message) }] }
	}
}

/**
 * The MCP server with its tools.
 *
 * @param client - the API client (configured, with the read-only flag)
 */
export function createServer(client: CantoriaClient): McpServer {
	const server = new McpServer({ name: SERVER_NAME, version: SERVER_VERSION }, { instructions: INSTRUCTIONS })
	for (const tool of availableTools(client.readOnly)) {
		server.registerTool(tool.name, {
			title: tool.title,
			description: tool.description,
			inputSchema: tool.inputSchema,
			annotations: { title: tool.title, ...tool.annotations },
		}, (args: Record<string, unknown>) => runTool(tool, client, args))
	}
	return server
}
