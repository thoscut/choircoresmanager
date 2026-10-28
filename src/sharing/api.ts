/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The calls of the page for choir members (milestone 0.7, see docs/api.md):
 * the score of a link (its measures, cut by the server, or the whole
 * piece), the address of its scan and the permission for a practice file.
 * Kept apart from api.ts, so that the small bundle of this page does not
 * carry the catalog.
 */
import type { MemberLink, MemberPracticeAudioInput } from '../types.ts'

import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { errorMessage } from '../api.ts'

/**
 * The links shared with the account, as on the page (milestone 0.15):
 * the check of the pieces kept on the device. Throws without a network.
 */
export async function fetchMemberLinks(): Promise<MemberLink[]> {
	const { data } = await axios.get<{ links?: MemberLink[] }>(generateUrl('/apps/notenarchiv/api/member/links'), { timeout: 8000 })
	return Array.isArray(data?.links) ? data.links : []
}

/**
 * URL of an API endpoint of a member link.
 *
 * @param token - link token
 * @param action - 'score', 'scan' or 'practice-audio'
 */
function memberUrl(token: string, action: string): string {
	return generateUrl('/apps/notenarchiv/api/member/links/{token}/{action}', { token, action })
}

/**
 * Address of the scan a member link offers: opened by the browser itself
 * (its PDF viewer, in a tab of its own); the server checks the role, the
 * link and the rights.
 *
 * @param token - link token
 */
export function memberScanUrl(token: string): string {
	return memberUrl(token, 'scan')
}

/**
 * The server's `{ error }` text of a failed request whose body arrived as
 * text, otherwise the usual message.
 *
 * @param error - whatever was thrown
 */
function textErrorMessage(error: unknown): string {
	const body = isAxiosError(error) ? error.response?.data : undefined
	if (typeof body === 'string' && body.trim().startsWith('{')) {
		try {
			const parsed = JSON.parse(body) as { error?: unknown }
			if (typeof parsed.error === 'string' && parsed.error !== '') {
				return parsed.error
			}
		} catch {
			// not JSON: the general message below
		}
	}
	return errorMessage(error)
}

/**
 * The MusicXML of a link for choir members: only its measures, or the
 * whole piece. The server checks the role, the link and the rights.
 *
 * @param token - link token
 */
export async function fetchMemberScore(token: string): Promise<string> {
	try {
		const { data } = await axios.get<string>(memberUrl(token, 'score'), { responseType: 'text', transformResponse: [(raw) => raw] })
		return data
	} catch (error) {
		throw new Error(textErrorMessage(error))
	}
}

/**
 * Ask the server before making a practice file of a member link: it checks
 * the rights and records the export in the audit log.
 *
 * @param token - link token
 * @param input - voice, mode, format, tempo, repeats
 */
export async function authorizeMemberPracticeAudio(token: string, input: MemberPracticeAudioInput): Promise<void> {
	try {
		await axios.post(memberUrl(token, 'practice-audio'), { export: input })
	} catch (error) {
		throw new Error(errorMessage(error))
	}
}
