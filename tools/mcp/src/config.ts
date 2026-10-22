/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Configuration from the environment. Error messages name the variable,
 * never its value: the app password must not reach a log or an agent.
 */

/** Where the server finds Nextcloud and how it signs in. */
export interface Config {
	/** Base URL of Nextcloud without a trailing slash, e.g. https://cloud.example.org or https://example.org/nextcloud */
	baseUrl: string
	/** Account of the agent (login name) */
	user: string
	/** App password of that account */
	appPassword: string
	/** Only the reading tools; writing requests are refused before they leave */
	readOnly: boolean
}

/** A configuration that cannot work; the message contains no secret. */
export class ConfigError extends Error {
	override name = 'ConfigError'
}

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

/**
 * Reads NOTENARCHIV_URL, NOTENARCHIV_USER, NOTENARCHIV_APP_PASSWORD and
 * NOTENARCHIV_READ_ONLY.
 *
 * @param env - the process environment
 */
export function readConfig(env: NodeJS.ProcessEnv): Config {
	const rawUrl = required(env, 'NOTENARCHIV_URL')
	let url: URL
	try {
		url = new URL(rawUrl)
	} catch {
		throw new ConfigError('NOTENARCHIV_URL is not a URL, e.g. https://cloud.example.org')
	}
	if (url.username !== '' || url.password !== '') {
		throw new ConfigError('NOTENARCHIV_URL must not contain a login; use NOTENARCHIV_USER and NOTENARCHIV_APP_PASSWORD.')
	}
	if (url.protocol !== 'https:' && !(url.protocol === 'http:' && LOCAL_HOSTS.has(url.hostname))) {
		throw new ConfigError('NOTENARCHIV_URL must use https (plain http only for localhost).')
	}
	if (url.search !== '' || url.hash !== '') {
		throw new ConfigError('NOTENARCHIV_URL must not contain a query or a fragment.')
	}
	const user = required(env, 'NOTENARCHIV_USER')
	if (/[:\s]/.test(user)) {
		throw new ConfigError('NOTENARCHIV_USER must not contain a colon or white space.')
	}
	return {
		baseUrl: (url.origin + url.pathname).replace(/\/+$/, ''),
		user,
		appPassword: required(env, 'NOTENARCHIV_APP_PASSWORD'),
		readOnly: flag(env, 'NOTENARCHIV_READ_ONLY'),
	}
}

/**
 * A variable that must be set and not blank.
 *
 * @param env - the environment
 * @param name - name of the variable
 */
function required(env: NodeJS.ProcessEnv, name: string): string {
	const value = env[name]
	if (value === undefined || value.trim() === '') {
		throw new ConfigError(`${name} is not set.`)
	}
	return value.trim()
}

/**
 * A yes/no variable: 1, true, yes or on; unset, empty, 0, false, no or off.
 *
 * @param env - the environment
 * @param name - name of the variable
 */
function flag(env: NodeJS.ProcessEnv, name: string): boolean {
	const value = (env[name] ?? '').trim().toLowerCase()
	if (['1', 'true', 'yes', 'on'].includes(value)) {
		return true
	}
	if (['', '0', 'false', 'no', 'off'].includes(value)) {
		return false
	}
	throw new ConfigError(`${name} must be 1 or 0.`)
}
