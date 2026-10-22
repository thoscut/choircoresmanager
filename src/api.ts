/**
 * All calls to the server API (see docs/api.md) in one place.
 */
import type {
	AccessionInput,
	AccessionProposal,
	AccessionResult,
	CalendarAddResult,
	CalendarUpdate,
	ChecksumStatus,
	EndingLicense,
	ChoirEvent,
	Contribution,
	CountInput,
	Detail,
	DueResult,
	Entry,
	EventDetail,
	EventInput,
	EventItem,
	EventKind,
	ExcerptLink,
	FieldMap,
	FieldValue,
	FileSyncReport,
	ImportReport,
	Inventory,
	Level,
	License,
	LicenseInput,
	LinkInput,
	Merge,
	MergeCandidateGroup,
	Meta,
	PerformanceImportReport,
	Person,
	PersonDetail,
	PersonImportReport,
	Proposal,
	ProtectionReviewRow,
	ReleaseRow,
	RepertoireThresholds,
	ResolvedLink,
	Settings,
	SettingsUpdate,
	ShelfLabel,
	Task,
	TaskInput,
	UserCalendar,
} from './types.ts'

import axios, { isAxiosError } from '@nextcloud/axios'
import { t } from './l10n.ts'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { davUrl } from './calendar.ts'
import { LEVELS } from './types.ts'

const APP = 'notenarchiv'

/**
 * URL of an API endpoint.
 *
 * @param path - path below /api, e.g. `/entries/{id}`
 * @param params - placeholder values for the path
 */
export function apiUrl(path: string, params?: Record<string, string | number>): string {
	return generateUrl(`/apps/${APP}/api${path}`, params)
}

/**
 * A user-facing message for a failed request: the server's `{ error }` text
 * if there is one, otherwise a generic hint with the HTTP status.
 *
 * @param error - whatever was thrown
 */
export function errorMessage(error: unknown): string {
	if (isAxiosError(error)) {
		const data = error.response?.data as { error?: unknown } | undefined
		if (data && typeof data.error === 'string' && data.error !== '') {
			return data.error
		}
		if (error.response?.status === 429) {
			// rate limit of Nextcloud: no `{ error }` in the response
			return t('notenarchiv', 'Too many requests. Please wait a moment and try again.')
		}
		if (error.response) {
			return t('notenarchiv', 'The server reported an error (HTTP {status}).', { status: error.response.status })
		}
		return t('notenarchiv', 'The server cannot be reached. Please check your connection.')
	}
	if (error instanceof Error && error.message) {
		return error.message
	}
	return t('notenarchiv', 'An unexpected error occurred.')
}

/**
 * PHP serializes empty associative arrays as `[]`. Bring the field
 * directory into a predictable shape.
 *
 * @param meta - meta as delivered by the server
 */
export function normalizeMeta(meta: Meta): Meta {
	const fields = {} as Record<Level, FieldMap>
	for (const level of LEVELS) {
		fields[level] = normalizeFieldMap(meta.fields?.[level])
	}
	return {
		uid: meta.uid ?? '',
		admin: meta.admin === true,
		roles: Array.isArray(meta.roles) ? meta.roles : [],
		roleLabels: meta.roleLabels && !Array.isArray(meta.roleLabels) ? meta.roleLabels : {},
		fields,
		licenseUses: meta.licenseUses && !Array.isArray(meta.licenseUses) ? meta.licenseUses : {},
		personFields: normalizeFieldMap(meta.personFields),
		contributionRoles: meta.contributionRoles && !Array.isArray(meta.contributionRoles) ? meta.contributionRoles : {},
		archive: meta.archive ?? { id: 0, path: '', user: '' },
	}
}

/**
 * One field directory in a predictable shape (options as map, write list).
 *
 * @param raw - field directory as delivered by the server
 */
function normalizeFieldMap(raw: FieldMap | undefined): FieldMap {
	const map: FieldMap = {}
	if (raw && !Array.isArray(raw)) {
		for (const [key, info] of Object.entries(raw)) {
			let options = info.options
			if (Array.isArray(options)) {
				options = Object.fromEntries((options as string[]).map((value) => [value, value]))
			}
			map[key] = {
				...info,
				write: Array.isArray(info.write) ? info.write : [],
				options,
			}
		}
	}
	return map
}

/**
 * An array from the server, or [] if the value is missing or no array.
 *
 * @param value - value from the server
 */
