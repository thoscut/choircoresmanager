/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A run of an agent without a language model, against a Nextcloud with the
 * demo data (tools/ci/integration-demo.sh, step 7): gets an app password
 * for the agent's account, starts the MCP server over stdio like a real
 * client and calls its tools, then checks what the server must refuse.
 *
 * The review of transcriptions: the agent reads the review points of the
 * demo report of the copy (read by the file sync in step 5), hands in a
 * new round of that report with one more point, and must not be able to
 * decide a point: api/v1 has no such route, and the route of the UI
 * refuses the role "contributor" (403). tools/ci/agent-demo-cleanup.php
 * dismisses the agent's points afterwards.
 *
 * Environment:
 *   CANTORIA_URL       Nextcloud, e.g. http://127.0.0.1:8080
 *   AGENT_USER            the agent's account (role "contributor")
 *   AGENT_LOGIN_PASSWORD  its login password, used once to get an app password
 *   NC_ROOT               the Nextcloud directory (for occ)
 *   PHP                   PHP command (default: php)
 *   SHELFMARK             a copy of the demo data with a demo transcription
 *                         report in tools/demo/reports (1-1 or 2-1; default 1-1)
 *
 * Neither password is printed. The app password is deleted at the end,
 * also after a failure. Exit code 0 only when every check passed.
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { getDefaultEnvironment, StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js'
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const env = (name, fallback) => {
	const value = process.env[name] ?? fallback
	if (value === undefined || value === '') {
		console.error(`${name} is not set`)
		process.exit(2)
	}
	return value
}
const url = env('CANTORIA_URL').replace(/\/+$/, '')
const user = env('AGENT_USER')
const loginPassword = env('AGENT_LOGIN_PASSWORD')
const ncRoot = env('NC_ROOT')
const php = env('PHP', 'php')
const shelfmark = env('SHELFMARK', '1-1')
const here = dirname(fileURLToPath(import.meta.url))
const server = join(here, '..', 'dist', 'src', 'index.js')
/** The demo transcription report of the copy (format bericht/1), as the file sync read it */
const demoReport = JSON.parse(readFileSync(join(here, '..', '..', 'demo', 'reports', `${shelfmark}.json`), 'utf8'))

let failures = 0
let checks = 0
const secrets = [loginPassword]
const redact = (text) => secrets.reduce((result, secret) => result.split(secret).join('[redacted]'), String(text))
const check = (name, ok, detail = '') => {
	checks++
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail === '' ? '' : ' — ' + redact(detail)}`)
	if (!ok) {
		failures++
	}
}
const basic = (password) => 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64')
/** A plain OCS request, without the MCP server. */
const request = async (method, path, password, body) => {
	const response = await fetch(url + path, {
		method,
		headers: { Authorization: basic(password), 'OCS-APIRequest': 'true', Accept: 'application/json',
			...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
		body: body === undefined ? undefined : JSON.stringify(body),
		redirect: 'manual',
	})
	const text = await response.text()
	let json = null
	try {
		json = JSON.parse(text)
	} catch {
		// not JSON
	}
	return { status: response.status, json }
}
const occRun = (...args) => execFileSync(php, ['-d', 'memory_limit=1G', join(ncRoot, 'occ'), '--no-warnings', ...args],
	{ stdio: ['ignore', 'pipe', 'inherit'] }).toString()

/** Starts the MCP server like Claude Desktop or Claude Code would. */
const connect = async (appPassword, extra = {}) => {
	const transport = new StdioClientTransport({
		command: process.execPath,
		args: [server],
		env: { ...getDefaultEnvironment(), CANTORIA_URL: url, CANTORIA_USER: user, CANTORIA_APP_PASSWORD: appPassword, ...extra },
		stderr: 'pipe',
	})
	let stderr = ''
	transport.stderr?.on('data', (chunk) => {
		stderr += chunk
	})
	const client = new Client({ name: 'cantoria-ci-agent', version: '1.0.0' })
	await client.connect(transport)
	return { client, stderr: () => stderr }
}
const data = (result) => {
	const text = (result.content ?? []).map((part) => part.text ?? '').join('')
	try {
		return JSON.parse(text)
	} catch {
		return text
	}
}

// ---- 1. App password of the agent's account ----
const login = await request('GET', '/ocs/v2.php/core/getapppassword', loginPassword)
const appPassword = login.json?.ocs?.data?.apppassword
check('app password from /ocs/v2.php/core/getapppassword', login.status === 200 && typeof appPassword === 'string' && appPassword.length >= 20,
	`HTTP ${login.status}`)
if (typeof appPassword !== 'string') {
	process.exit(1)
}
secrets.push(appPassword, Buffer.from(`${user}:${appPassword}`).toString('base64'), basic(loginPassword).slice(6))

try {
	// ---- 2. The MCP server over stdio ----
	const { client, stderr } = await connect(appPassword)
	const tools = (await client.listTools()).tools
	const names = tools.map((tool) => tool.name)
	check('tools/list', ['search_catalog', 'get_copy', 'list_due', 'list_tasks', 'create_task', 'answer_task', 'propose_change', 'text_reference',
		'list_concerts', 'get_concert', 'statistics', 'modules', 'get_review', 'list_correction_orders', 'submit_transcription_report']
		.every((name) => names.includes(name)), names.join(', '))
	check('no tool decides', !names.some((name) => /confirm|valid|licen|delete|restore|backup|setting|decide|decision/.test(name)))
	check('writing tools are marked', tools.filter((tool) => tool.annotations?.readOnlyHint === false).map((tool) => tool.name).sort().join(',')
		=== 'answer_task,create_task,propose_change,submit_transcription_report')

	const status = data(await client.callTool({ name: 'modules', arguments: {} }))
	check('modules: role contributor, interface on, may propose and hand in reports', status?.roles?.includes('contributor')
		&& status?.modules?.agent_api === true && status?.modules?.transcription_review === true && status?.permissions?.propose === true
		&& status?.permissions?.submit_transcription_reports === true && status?.permissions?.view_preservation_copies === false,
		JSON.stringify(status?.roles))

	const found = data(await client.callTool({ name: 'search_catalog', arguments: { shelfmark } }))
	const copy = found?.items?.[0]
	check(`search_catalog finds ${shelfmark}`, copy?.shelfmark === shelfmark && found.total === 1, JSON.stringify(found).slice(0, 200))
	const hidden = data(await client.callTool({ name: 'search_catalog', arguments: { query: 'Mond' } }))
	check('the preservation copy 3-1 is not listed for a contributor', Array.isArray(hidden?.items)
		&& !hidden.items.some((row) => row.shelfmark === '3-1'), JSON.stringify(hidden?.items?.map((row) => row.shelfmark)))

	const due = data(await client.callTool({ name: 'list_due', arguments: {} }))
	check('list_due', typeof due === 'object' && due !== null && !Array.isArray(due), JSON.stringify(due).slice(0, 200))

	const reference = data(await client.callTool({ name: 'text_reference',
		arguments: { copy_id: copy?.id ?? 0, measure_from: '1', measure_to: '4', voice: 'Sopran' } }))
	check('text_reference', typeof reference?.text === 'string' && reference.text.startsWith(shelfmark + ' · '), JSON.stringify(reference))

	// The field is proposed only to be rejected again by the cleanup.
	const proposed = data(await client.callTool({ name: 'propose_change', arguments: { level: 'version', object_id: copy?.version_id ?? 0,
		fields: { key_signature: 'CI demo, to be rejected' }, reason: 'CI run of the agent interface', source: 'ci-agent-demo' } }))
	// One proposal per field: the result is a list.
	const proposal = Array.isArray(proposed) ? proposed[0] : undefined
	check('propose_change creates an open proposal', proposal?.status === 'open' && proposal?.own === true, JSON.stringify(proposed).slice(0, 200))
	const mine = data(await client.callTool({ name: 'list_my_proposals', arguments: { status: 'open' } }))
	check('the own proposals list it', proposal !== undefined && mine?.items?.some((entry) => entry.id === proposal.id), JSON.stringify(mine).slice(0, 200))

	const task = data(await client.callTool({ name: 'create_task', arguments: { copy_id: copy?.id ?? 0, kind: 'check', addressee: 'librarian',
		question: 'CI demo: please check bar 1 (will be discarded).', measure_from: '1', origin: 'ci-agent-demo' } }))
	check('create_task creates an open task', task?.status === 'open' && task?.own === true, JSON.stringify(task).slice(0, 200))

	const refused = await client.callTool({ name: 'answer_task', arguments: { task_id: task?.id ?? 0, answer: 'not mine' } })
	check('answer_task for another role is refused', refused.isError === true && /Not permitted/.test(data(refused)), String(data(refused)))

	// ---- Review of transcriptions ----
	const reviewText = (result) => (result.content ?? []).map((part) => part.text ?? '').join('')
	const reviewResult = await client.callTool({ name: 'get_review', arguments: { copy_id: copy?.id ?? 0, limit: 100 } })
	const review = data(reviewResult)
	const demoPoints = review?.items ?? []
	check(`get_review lists the review points of the demo report of ${shelfmark}`, review?.shelfmark === shelfmark
		&& review?.reports?.length >= 1 && demoPoints.length >= 1 && demoPoints.length === review?.total
		&& demoPoints.every((point) => point.own === false) && demoPoints.some((point) => point.measure_from !== undefined && point.voice !== undefined),
		JSON.stringify(review).slice(0, 300))
	check('the review names no account and carries no received text', !/"(decided_by|decided_by_name|created_by|raw|point_key)"/.test(reviewText(reviewResult)))
	const orders = data(await client.callTool({ name: 'list_correction_orders', arguments: {} }))
	check('list_correction_orders answers with a page', Array.isArray(orders?.items) && typeof orders?.total === 'number', JSON.stringify(orders).slice(0, 200))

	// A new round of the demo report with one more uncertain reading: the
	// points of the demo report are named again, one point is new.
	const round = { ...demoReport, runde: 'ci-agent-demo',
		unsicher: [...demoReport.unsicher, { takt: '1', stimme: 'Sopran', was: 'CI demo: first note checked by the agent (will be dismissed)' }] }
	const submitted = data(await client.callTool({ name: 'submit_transcription_report', arguments: { copy_id: copy?.id ?? 0, report: round } }))
	check('submit_transcription_report reads the new round', submitted?.unchanged === false && submitted?.points?.created === 1
		&& submitted?.points?.kept >= 1 && submitted?.report?.own === true, JSON.stringify(submitted).slice(0, 300))
	const again = data(await client.callTool({ name: 'submit_transcription_report', arguments: { copy_id: copy?.id ?? 0, report: round } }))
	check('the same report again creates nothing', again?.unchanged === true && again?.report?.id === submitted?.report?.id, JSON.stringify(again).slice(0, 200))
	const foreign = await client.callTool({ name: 'submit_transcription_report',
		arguments: { copy_id: copy?.id ?? 0, report: { ...round, signatur: '3-1' } } })
	check('a report naming another copy is refused', foreign.isError === true, String(data(foreign)))
	const after = data(await client.callTool({ name: 'get_review', arguments: { copy_id: copy?.id ?? 0, limit: 100 } }))
	const ownPoint = after?.items?.find((point) => point.own === true)
	check('the review shows the new round and its point', after?.reports?.[0]?.id === submitted?.report?.id && after?.reports?.[0]?.own === true
		&& after?.reports?.[1]?.superseded_by === submitted?.report?.id && ownPoint?.status === 'open' && ownPoint?.voice === 'Sopran',
		JSON.stringify(after?.reports?.map((report) => [report.id, report.superseded_by])))

	// ---- 3. What the server refuses ----
	// Deciding a review point is for people: api/v1 has no route, the route of the UI checks the role.
	const point = demoPoints.find((entry) => entry.role === 'conductor') ?? demoPoints[0]
	for (const path of [`review_points/${point?.id ?? 0}/decision`, `review-points/${point?.id ?? 0}/decision`, `copies/${copy?.id ?? 0}/corrections`]) {
		const missing = await request('POST', `/ocs/v2.php/apps/cantoria/api/v1/${path}`, appPassword, { status: 'confirmed' })
		check(`api/v1 has no route ${path.replace(/\d+/g, '{id}')} (404/405)`, missing.status === 404 || missing.status === 405, `HTTP ${missing.status}`)
	}
	const decision = await request('POST', `/index.php/apps/cantoria/api/review-points/${point?.id ?? 0}/decision`, appPassword,
		{ status: 'confirmed', reason: 'agent' })
	check('deciding a review point with the app password is refused (403)', decision.status === 403, `HTTP ${decision.status}`)
	const correction = await request('POST', `/index.php/apps/cantoria/api/versions/${copy?.version_id ?? 0}/corrections`, appPassword,
		{ fileId: 0, content: '<score-partwise/>', pointIds: [point?.id ?? 0] })
	check('saving a correction with the app password is refused (403)', correction.status === 403, `HTTP ${correction.status}`)
	const unchanged = data(await client.callTool({ name: 'get_review', arguments: { copy_id: copy?.id ?? 0, status: 'open', limit: 100 } }))
	check('the point stays open', unchanged?.items?.some((entry) => entry.id === point?.id) === true)

	const confirm = await request('POST', `/index.php/apps/cantoria/api/versions/${copy?.version_id ?? 0}/confirm`, appPassword,
		{ status: 'public_domain', reason: 'agent', evidence: 'agent' })
	check('confirming rights with the app password is refused (403)', confirm.status === 403, `HTTP ${confirm.status}`)
	const noRoute = await request('POST', `/ocs/v2.php/apps/cantoria/api/v1/versions/${copy?.version_id ?? 0}/confirm`, appPassword, {})
	check('api/v1 has no route to confirm (404/405)', noRoute.status === 404 || noRoute.status === 405, `HTTP ${noRoute.status}`)

	occRun('cantoria:modules', 'disable', 'agent_api')
	try {
		const off = await request('GET', '/ocs/v2.php/apps/cantoria/api/v1/modules', appPassword)
		check('without agent_api the API answers 404 with OCS meta', off.status === 404 && off.json?.ocs?.meta?.statuscode === 404
			&& off.json?.ocs?.meta?.message === 'This function is switched off.', `HTTP ${off.status} ${JSON.stringify(off.json?.ocs?.meta)}`)
		const offTool = await client.callTool({ name: 'search_catalog', arguments: {} })
		check('and the tool reports it', offTool.isError === true && /switched off/.test(data(offTool)), String(data(offTool)))
	} finally {
		occRun('cantoria:modules', 'enable', 'agent_api')
	}
	check('switched on again', !(await client.callTool({ name: 'list_due', arguments: {} })).isError)

	await client.close()
	check('the server log holds no secret and no account', !secrets.some((secret) => stderr().includes(secret)) && !stderr().includes(user),
		stderr().trim())

	// ---- 4. Read-only mode ----
	const readOnly = await connect(appPassword, { CANTORIA_READ_ONLY: '1' })
	const readOnlyNames = (await readOnly.client.listTools()).tools.map((tool) => tool.name)
	check('read-only: no writing tools', !readOnlyNames.includes('propose_change') && !readOnlyNames.includes('create_task')
		&& !readOnlyNames.includes('submit_transcription_report') && readOnlyNames.includes('search_catalog') && readOnlyNames.includes('get_review'),
		readOnlyNames.join(', '))
	await readOnly.client.close()
} catch (error) {
	check('no unexpected error', false, error instanceof Error ? error.stack ?? error.message : String(error))
} finally {
	// ---- 5. The app password is deleted ----
	const deleted = await request('DELETE', '/ocs/v2.php/core/apppassword', appPassword)
	check('app password deleted', deleted.status === 200, `HTTP ${deleted.status}`)
	const after = await request('GET', '/ocs/v2.php/apps/cantoria/api/v1/modules', appPassword)
	check('the deleted app password no longer signs in', after.status === 401, `HTTP ${after.status}`)
}

console.log(failures === 0 ? `\nall ${checks} checks passed` : `\n${failures} of ${checks} checks failed`)
process.exit(failures === 0 ? 0 : 1)
