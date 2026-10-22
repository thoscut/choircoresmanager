/**
 * Durations of programme items: stored in seconds, shown and entered as
 * "m:ss" (or "h:mm:ss" for long works). Pure functions.
 */

/**
 * "3:05" for 185 seconds; "1:02:03" from one hour on; '' for no value.
 *
 * @param seconds - duration in seconds
 */
export function formatDuration(seconds: number | null | undefined): string {
	if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) {
		return ''
	}
	const total = Math.round(seconds)
	const hours = Math.floor(total / 3600)
	const minutes = Math.floor((total % 3600) / 60)
	const rest = total % 60
	const ss = String(rest).padStart(2, '0')
	if (hours > 0) {
		return `${hours}:${String(minutes).padStart(2, '0')}:${ss}`
	}
	return `${minutes}:${ss}`
}

/**
 * Seconds from "m:ss" or "h:mm:ss" (also with "." as separator). Empty input
 * gives null; invalid input gives undefined.
 *
 * @param input - what the user typed
 */
export function parseDuration(input: string): number | null | undefined {
	const value = input.trim()
	if (value === '') {
		return null
	}
	const match = value.match(/^(?:(\d{1,2})[:.])?(\d{1,3})[:.]([0-5]\d)$/)
	if (!match) {
		return undefined
	}
	const hours = match[1] === undefined ? 0 : Number.parseInt(match[1], 10)
	const minutes = Number.parseInt(match[2], 10)
	if (hours > 0 && minutes > 59) {
		return undefined
	}
	return hours * 3600 + minutes * 60 + Number.parseInt(match[3], 10)
}