function list<T>(value: unknown): T[] {
	return Array.isArray(value) ? value as T[] : []
}

export async function fetchMeta(): Promise<Meta> {
	const { data } = await axios.get<Meta>(apiUrl('/meta'))
	return normalizeMeta(data)
}

export async function fetchEntries(): Promise<Entry[]> {
	const { data } = await axios.get<Entry[]>(apiUrl('/entries'))
	return Array.isArray(data) ? data : []
}

export async function fetchEntry(id: number): Promise<Detail> {
	const { data } = await axios.get<Detail>(apiUrl('/entries/{id}', { id }))
	const releases = data.releases && typeof data.releases === 'object' && !Array.isArray(data.releases)
		? data.releases
		: { portal: null, onPortal: false, printStation: null }
	return {
		...data,
		files: list(data.files),
		siblings: list(data.siblings),
		audit: list(data.audit),
		score: data.score && typeof data.score === 'object' && typeof data.score.fileId === 'number' ? data.score : null,
		licenses: list(data.licenses),
		releases,
		proposals: list(data.proposals),
		contributions: list(data.contributions),
		protection: data.protection && typeof data.protection === 'object' && !Array.isArray(data.protection) ? data.protection : null,
		merges: list(data.merges),
		tasks: list(data.tasks),
		links: list(data.links),
	}
}

/**
 * Raw content of a linked file (e.g. the MusicXML of the score view). The
 * server checks the rights policy and the user's access to the file.
 *
 * @param id - id from `files[]` (not the Nextcloud file id)
 */
export async function fetchFileContent(id: number): Promise<ArrayBuffer> {
	try {
		const { data } = await axios.get<ArrayBuffer>(apiUrl('/files/{id}/content', { id }), { responseType: 'arraybuffer' })
		return data
	} catch (error) {
		// With responseType arraybuffer the `{ error }` body arrives as bytes
		const body = isAxiosError(error) ? error.response?.data : undefined
		if (body instanceof ArrayBuffer) {
			try {
				const parsed = JSON.parse(new TextDecoder().decode(body)) as { error?: unknown }
				if (typeof parsed.error === 'string' && parsed.error !== '') {
					throw new Error(parsed.error)
				}
			} catch (decoded) {
				if (decoded instanceof Error && !(decoded instanceof SyntaxError)) {
					throw decoded
				}
			}
		}
		throw new Error(errorMessage(error))
	}
}

/**
 * Change fields of a work, version or copy. Only send changed fields.
 *
 * @param level - work, version or copy
 * @param id - id of the object on that level
 * @param fields - field key => new value
 * @param reason - optional reason for the audit log
 */
export async function updateFields(level: Level, id: number, fields: Record<string, FieldValue>, reason: string): Promise<void> {
	await axios.patch(apiUrl('/{level}/{id}', { level, id }), {
		fields,
		reason: reason.trim() === '' ? null : reason.trim(),
	})
}

export async function runImport(fileId: number, dryRun: boolean): Promise<ImportReport> {
	const { data } = await axios.post<ImportReport>(apiUrl('/import'), { fileId, dryRun })
	return data
}

export async function runFileSync(): Promise<FileSyncReport> {
	const { data } = await axios.post<FileSyncReport>(apiUrl('/file-sync'))
	return data
}

/**
 * Download URL of the CSV export.
 *
 * @param extended - false: legacy catalog columns, true: all fields
 */
export function exportUrl(extended: boolean): string {
	return apiUrl('/export') + '?extended=' + (extended ? '1' : '0')
}

export async function storeExport(): Promise<string> {
	const { data } = await axios.post<{ path: string }>(apiUrl('/export/store'))
	return data.path
}

export async function fetchSettings(): Promise<Settings> {
	const { data } = await axios.get<Settings>(apiUrl('/settings'))
	return data
}

export async function saveSettings(update: SettingsUpdate): Promise<Settings> {
	const { data } = await axios.put<Settings>(apiUrl('/settings'), update)
	return data
}

// ---- milestone 0.2: rights, releases, valid version, proposals -------------

export async function confirmRights(versionId: number, status: string, reason: string, evidence: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/confirm', { id: versionId }), { status, reason, evidence })
}

export async function setRightsOpen(versionId: number, reason: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/set-open', { id: versionId }), { reason })
}

/**
 * Record that the version is in the singers' portal.
 *
 * @param versionId - version id
 * @param since - date (YYYY-MM-DD)
 */
