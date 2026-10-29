/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
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

const WRITING = ['answer_task', 'create_task', 'propose_change', 'submit_transcription_report']
const EXPECTED = ['answer_task', 'create_task', 'get_concert', 'get_copy', 'get_review', 'list_concerts', 'list_correction_orders', 'list_due',
	'list_my_proposals', 'list_revisions', 'list_tasks', 'modules', 'propose_change', 'search_catalog', 'statistics', 'submit_transcription_report',
	'text_reference']
/** Writing tools whose repetition with the same arguments changes nothing (the same report is read once) */
const IDEMPOTENT_WRITING = ['submit_transcription_report']

describe('tool definitions', () => {
	it('has one tool per use case with a clear description', () => {
		assert.deepEqual(TOOLS.map((tool) => tool.name).sort(), EXPECTED)
		for (const tool of TOOLS) {
			assert.match(tool.name, /^[a-z_]+$/)
			assert.ok(tool.title.length > 5, tool.name)
			assert.ok(tool.description.length >= 120, `${tool.name}: description for models`)
			assert.doesNotMatch(tool.description, /\bdelete\b|\bconfirm the rights\b/i, `${tool.name}: no tool promises a decision`)
			assert.doesNotMatch(tool.name, /decide|decision|confirm|valid|licen|delete|restore|backup|setting|^correct/, `${tool.name}: no tool decides`)
		}
	})

	it('marks writing tools and only those', () => {
		assert.deepEqual(TOOLS.filter((tool) => tool.writes).map((tool) => tool.name).sort(), WRITING)
		for (const tool of TOOLS) {
			assert.equal(tool.annotations.readOnlyHint, !tool.writes, tool.name)
			assert.equal(tool.annotations.destructiveHint, false, `${tool.name}: nothing is deleted or overwritten`)
			assert.equal(tool.annotations.openWorldHint, false, `${tool.name}: only the archive`)
			assert.equal(tool.annotations.idempotentHint, !tool.writes || IDEMPOTENT_WRITING.includes(tool.name),
				`${tool.name}: repeating a write adds another entry, except the same report again`)
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
		assert.equal(tool('get_review').inputSchema.safeParse({ copy_id: 1, status: 'deleted' }).success, false)
		assert.equal(tool('get_review').inputSchema.safeParse({ copy_id: 1, role: 'operations' }).success, false, 'points belong to two roles')
		assert.equal(tool('get_review').inputSchema.safeParse({ copy_id: 1, status: 'correct', role: 'conductor', limit: 10 }).success, true)
		assert.equal(tool('list_correction_orders').inputSchema.safeParse({}).success, true)
		assert.equal(tool('list_correction_orders').inputSchema.safeParse({ copy_id: '1-1' }).success, false, 'ids, not shelf marks')
		const report = tool('submit_transcription_report').inputSchema
		assert.equal(report.safeParse({ copy_id: 1 }).success, false, 'a report is needed')
		assert.equal(report.safeParse({ copy_id: 1, report: { format: 'bericht/1' }, content: '{}' }).success, false, 'only one form')
		assert.equal(report.safeParse({ copy_id: 1, report: { format: 'bericht/1' }, status: 'confirmed' }).success, false, 'no decision')
		assert.equal(report.safeParse({ copy_id: 1, report: { format: 'bericht/1', unsicher: [] } }).success, true)
		assert.equal(report.safeParse({ copy_id: 1, content: '{"format":"bericht/1"}' }).success, true)
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
		assert.ok(names.includes('get_review') && names.includes('list_correction_orders'), 'reading the review stays')
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
		assert.equal(url.pathname, '/nextcloud/ocs/v2.php/apps/cantoria/api/v1/catalog')
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

	it('reads the review and the correction orders', async () => {
		const { fetch, requests } = fakeFetch(
			ocs(200, { copy_id: 7, shelfmark: '1-1', version_id: 3, counts: { open: 1 }, reports: [], items: [{ id: 12, status: 'open', voice: null }],
				total: 1, next_cursor: null }),
			ocs(200, { items: [{ id: 12, correction: 'd, as in the scan' }], total: 1, next_cursor: null }))
		const client = await connect(fetch)
		const review = await client.callTool({ name: 'get_review', arguments: { copy_id: 7, status: 'open', limit: 50 } })
		assert.notEqual(review.isError, true)
		assert.deepEqual((review.structuredContent as { items: unknown[] }).items, [{ id: 12, status: 'open' }])
		const url = new URL(requests[0]!.url)
		assert.equal(url.pathname, '/nextcloud/ocs/v2.php/apps/cantoria/api/v1/copies/7/review')
		assert.equal(url.searchParams.get('status'), 'open')
		assert.equal(url.searchParams.get('limit'), '50')
		assert.equal(url.searchParams.has('copy_id'), false, 'the id is part of the path')
		await client.callTool({ name: 'list_correction_orders', arguments: { copy_id: 7 } })
		const orders = new URL(requests[1]!.url)
		assert.equal(orders.pathname, '/nextcloud/ocs/v2.php/apps/cantoria/api/v1/correction_orders')
		assert.equal(orders.searchParams.get('copy_id'), '7')
		assert.equal(requests.every((request) => request.init.method === 'GET'), true)
		await client.close()
	})

	it('hands in a report as its text', async () => {
		const answer = { report: { id: 4, own: true }, unchanged: false, points: { created: 2, kept: 0, closed: 0, open: 2 }, proposals: 0,
			proposals_skipped: false, warnings: [] }
		const { fetch, requests } = fakeFetch(ocs(201, answer), ocs(200, { ...answer, unchanged: true }))
		const client = await connect(fetch)
		const report = { format: 'bericht/1', signatur: '1-1', basisname: '1-01_Test', unsicher: [{ takt: '3', stimme: 'T', was: 'cis or c' }] }
		const result = await client.callTool({ name: 'submit_transcription_report', arguments: { copy_id: 7, report } })
		assert.notEqual(result.isError, true)
		assert.ok(requests[0]!.url.endsWith('/api/v1/copies/7/transcription_reports'))
		assert.equal(requests[0]!.init.method, 'POST')
		const body = JSON.parse(String(requests[0]!.init.body)) as { content: string }
		assert.deepEqual(Object.keys(body), ['content'], 'sent as text, so that the evidence is what the agent wrote')
		assert.deepEqual(JSON.parse(body.content), report)
		const text = '{"format":"bericht/1","signatur":"1-1"}'
		const again = await client.callTool({ name: 'submit_transcription_report', arguments: { copy_id: 7, content: text } })
		assert.equal((again.structuredContent as { unchanged: boolean }).unchanged, true)
		assert.deepEqual(JSON.parse(String(requests[1]!.init.body)), { content: text })
		const both = await client.callTool({ name: 'submit_transcription_report', arguments: { copy_id: 7, report, content: text } })
		assert.equal(both.isError, true)
		assert.equal(requests.length, 2, 'refused before any request')
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
