/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * HTTP client of the API for agents (OCS, /ocs/v2.php/apps/notenarchiv/api/v1).
 *
 * - Signs in with Basic authentication (account and app password) and sends
 *   `OCS-APIRequest: true`, which Nextcloud requires instead of a CSRF token.
 * - Unwraps the OCS envelope and maps HTTP errors to ApiError with a short
 *   message an agent can act on.
 * - Never follows redirects (the credentials stay with the configured host).
 * - Every message passes redact(): the app password never appears in an
 *   error, even if a server or a network library repeats it.
 */
import type { Config } from './config.js'

export const API_PATH = '/ocs/v2.php/apps/notenarchiv/api/v1'
const TIMEOUT_MS = 30_000
const USER_AGENT = 'notenarchiv-mcp/1.0'

/** What went wrong, for the agent and for tests. */
export type ApiErrorKind = 'auth' | 'forbidden' | 'not_found' | 'invalid' | 'rate_limited' | 'server' | 'network' | 'read_only'

/** A refused or failed request; the message is safe to show. */
export class ApiError extends Error {
	override name = 'ApiError'

	constructor(readonly kind: ApiErrorKind, readonly status: number, message: string) {
		super(message)
	}
}

/** Values of a query: missing values are left out. */
export type Query = Record<string, string | number | boolean | null | undefined>

/** The parts of `fetch` the client uses (replaced in tests). */
export type Fetch = (url: string, init: RequestInit) => Promise<Response>

export class NotenarchivClient {
	private readonly authorization: string
	private readonly secrets: string[]

	/**
	 * @param config - URL, account and app password
	 * @param fetchImpl - fetch, replaced in tests
	 */
	constructor(private readonly config: Config, private readonly fetchImpl: Fetch = (url, init) => fetch(url, init)) {
		const token = Buffer.from(`${config.user}:${config.appPassword}`, 'utf8').toString('base64')
		this.authorization = `Basic ${token}`
		this.secrets = [config.appPassword, token, encodeURIComponent(config.appPassword)]
			.filter((secret, index, all) => secret.length >= 4 && all.indexOf(secret) === index)
			.sort((a, b) => b.length - a.length)
	}

	get readOnly(): boolean {
		return this.config.readOnly
	}

	/**
	 * Replaces every secret of the configuration in a text.
	 *
	 * @param text - any text that may leave the server
	 */
	redact(text: string): string {
		let result = text
		for (const secret of this.secrets) {
			result = result.split(secret).join('[redacted]')
		}
		return result
	}

	/**
	 * GET a path of the API.
	 *
	 * @param path - path below api/v1, e.g. /catalog
	 * @param query - query parameters
	 */
	async get(path: string, query: Query = {}): Promise<unknown> {
		return this.request('GET', path, query, undefined)
	}

	/**
	 * POST a JSON body to a path of the API. Refused in read-only mode
	 * before anything is sent.
	 *
	 * @param path - path below api/v1
	 * @param body - JSON body
	 */
	async post(path: string, body: Record<string, unknown>): Promise<unknown> {
		if (this.config.readOnly) {
			throw new ApiError('read_only', 0, 'The server runs read-only (NOTENARCHIV_READ_ONLY=1): nothing is written.')
		}
		return this.request('POST', path, {}, body)
	}

	private url(path: string, query: Query): string {
		const url = new URL(this.config.baseUrl + API_PATH + path)
		for (const [key, value] of Object.entries(query)) {
			if (value !== undefined && value !== null && value !== '') {
				url.searchParams.set(key, String(value))
			}
		}
		return url.toString()
	}

	private async request(method: 'GET' | 'POST', path: string, query: Query, body: Record<string, unknown> | undefined): Promise<unknown> {
		const headers: Record<string, string> = {
			Authorization: this.authorization,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			'User-Agent': USER_AGENT,
		}
		if (body !== undefined) {
			headers['Content-Type'] = 'application/json'
		}
		let response: Response
		try {
			response = await this.fetchImpl(this.url(path, query), {
				method,
				headers,
				body: body === undefined ? undefined : JSON.stringify(body),
				redirect: 'manual',
				signal: AbortSignal.timeout(TIMEOUT_MS),
			})
		} catch (error) {
			const reason = error instanceof Error ? (error.cause instanceof Error ? error.cause.message : error.message) : String(error)
			throw new ApiError('network', 0, this.redact(`Nextcloud is not reachable at ${new URL(this.config.baseUrl).host}: ${reason}`))
		}

		const text = await response.text().catch(() => '')
		const envelope = parseEnvelope(text)
		if (response.status >= 200 && response.status < 300) {
			if (envelope === null) {
				throw new ApiError('server', response.status, 'The answer of the server is not an OCS answer. Is NOTENARCHIV_URL the address of Nextcloud?')
			}
			return envelope.data
		}
		throw this.error(response.status, envelope?.message ?? '')
	}

	private error(status: number, serverMessage: string): ApiError {
		const message = this.redact(serverMessage.trim())
		switch (true) {
		case status >= 300 && status < 400:
			return new ApiError('server', status, 'The server answered with a redirect, which is not followed. Check NOTENARCHIV_URL (https, the address of Nextcloud).')
		case status === 401:
			return new ApiError('auth', status, 'Nextcloud refused the login. Check NOTENARCHIV_USER and NOTENARCHIV_APP_PASSWORD (an app password of the agent\'s own account).')
		case status === 403:
			return new ApiError('forbidden', status, `Not permitted for the roles of this account${message === '' ? '.' : ': ' + message}`)
		case status === 404:
			return new ApiError('not_found', status, message === ''
				? 'Not found. The interface for agents (module "agent_api") may be switched off, or the app is not installed.'
				: message + (message === 'This function is switched off.' ? ' Use the tool "modules" to see what is active.' : ''))
		case status === 429:
			return new ApiError('rate_limited', status, 'Too many requests for this account. Wait a few minutes and try again.')
		case status >= 400 && status < 500:
			return new ApiError('invalid', status, message === '' ? `The request was refused (HTTP ${status}).` : message)
		default:
			return new ApiError('server', status, `The server failed (HTTP ${status})${message === '' ? '.' : ': ' + message}`)
		}
	}
}

/**
 * The parts of an OCS v2 JSON answer, or null for anything else.
 *
 * @param text - body of the response
 */
function parseEnvelope(text: string): { data: unknown, message: string } | null {
	let json: unknown
	try {
		json = JSON.parse(text)
	} catch {
		return null
	}
	if (typeof json !== 'object' || json === null || !('ocs' in json)) {
		return null
	}
	const ocs = (json as { ocs: unknown }).ocs
	if (typeof ocs !== 'object' || ocs === null) {
		return null
	}
	const meta = 'meta' in ocs && typeof ocs.meta === 'object' && ocs.meta !== null ? ocs.meta : {}
	const message = 'message' in meta && typeof meta.message === 'string' ? meta.message : ''
	return { data: 'data' in ocs ? ocs.data : null, message }
}