export async function addToPortal(versionId: number, since: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/portal', { id: versionId }), { since })
}

export async function removeFromPortal(versionId: number, reason: string): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/portal-remove', { id: versionId }), { reason })
}

export async function setValidVersion(versionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/valid', { id: versionId }), { reason })
}

export async function clearValidVersion(versionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/versions/{id}/valid-clear', { id: versionId }), { reason })
}

export async function createLicense(versionId: number, license: LicenseInput): Promise<License> {
	const { data } = await axios.post<License>(apiUrl('/versions/{id}/licenses', { id: versionId }), { license })
	return data
}

export async function updateLicense(licenseId: number, license: Partial<LicenseInput>): Promise<License> {
	const { data } = await axios.patch<License>(apiUrl('/licenses/{id}', { id: licenseId }), { license })
	return data
}

export async function fetchProposals(): Promise<Proposal[]> {
	const { data } = await axios.get<Proposal[]>(apiUrl('/proposals'))
	return list(data)
}

export async function acceptProposal(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/proposals/{id}/accept', { id }), { reason })
}

export async function rejectProposal(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/proposals/{id}/reject', { id }), { reason })
}

/**
 * Target/actual list of the singers' portal or the print station.
 *
 * @param target - which list
 * @param all - also blocked versions and those where another version is valid
 */
export async function fetchReleaseList(target: 'portal' | 'print-station', all: boolean): Promise<ReleaseRow[]> {
	const { data } = await axios.get<ReleaseRow[]>(apiUrl('/lists/{target}', { target }) + '?all=' + (all ? '1' : '0'))
	return list(data)
}

/** Store a complete backup (CSV and ODS) in the archive folder. */
export async function storeBackup(): Promise<string[]> {
	const { data } = await axios.post<{ paths?: string[] }>(apiUrl('/backup'))
	return list(data.paths)
}

// ---- milestone 0.3: tasks, links, start page --------------------------------

export async function fetchDue(): Promise<DueResult> {
	const { data } = await axios.get<DueResult>(apiUrl('/due'))
	return { cards: list(data.cards), tasks: list(data.tasks) }
}

/**
 * Tasks of all copies.
 *
 * @param closed - also closed tasks (applied, discarded)
 */
export async function fetchTasks(closed: boolean): Promise<Task[]> {
	const { data } = await axios.get<Task[]>(apiUrl('/tasks') + '?closed=' + (closed ? '1' : '0'))
	return list(data)
}

export async function createTask(copyId: number, task: TaskInput): Promise<Task> {
	const { data } = await axios.post<Task>(apiUrl('/copies/{id}/tasks', { id: copyId }), { task })
	return data
}

export async function answerTask(id: number, answer: string): Promise<void> {
	await axios.post(apiUrl('/tasks/{id}/answer', { id }), { answer })
}

export async function closeTask(id: number, status: 'applied' | 'discarded', note: string | null): Promise<void> {
	await axios.post(apiUrl('/tasks/{id}/close', { id }), { status, note })
}

export async function createLink(copyId: number, link: LinkInput): Promise<ExcerptLink> {
	const { data } = await axios.post<ExcerptLink>(apiUrl('/copies/{id}/links', { id: copyId }), { link })
	return data
}

export async function fetchLinks(): Promise<ExcerptLink[]> {
	const { data } = await axios.get<ExcerptLink[]>(apiUrl('/links'))
	return list(data)
}

export async function resolveLink(token: string): Promise<ResolvedLink> {
	const { data } = await axios.get<ResolvedLink>(apiUrl('/links/{token}', { token }))
	return data
}

export async function revokeLink(id: number): Promise<void> {
	await axios.post(apiUrl('/links/{id}/revoke', { id }))
}

/**
 * Absolute URL that opens an excerpt link in the app.
 *
 * @param token - link token
 */
export function linkUrl(token: string): string {
	return window.location.origin + generateUrl('/apps/{app}/', { app: APP }) + '?link=' + encodeURIComponent(token)
}

// ---- milestone 0.4: concerts, song lists, repertoire ------------------------

export async function fetchEvents(kind: EventKind): Promise<ChoirEvent[]> {
	const { data } = await axios.get<ChoirEvent[]>(apiUrl('/events') + '?kind=' + kind)
	return list(data)
}

export async function fetchEvent(id: number): Promise<EventDetail> {
	const { data } = await axios.get<EventDetail>(apiUrl('/events/{id}', { id }))
	return { ...data, items: list(data.items) }
}

