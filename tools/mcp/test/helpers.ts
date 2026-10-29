/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Test doubles: a configuration with a recognisable secret and a fetch that
 * answers from a list and records the requests.
 */
import type { Fetch } from '../src/client.js'
import type { Config } from '../src/config.js'

import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js'
import { CantoriaClient } from '../src/client.js'
import { createServer } from '../src/server.js'

export const SECRET = 'Secr3t-AppPass-wOrd-12345'
export const USER = 'agent-bot'

export const config = (readOnly = false): Config => ({
	baseUrl: 'https://cloud.example.org/nextcloud',
	user: USER,
	appPassword: SECRET,
	readOnly,
})

/** A recorded request */
export interface Recorded {
	url: string
	init: RequestInit
}

/**
 * An OCS v2 answer.
 *
 * @param status - HTTP status
 * @param data - ocs.data
 * @param message - ocs.meta.message
 */
export function ocs(status: number, data: unknown, message = ''): Response {
	return new Response(JSON.stringify({ ocs: { meta: { status: status < 300 ? 'ok' : 'failure', statuscode: status, message }, data } }),
		{ status, headers: { 'Content-Type': 'application/json' } })
}

/**
 * A fetch that answers with the given responses in turn.
 *
 * @param answers - responses or errors to throw
 */
export function fakeFetch(...answers: (Response | Error)[]): { fetch: Fetch, requests: Recorded[] } {
	const requests: Recorded[] = []
	const fetch: Fetch = async (url, init) => {
		requests.push({ url, init })
		const answer = answers.shift()
		if (answer === undefined) {
			throw new Error('no answer left')
		}
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	}
	return { fetch, requests }
}

/**
 * An MCP client connected in memory to a server with the given fetch.
 *
 * @param fetch - the fetch of the API client
 * @param readOnly - read-only mode
 */
export async function connect(fetch: Fetch, readOnly = false): Promise<Client> {
	const server = createServer(new CantoriaClient(config(readOnly), fetch))
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: 'test', version: '1.0.0' })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	return client
}

/**
 * The text of a tool result.
 *
 * @param result - result of callTool
 */
export function text(result: unknown): string {
	const content = (result as { content?: { type: string, text?: string }[] }).content ?? []
	return content.map((part) => part.text ?? '').join('\n')
}
