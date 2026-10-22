/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The tools as an agent sees them: names, descriptions, annotations, strict
 * input schemas, and that read-only mode leaves the writing tools out.
 */
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { TOOLS } from '../src/tools.js'
import { connect, fakeFetch, ocs, text } from './helpers.js'

const WRITING = ['answer_task', 'create_task', 'propose_change']
const EXPECTED = ['answer_task', 'create_task', 'get_concert', 'get_copy', 'list_concerts', 'list_due', 'list_my_proposals', 'list_tasks',
	'modules', 'propose_change', 'search_catalog', 'statistics', 'text_reference']

describe('tool definitions', () => {
	it('has one tool per use case with a clear description', () => {
		assert.deepEqual(TOOLS.map((tool) => tool.name).sort(), EXPECTED)
		for (const tool of TOOLS) {
			assert.match(tool.name, /^[a-z_]+$/)
			assert.ok(tool.title.length > 5, tool.name)
			assert.ok(tool.description.length >= 120, `${tool.name}: description for models`)
			assert.doesNotMatch(tool.description, /\bdelete\b|\bconfirm the rights\b/i, `${tool.name}: no tool promises a decision`)
		}
	})

	it('marks writing tools and only those', () => {
		assert.deepEqual(TOOLS.filter((tool) => tool.writes).map((tool) => tool.name).sort(), WRITING)
		for (const tool of TOOLS) {
			assert.equal(tool.annotations.readOnlyHint, !tool.writes, tool.name)
			assert.equal(tool.annotations.destructiveHint, false, `${tool.name}: nothing is deleted or overwritten`)
			assert.equal(tool.annotations.openWorldHint, false, `${tool.name}: only the archive`)
			assert.equal(tool.annotations.idempotentHint, !tool.writes, `${tool.name}: repeating a write adds another entry`)
		}
	})

	it('refuses unknown and invalid arguments', () => {
		const tool = (name: string) => TOOLS.find((entry) => entry.name === name)!
		assert.equal(tool('search_catalog').inputSchema.safeParse({ query: 'Mond', title: 'x' }).success, false, 'unknown argument')
		assert.equal(tool('search_catalog').inputSchema.safeParse({ limit: 101 }).success, false)
		assert.equal(tool('search_catalog').inputSchema.safeParse({ status: 'burnt' }).success, false)
		assert.equal(tool('search_catalog').inputSchema.safeParse({}).success, true)
		assert.equal(tool('get_copy').inputSchema.safeParse({ copy_id: '5-1' }).success, false, 'ids, not shelf marks')
		assert.equal(tool('create_task').inputSchema.safeParse({ copy_id: 1, question: 'x' }).success, false, 'addressee is required')
		assert.equal(tool('create_task').inputSchema.safeParse({ copy_id: 1, addressee: 'board', question: 'x' }).success, false)
		assert.equal(tool('create_task').inputSchema.safeParse({ copy_id: 1, addressee: 'conductor', question: 'x', measure_from: '17; DROP' })
			.success, false)
		assert.equal(tool('create_task').inputSchema.safeParse({ copy_id: 1, addressee: 'conductor', question: 'x', measure_from: '34X' })
			.success, true)
		assert.equal(tool('propose_change').inputSchema.safeParse({ level: 'version', object_id: 1, fields: {} }).success, false, 'no fields')
		assert.equal(tool('propose_change').inputSchema.safeParse({ level: 'version', object_id: 1, fields: { key_signature: { a: 1 } } })
			.success, false, 'single values only')
		assert.equal(tool('propose_change').inputSchema.safeParse({ level: 'licence', object_id: 1, fields: { a: 'b' } }).success, false)
		assert.equal(tool('propose_change').inputSchema.safeParse({ level: 'version', object_id: 1, fields: { key_signature: null } }).success, true)
	})
})