export async function createEvent(kind: EventKind, event: EventInput): Promise<ChoirEvent> {
	const { data } = await axios.post<ChoirEvent>(apiUrl('/events'), { kind, event })
	return data
}

export async function updateEvent(id: number, event: EventInput): Promise<ChoirEvent> {
	const { data } = await axios.patch<ChoirEvent>(apiUrl('/events/{id}', { id }), { event })
	return data
}

export async function addEventItem(eventId: number, copyId: number): Promise<EventItem> {
	const { data } = await axios.post<EventItem>(apiUrl('/events/{id}/items', { id: eventId }), { copyId })
	return data
}

export async function updateEventItem(id: number, item: Partial<Pick<EventItem, 'duration' | 'note' | 'encore' | 'struck'>>): Promise<EventItem> {
	const { data } = await axios.patch<EventItem>(apiUrl('/event-items/{id}', { id }), { item })
	return data
}

export async function removeEventItem(id: number): Promise<void> {
	await axios.delete(apiUrl('/event-items/{id}', { id }))
}

export async function reorderEventItems(eventId: number, order: number[]): Promise<void> {
	await axios.post(apiUrl('/events/{id}/order', { id: eventId }), { order })
}

export async function fixEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/fix', { id }))
}

/**
 * Confirm a concert as sung.
 *
 * @param id - event id
 * @param struck - ids of the items that were not sung
 */
export async function confirmSung(id: number, struck: number[]): Promise<void> {
	await axios.post(apiUrl('/events/{id}/sung', { id }), { struck })
}

export async function fetchAmbiguousItems(): Promise<EventItem[]> {
	const { data } = await axios.get<EventItem[]>(apiUrl('/event-items/ambiguous'))
	return list(data)
}

/**
 * Resolve an unclear programme line.
 *
 * @param id - event item id
 * @param copyId - the chosen copy, or null: not in the archive
 */
export async function resolveEventItem(id: number, copyId: number | null): Promise<void> {
	await axios.post(apiUrl('/event-items/{id}/resolve', { id }), { copyId })
}

/**
 * Download URL of the song order (CSV) of an event.
 *
 * @param id - event id
 */
export function songOrderUrl(id: number): string {
	return apiUrl('/events/{id}/song-order', { id })
}

/**
 * Cancel a planned concert.
 *
 * @param id - event id
 */
export async function cancelEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/cancel', { id }))
}

/**
 * Take back the cancellation of a concert (it becomes a draft again).
 *
 * @param id - event id
 */
export async function reopenEvent(id: number): Promise<void> {
	await axios.post(apiUrl('/events/{id}/reopen', { id }))
}

// ---- concerts in the calendar ------------------------------------------------

/**
 * Download URL of the calendar file (iCalendar) of a concert, or of all
 * upcoming concerts.
 *
 * @param id - event id, or null for all upcoming concerts
 * @param deadlines - add the preparation deadlines as all-day entries
 */
export function calendarFileUrl(id: number | null, deadlines: boolean): string {
	const url = id === null ? apiUrl('/events/upcoming/ics') : apiUrl('/events/{id}/ics', { id })
	return deadlines ? url + '?deadlines=1' : url
}

/** The account's calendars that can take new entries. */
export async function fetchCalendars(): Promise<UserCalendar[]> {
	const { data } = await axios.get<UserCalendar[]>(apiUrl('/calendars'))
	return list(data)
}

/**
 * Add a concert, or all upcoming concerts, to one of the account's
 * calendars. Entries that are already in a calendar come back as updates
 * for writeCalendarObject().
 *
 * @param id - event id, or null for all upcoming concerts
 * @param calendar - URI of the calendar
 * @param deadlines - add the preparation deadlines as all-day entries
 */
export async function addToCalendar(id: number | null, calendar: string, deadlines: boolean): Promise<CalendarAddResult> {
	const url = id === null ? apiUrl('/events/upcoming/calendar') : apiUrl('/events/{id}/calendar', { id })
	const { data } = await axios.post<CalendarAddResult>(url, { calendar, deadlines })
	return { ...data, updates: list<CalendarUpdate>(data.updates) }
}

/**
 * Update an entry in the account's own calendar (CalDAV PUT, with the
 * session of the logged-in account). Nextcloud's calendar interface for
 * apps can only create entries, so the server leaves updates to this call.
 *
 * @param path - CalDAV path below remote.php/dav, as sent by the server
 * @param ics - new content
 */
