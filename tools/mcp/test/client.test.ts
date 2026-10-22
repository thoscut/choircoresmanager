/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The HTTP client and the configuration: authentication headers, mapping of
 * HTTP errors, read-only mode, and that no secret appears in any output.
 */
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { ApiError, NotenarchivClient } from '../src/client.js'
import { ConfigError, readConfig } from '../src/config.js'
import { config, connect, fakeFetch, ocs, SECRET, text, USER } from './helpers.js'

/**
 * The error a call ends with.
 *
 * @param call - the call
 */
async function failure(call: () => Promise<unknown>): Promise<ApiError> {
	try {
		await call()
	} catch (error) {
		assert.ok(error instanceof ApiError, String(error))
		return error
	}
	assert.fail('no error')
}

describe('requests', () => {
	it('sign in with the app password and the OCS header', async () => {
		const { fetch, requests } = fakeFetch(ocs(200, { api_version: 1 }))
		const data = await new NotenarchivClient(config(), fetch).get('/modules')
		assert.deepEqual(data, { api_version: 1 })
		const headers = requests[0]!.init.headers as Record<string, string>
		assert.equal(headers.Authorization, 'Basic ' + Buffer.from(`${USER}:${SECRET}`).toString('base64'))
		assert.equal(headers['OCS-APIRequest'], 'true')
		assert.equal(headers.Accept, 'application/json')
		assert.equal(requests[0]!.init.redirect, 'manual', 'credentials never follow a redirect')
		assert.ok(!requests[0]!.url.includes(SECRET), 'never in the URL')
	})

	it('map HTTP errors to kinds an agent can act on', async () => {
		const cases: [Response, ApiError['kind'], RegExp][] = [
			[ocs(401, [], ''), 'auth', /NOTENARCHIV_APP_PASSWORD/],
			[ocs(403, [], 'This requires one of these roles: Catalog (music librarian)'), 'forbidden', /Not permitted.*Catalog/],
			[ocs(404, [], 'This function is switched off.'), 'not_found', /switched off/],
			[ocs(404, [], ''), 'not_found', /agent_api/],
			[ocs(400, [], 'The limit must be between 1 and 100.'), 'invalid', /limit/],
			[new Response('', { status: 429 }), 'rate_limited', /Wait/],
			[ocs(500, [], 'Internal error. Details are in the Nextcloud log.'), 'server', /HTTP 500/],
			[new Response('', { status: 302, headers: { Location: 'https://elsewhere.example/' } }), 'server', /redirect/],
			[new Response('<html>login</html>', { status: 200 }), 'server', /not an OCS answer/],
		]
		for (const [response, kind, message] of cases) {
			const error = await failure(() => new NotenarchivClient(config(), fakeFetch(response).fetch).get('/catalog'))
			assert.equal(error.kind, kind, message.source)
			assert.match(error.message, message)
		}
	})

	it('report a network failure without secrets', async () => {
		const cause = new Error(`connect ECONNREFUSED; Authorization: Basic ${Buffer.from(`${USER}:${SECRET}`).toString('base64')} ${SECRET}`)
		const error = await failure(() => new NotenarchivClient(config(), fakeFetch(new TypeError('fetch failed', { cause })).fetch).get('/due'))
		assert.equal(error.kind, 'network')
		assert.match(error.message, /cloud\.example\.org/)
		assert.ok(!error.message.includes(SECRET))
		assert.ok(!error.message.includes(Buffer.from(`${USER}:${SECRET}`).toString('base64')))
	})

	it('send nothing in read-only mode', async () => {
		const { fetch, requests } = fakeFetch()
		const error = await failure(() => new NotenarchivClient(config(true), fetch).post('/proposals', { level: 'work' }))
		assert.equal(error.kind, 'read_only')
		assert.equal(requests.length, 0)
	})
})

describe('secrets', () => {
	it('never appear in tool results, also when a server repeats them', async () => {
		const client = await connect(fakeFetch(
			ocs(403, [], `Wrong app password ${SECRET}`),
			ocs(200, { note: `echo ${SECRET}` }),
			new Error(`boom ${SECRET}`),
		).fetch)
		for (const name of ['list_due', 'modules', 'statistics']) {
			const result = await client.callTool({ name, arguments: {} })
			const output = JSON.stringify(result)
			assert.ok(!output.includes(SECRET), `${name}: ${output}`)
			assert.ok(output.includes('[redacted]'), name)
		}
		await client.close()
	})

	it('are not part of configuration errors', () => {
		const env = { NOTENARCHIV_URL: 'https://cloud.example.org', NOTENARCHIV_USER: USER, NOTENARCHIV_APP_PASSWORD: SECRET }
		const messages: string[] = []
		for (const broken of [
			{ ...env, NOTENARCHIV_URL: `https://${USER}:${SECRET}@cloud.example.org` },
			{ ...env, NOTENARCHIV_URL: 'http://cloud.example.org' },
			{ ...env, NOTENARCHIV_URL: 'not a url' },
			{ ...env, NOTENARCHIV_APP_PASSWORD: '' },
			{ ...env, NOTENARCHIV_USER: undefined },
			{ ...env, NOTENARCHIV_READ_ONLY: SECRET },
		]) {
			assert.throws(() => readConfig(broken), (error: unknown) => {
				assert.ok(error instanceof ConfigError)
				messages.push(error.message)
				return true
			})
		}
		assert.ok(messages.every((message) => !message.includes(SECRET)), messages.join('\n'))
	})
})

describe('configuration', () => {
	it('reads the environment', () => {
		const read = readConfig({ NOTENARCHIV_URL: 'https://example.org/nextcloud/', NOTENARCHIV_USER: 'bot', NOTENARCHIV_APP_PASSWORD: 'x-y-z',
			NOTENARCHIV_READ_ONLY: '1' })
		assert.deepEqual(read, { baseUrl: 'https://example.org/nextcloud', user: 'bot', appPassword: 'x-y-z', readOnly: true })
		assert.equal(readConfig({ NOTENARCHIV_URL: 'http://127.0.0.1:8080', NOTENARCHIV_USER: 'bot', NOTENARCHIV_APP_PASSWORD: 'p' }).readOnly, false,
			'plain http only for local tests')
	})

	it('builds the text of a tool error from the server message', async () => {
		const client = await connect(fakeFetch(ocs(400, [], '"Status": value not allowed.')).fetch)
		const result = await client.callTool({ name: 'list_tasks', arguments: { scope: 'mine' } })
		assert.equal(text(result), '"Status": value not allowed.')
		await client.close()
	})
})