describe('the server', () => {
	it('lists every tool with schema and annotations', async () => {
		const client = await connect(fakeFetch().fetch)
		const { tools } = await client.listTools()
		assert.deepEqual(tools.map((tool) => tool.name).sort(), EXPECTED)
		const search = tools.find((tool) => tool.name === 'search_catalog')!
		assert.equal(search.inputSchema.type, 'object')
		assert.ok(search.inputSchema.properties && 'shelfmark' in search.inputSchema.properties)
		assert.equal(search.annotations?.readOnlyHint, true)
		const propose = tools.find((tool) => tool.name === 'propose_change')!
		assert.equal(propose.annotations?.readOnlyHint, false)
		assert.equal(propose.annotations?.destructiveHint, false)
		assert.deepEqual(propose.inputSchema.required?.sort(), ['fields', 'level', 'object_id'])
		const instructions = client.getInstructions() ?? ''
		assert.match(instructions, /modules/)
		await client.close()
	})

	it('leaves out the writing tools in read-only mode', async () => {
		const client = await connect(fakeFetch().fetch, true)
		const names = (await client.listTools()).tools.map((tool) => tool.name)
		for (const name of WRITING) {
			assert.ok(!names.includes(name), name)
		}
		assert.ok(names.includes('search_catalog'))
		const result = await client.callTool({ name: 'propose_change', arguments: { level: 'version', object_id: 1, fields: { a: 'b' } } })
		assert.equal(result.isError, true, 'an unknown tool cannot be called')
		await client.close()
	})

	it('calls the route and returns compact data', async () => {
		const { fetch, requests } = fakeFetch(ocs(200, { items: [{ id: 1, shelfmark: '1-1', title: 'Der Mond ist aufgegangen', composer: null }],
			total: 1, next_cursor: null }))
		const client = await connect(fetch)
		const result = await client.callTool({ name: 'search_catalog', arguments: { shelfmark: '1-1', confirmed: false, limit: 5 } })
		assert.notEqual(result.isError, true)
		assert.equal(text(result), '{"items":[{"id":1,"shelfmark":"1-1","title":"Der Mond ist aufgegangen"}],"total":1}')
		assert.deepEqual(result.structuredContent, { items: [{ id: 1, shelfmark: '1-1', title: 'Der Mond ist aufgegangen' }], total: 1 })
		const url = new URL(requests[0]!.url)
		assert.equal(url.pathname, '/nextcloud/ocs/v2.php/apps/notenarchiv/api/v1/catalog')
		assert.equal(url.searchParams.get('shelfmark'), '1-1')
		assert.equal(url.searchParams.get('confirmed'), 'false')
		assert.equal(url.searchParams.get('limit'), '5')
		assert.equal(url.searchParams.has('q'), false, 'missing arguments are not sent')
		await client.close()
	})

	it('sends writes as JSON bodies to the right route', async () => {
		const { fetch, requests } = fakeFetch(ocs(201, { id: 12, status: 'open' }), ocs(201, [{ id: 3, status: 'open' }]))
		const client = await connect(fetch)
		await client.callTool({ name: 'create_task', arguments: { copy_id: 7, addressee: 'conductor', question: 'fis oder f?', measure_from: '3' } })
		assert.ok(requests[0]!.url.endsWith('/api/v1/copies/7/tasks'))
		assert.equal(requests[0]!.init.method, 'POST')
		assert.deepEqual(JSON.parse(String(requests[0]!.init.body)), { addressee: 'conductor', question: 'fis oder f?', measure_from: '3' })
		const proposed = await client.callTool({ name: 'propose_change',
			arguments: { level: 'version', object_id: 4, fields: { key_signature: 'D major' }, source: 'test' } })
		assert.deepEqual(proposed.structuredContent, { items: [{ id: 3, status: 'open' }] }, 'a list is wrapped for structured content')
		assert.deepEqual(JSON.parse(String(requests[1]!.init.body)),
			{ level: 'version', object_id: 4, fields: { key_signature: 'D major' }, source: 'test' })
		await client.close()
	})

	it('reports refused requests as tool errors', async () => {
		const client = await connect(fakeFetch(ocs(404, [], 'This function is switched off.')).fetch)
		const result = await client.callTool({ name: 'list_tasks', arguments: {} })
		assert.equal(result.isError, true)
		assert.match(text(result), /switched off.*modules/)
		await client.close()
	})

	it('reports invalid arguments before any request', async () => {
		const { fetch, requests } = fakeFetch()
		const client = await connect(fetch)
		const result = await client.callTool({ name: 'get_copy', arguments: { copy_id: 0 } })
		assert.equal(result.isError, true)
		assert.equal(requests.length, 0)
		await client.close()
	})
})