export async function writeCalendarObject(path: string, ics: string): Promise<void> {
	await axios.put(davUrl(generateRemoteUrl('dav'), path), ics, {
		headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
	})
}

export async function fetchRepertoireThresholds(): Promise<RepertoireThresholds> {
	const { data } = await axios.get<{ thresholds: RepertoireThresholds }>(apiUrl('/repertoire'))
	return data.thresholds
}

export async function saveRepertoireThresholds(thresholds: RepertoireThresholds): Promise<RepertoireThresholds> {
	const { data } = await axios.put<RepertoireThresholds>(apiUrl('/repertoire/thresholds'), thresholds)
	return data
}

/**
 * Import performances and song lists (.xlsx or .csv). Always run a dry run first.
 *
 * @param fileId - Nextcloud file id
 * @param dryRun - only check and report
 * @param ownChoir - name of the own choir in the file, if the detection is wrong
 */
export async function importPerformances(fileId: number, dryRun: boolean, ownChoir: string | null): Promise<PerformanceImportReport> {
	const { data } = await axios.post<PerformanceImportReport>(apiUrl('/performances/import'), { fileId, dryRun, ownChoir })
	return { ...data, unknownShelfmarks: list(data.unknownShelfmarks), sheets: list(data.sheets) }
}

// ---- milestone 0.5: maintaining the catalog ---------------------------------

/**
 * Proposal for the next shelf mark.
 *
 * @param binder - wanted binder, or null for the default
 */
export async function fetchNextShelfmark(binder: number | null): Promise<AccessionProposal> {
	const { data } = await axios.get<AccessionProposal>(apiUrl('/accession/next') + (binder === null ? '' : '?binder=' + binder))
	return { ...data, binders: list(data.binders), openBinders: list(data.openBinders) }
}

export async function saveOpenBinders(binders: number[]): Promise<number[]> {
	const { data } = await axios.put<{ openBinders: number[] }>(apiUrl('/accession/open-binders'), { binders })
	return list(data.openBinders)
}

/**
 * Create work, version and copy of a new accession.
 *
 * @param copy - fields of the accession
 * @param fileId - Nextcloud id of a file in the archive folder to link, or null
 */
export async function createAccession(copy: AccessionInput, fileId: number | null): Promise<AccessionResult> {
	const { data } = await axios.post<AccessionResult>(apiUrl('/accession'), { copy, fileId })
	return data
}

export async function fetchLabels(copyIds: number[]): Promise<ShelfLabel[]> {
	const { data } = await axios.get<ShelfLabel[]>(apiUrl('/labels') + '?ids=' + copyIds.join(','))
	return list(data)
}

/**
 * Link a file of the archive folder to a copy by hand.
 *
 * @param copyId - copy id
 * @param fileId - Nextcloud file id
 */
export async function linkFile(copyId: number, fileId: number): Promise<void> {
	await axios.post(apiUrl('/copies/{id}/files', { id: copyId }), { fileId })
}

/**
 * Remove a manual file link; afterwards the file name decides again.
 *
 * @param fileRowId - id from files[] (not the Nextcloud file id)
 */
export async function unlinkFile(fileRowId: number): Promise<void> {
	await axios.delete(apiUrl('/files/{id}/link', { id: fileRowId }))
}

export async function fetchPersons(query: string): Promise<Person[]> {
	const { data } = await axios.get<Person[]>(apiUrl('/persons') + (query.trim() === '' ? '' : '?q=' + encodeURIComponent(query.trim())))
	return list(data)
}

export async function fetchPerson(id: number): Promise<PersonDetail> {
	const { data } = await axios.get<PersonDetail>(apiUrl('/persons/{id}', { id }))
	return { ...data, contributions: list(data.contributions) }
}

export async function createPerson(person: Record<string, FieldValue>): Promise<Person> {
	const { data } = await axios.post<Person>(apiUrl('/persons'), { person })
	return data
}

export async function updatePerson(id: number, person: Record<string, FieldValue>, reason: string | null): Promise<Person> {
	const { data } = await axios.patch<Person>(apiUrl('/persons/{id}', { id }), { person, reason })
	return data
}

export async function markPersonChecked(id: number): Promise<Person> {
	const { data } = await axios.post<Person>(apiUrl('/persons/{id}/checked', { id }))
	return data
}

