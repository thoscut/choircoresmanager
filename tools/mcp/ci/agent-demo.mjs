/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A run of an agent without a language model, against a Nextcloud with the
 * demo data (tools/ci/integration-demo.sh, step 7): gets an app password
 * for the agent's account, starts the MCP server over stdio like a real
 * client and calls its tools, then checks what the server must refuse.
 *
 * Environment:
 *   NOTENARCHIV_URL       Nextcloud, e.g. http://127.0.0.1:8080
 *   AGENT_USER            the agent's account (role "contributor")
 *   AGENT_LOGIN_PASSWORD  its login password, used once to get an app password
 *   NC_ROOT               the Nextcloud directory (for occ)
 *   PHP                   PHP command (default: php)
 *   SHELFMARK             a copy of the demo data (default 1-1)
 *
 * Neither password is printed. The app password is deleted at the end,
 * also after a failure. Exit code 0 only when every check passed.
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { getDefaultEnvironment, StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js'
import { execFileSync } from 'node:child_process'
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
const url = env('NOTENARCHIV_URL').replace(/\/+$/, '')
const user = env('AGENT_USER')
const loginPassword = env('AGENT_LOGIN_PASSWORD')
const ncRoot = env('NC_ROOT')
const php = env('PHP', 'php')
const shelfmark = env('SHELFMARK', '1-1')
const server = join(dirname(fileURLToPath(import.meta.url)), '..', 'dist', 'src', 'index.js')

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
		env: { ...getDefaultEnvironment(), NOTENARCHIV_URL: url, NOTENARCHIV_USER: user, NOTENARCHIV_APP_PASSWORD: appPassword, ...extra },
		stderr: 'pipe',
	})
	let stderr = ''
	transport.stderr?.on('data', (chunk) => {
		stderr += chunk
	})
	const client = new Client({ name: 'notenarchiv-ci-agent', version: '1.0.0' })
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
		'list_concerts', 'get_concert', 'statistics', 'modules'].every((name) => names.includes(name)), names.join(', '))
	check('no tool decides', !names.some((name) => /confirm|valid|licen|delete|restore|backup|setting/.test(name)))
	check('writing tools are marked', tools.filter((tool) => tool.annotations?.readOnlyHint === false).map((tool) => tool.name).sort().join(',')
		=== 'answer_task,create_task,propose_change')

	const status = data(await client.callTool({ name: 'modules', arguments: {} }))
	check('modules: role contributor, interface on, may propose', status?.roles?.includes('contributor') && status?.modules?.agent_api === true
		&& status?.permissions?.propose === true && status?.permissions?.view_preservation_copies === false, JSON.stringify(status?.roles))

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

	// ---- 3. What the server refuses ----
	const confirm = await request('POST', `/index.php/apps/notenarchiv/api/versions/${copy?.version_id ?? 0}/confirm`, appPassword,
		{ status: 'public_domain', reason: 'agent', evidence: 'agent' })
	check('confirming rights with the app password is refused (403)', confirm.status === 403, `HTTP ${confirm.status}`)
	const noRoute = await request('POST', `/ocs/v2.php/apps/notenarchiv/api/v1/versions/${copy?.version_id ?? 0}/confirm`, appPassword, {})
	check('api/v1 has no route to confirm (404/405)', noRoute.status === 404 || noRoute.status === 405, `HTTP ${noRoute.status}`)

	occRun('notenarchiv:modules', 'disable', 'agent_api')
	try {
		const off = await request('GET', '/ocs/v2.php/apps/notenarchiv/api/v1/modules', appPassword)
		check('without agent_api the API answers 404 with OCS meta', off.status === 404 && off.json?.ocs?.meta?.statuscode === 404
			&& off.json?.ocs?.meta?.message === 'This function is switched off.', `HTTP ${off.status} ${JSON.stringify(off.json?.ocs?.meta)}`)
		const offTool = await client.callTool({ name: 'search_catalog', arguments: {} })
		check('and the tool reports it', offTool.isError === true && /switched off/.test(data(offTool)), String(data(offTool)))
	} finally {
		occRun('notenarchiv:modules', 'enable', 'agent_api')
	}
	check('switched on again', !(await client.callTool({ name: 'list_due', arguments: {} })).isError)

	await client.close()
	check('the server log holds no secret and no account', !secrets.some((secret) => stderr().includes(secret)) && !stderr().includes(user),
		stderr().trim())

	// ---- 4. Read-only mode ----
	const readOnly = await connect(appPassword, { NOTENARCHIV_READ_ONLY: '1' })
	const readOnlyNames = (await readOnly.client.listTools()).tools.map((tool) => tool.name)
	check('read-only: no writing tools', !readOnlyNames.includes('propose_change') && !readOnlyNames.includes('create_task')
		&& readOnlyNames.includes('search_catalog'), readOnlyNames.join(', '))
	await readOnly.client.close()
} catch (error) {
	check('no unexpected error', false, error instanceof Error ? error.stack ?? error.message : String(error))
} finally {
	// ---- 5. The app password is deleted ----
	const deleted = await request('DELETE', '/ocs/v2.php/core/apppassword', appPassword)
	check('app password deleted', deleted.status === 200, `HTTP ${deleted.status}`)
	const after = await request('GET', '/ocs/v2.php/apps/notenarchiv/api/v1/modules', appPassword)
	check('the deleted app password no longer signs in', after.status === 401, `HTTP ${after.status}`)
}

console.log(failures === 0 ? `\nall ${checks} checks passed` : `\n${failures} of ${checks} checks failed`)
process.exit(failures === 0 ? 0 : 1)