export async function deletePerson(id: number): Promise<void> {
	await axios.delete(apiUrl('/persons/{id}', { id }))
}

export async function addContribution(versionId: number, personId: number, role: string, textForComposition: boolean): Promise<Contribution> {
	const { data } = await axios.post<Contribution>(apiUrl('/versions/{id}/contributions', { id: versionId }), { personId, role, textForComposition })
	return data
}

export async function updateContribution(id: number, contribution: { role?: string, text_for_composition?: boolean }): Promise<Contribution> {
	const { data } = await axios.patch<Contribution>(apiUrl('/contributions/{id}', { id }), { contribution })
	return data
}

export async function removeContribution(id: number): Promise<void> {
	await axios.delete(apiUrl('/contributions/{id}', { id }))
}

export async function fetchProtectionReview(): Promise<ProtectionReviewRow[]> {
	const { data } = await axios.get<ProtectionReviewRow[]>(apiUrl('/protection/review'))
	return list(data)
}

export async function importPersons(fileId: number, dryRun: boolean): Promise<PersonImportReport> {
	const { data } = await axios.post<PersonImportReport>(apiUrl('/persons/import'), { fileId, dryRun })
	return {
		...data,
		renamed: list(data.renamed),
		unknownShelfmarks: list(data.unknownShelfmarks),
		protectionDiffers: list(data.protectionDiffers),
		warnings: list(data.warnings),
	}
}

export async function fetchMergeCandidates(): Promise<MergeCandidateGroup[]> {
	const { data } = await axios.get<MergeCandidateGroup[]>(apiUrl('/merges/candidates'))
	return list(data)
}

/**
 * Merges, newest first.
 *
 * @param all - also undone merges
 */
export async function fetchMerges(all: boolean): Promise<Merge[]> {
	const { data } = await axios.get<Merge[]>(apiUrl('/merges') + '?all=' + (all ? '1' : '0'))
	return list(data)
}

export async function mergeWorks(sourceWorkId: number, targetWorkId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/works'), { sourceWorkId, targetWorkId, reason })
}

export async function moveCopy(copyId: number, targetVersionId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/copies'), { copyId, targetVersionId, reason })
}

export async function undoMerge(id: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/merges/{id}/undo', { id }), { reason })
}

/**
 * Inventory list of a binder.
 *
 * @param binder - binder number, or null for the lowest
 * @param all - also copies that are not active
 */
export async function fetchInventory(binder: number | null, all: boolean): Promise<Inventory> {
	const params = new URLSearchParams({ all: all ? '1' : '0' })
	if (binder !== null) {
		params.set('binder', String(binder))
	}
	const { data } = await axios.get<Inventory>(apiUrl('/inventory') + '?' + params.toString())
	return { ...data, binders: list(data.binders), rows: list(data.rows) }
}

export async function recordCounts(counts: CountInput[], date: string | null): Promise<number> {
	const { data } = await axios.post<{ recorded: number }>(apiUrl('/inventory/count'), { counts, date })
	return data.recorded
}

export async function fetchChecksums(): Promise<ChecksumStatus> {
	const { data } = await axios.get<ChecksumStatus>(apiUrl('/checksums'))
	return { ...data, mismatches: list(data.mismatches) }
}

export async function acceptChecksum(fileRowId: number, reason: string | null): Promise<void> {
	await axios.post(apiUrl('/files/{id}/checksum-accept', { id: fileRowId }), { reason })
}

/**
 * Licenses that end within the given days or have ended, the soonest first.
 *
 * @param days - 1 … 366
 */
export async function fetchEndingLicenses(days: number): Promise<EndingLicense[]> {
	const { data } = await axios.get<EndingLicense[]>(apiUrl('/licenses/ending') + '?days=' + days)
	return list<EndingLicense>(data).map((license) => ({ ...license, copies: list(license.copies), uses: list(license.uses) }))
}

/** Download URL of the complete backup (JSON). */
export function fullBackupUrl(): string {
	return apiUrl('/backup/full')
}

/**
 * Restore a complete backup into an empty catalog.
 *
 * @param fileId - Nextcloud id of the backup file (JSON)
 * @return restored rows per table
 */
export async function restoreBackup(fileId: number): Promise<Record<string, number>> {
	const { data } = await axios.post<{ restored: Record<string, number> }>(apiUrl('/backup/restore'), { fileId })
	return data.restored ?? {}
}
