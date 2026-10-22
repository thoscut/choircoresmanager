/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Types of the server API (see docs/api.md).
 */

/** The three levels of the catalog that carry editable fields. */
export type Level = 'work' | 'version' | 'copy'

export const LEVELS: Level[] = ['work', 'version', 'copy']

export type FieldType = 'text' | 'longtext' | 'number' | 'date' | 'choice' | 'boolean'

/** One entry of the field directory (`meta.fields[level][key]`). */
export interface FieldInfo {
	type: FieldType
	/** English source string, translated in the UI */
	label: string
	/** Roles that may write this field; empty = read-only */
	write: string[]
	/** Only for `choice`: key => English label */
	options?: Record<string, string>
	/** Maximum length in characters (text fields) */
	max?: number
	/** Decision field (e.g. rights confirmed): set by people, never by an import */
	decision?: boolean
}

export type FieldMap = Record<string, FieldInfo>

export interface ArchiveInfo {
	/** 0 = no archive folder configured yet */
	id: number
	path: string
	user: string
}

/** What an account has hidden (`meta.personalView`, `PUT /api/personal-view`). */
export interface PersonalView {
	/** views of the navigation */
	navigation: string[]
	/** cards of the start page, plus `concerts` and `tasks` for its lists */
	cards: string[]
}

export interface Meta {
	uid: string
	admin: boolean
	/**
	 * May change the settings (Administration → Music archive): admins and
	 * the groups the administration has given the page. No role.
	 */
	canChangeSettings: boolean
	roles: string[]
	roleLabels: Record<string, string>
	fields: Record<Level, FieldMap>
	/** license use key => English label */
	licenseUses: Record<string, string>
	/** field directory of a person (same format as fields) */
	personFields: FieldMap
	/** contribution role key => English label */
	contributionRoles: Record<string, string>
	archive: ArchiveInfo
	/**
	 * Whether the account can open the archive folder in its own Nextcloud
	 * (the app reads files only with the account's permissions); null without
	 * an archive folder
	 */
	archiveAccessible: boolean | null
	/**
	 * module key => active; null when the server sends none (older server:
	 * every module counts as active)
	 */
	modules: Record<string, boolean> | null
	/** what the account has hidden in its own view */
	personalView: PersonalView
}

/** Role keys (see docs/api.md). */
export type Role = 'librarian' | 'operations' | 'conductor' | 'contributor' | 'reader'

/** Repertoire class derived by the server. */
export type RepertoireClass = 'current' | 'dormant' | 'earlier' | 'none'

/** One row of `GET /api/entries` (one per copy). */
export interface Entry {
	id: number
	shelfmark: string
	binder: number | null
	number: number | null
	status: string
	title: string | null
	composer: string | null
	work_no: string | null
	arranger_text: string | null
	rights_status: string | null
	rights_confirmed: string | null
	/** date of the confirmation of the rights in the app, or null */
	confirmed_at?: string | null
	preservation_copy: boolean
	publication: string | null
	valid_since: string | null
	repertoire: string | null
	last_performed: string | null
	has_musicxml: boolean
	files: number
	pdfs: number
	work_id: number | null
	version_id: number | null
	repertoire_class?: RepertoireClass | null
	/** 'performances': derived from documented performances, 'import': from the imported catalog */
	repertoire_source?: 'performances' | 'import' | null
	performances?: number | null
	last_performed_on?: string | null
	/** names of the song lists the work is on (a count from older servers) */
	song_lists?: string[] | number | null
	/** review points with status open or question; null while the review of transcriptions is off */
	review_open?: number | null
	/** correction orders (review points with status correct); null while the review is off */
	review_orders?: number | null
}

export type FieldValue = string | number | boolean | null

/** A work, version or copy as delivered by the detail endpoint. */
export type DataRecord = { id: number } & Record<string, FieldValue | undefined>

export interface FileRef {
	id: number
	copy_id: number | null
	file_id: number
	path: string
	name: string
	type: string | null
	role: string | null
	size: number | null
	mtime: number | null
	listed: boolean | null
	musicxml_valid: boolean | null
	found: boolean | null
	/** linked by hand; the file sync never moves it */
	linked_manually?: boolean | null
	sha256_mismatch?: boolean | null
}

export interface Sibling {
	id: number
	shelfmark: string
	version_id: number
	status: string
}

export interface AuditEntry {
	id: number
	operation: string
	/** Unix timestamp in seconds */
	time: number
	user_id: string | null
	user_name: string | null
	roles: string | null
	object_type: string
	object_id: number | null
	action: string
	field: string | null
	old_value: string | null
	new_value: string | null
	reason: string | null
}

/** The MusicXML of a copy and what the user may do with it. */
export interface ScoreInfo {
	/** id of the file in `files[]` (not the Nextcloud file id) */
	fileId: number
	fileName: string
	canView: boolean
	canPlay: boolean
	/** translated explanation why viewing or playback is not allowed */
	reason: string | null
	/** practice audio files may be made (the server checks again before each export) */
	canExportAudio?: boolean
	/** translated explanation why no practice audio files can be made although playback is allowed */
	exportReason?: string | null
}

// ---- milestone 0.2: rights, releases, proposals ---------------------------

export interface License {
	id: number
	version_id: number
	holder: string | null
	/** comma-separated use keys */
	uses: string | null
	quantity: number | null
	valid_from: string | null
	valid_until: string | null
	evidence: string | null
	notes: string | null
}

/** A license that ends soon or has ended (GET /api/licenses/ending). */
export interface EndingLicense {
	licenseId: number
	versionId: number
	copies: { id: number, shelfmark: string }[]
	title: string | null
	composer: string | null
	holder: string | null
	uses: string[]
	validUntil: string
	ended: boolean
}

/** Editable fields of a license as sent to the server. */
export interface LicenseInput {
	holder: string | null
	uses: string[]
	quantity: number | null
	valid_from: string | null
	valid_until: string | null
	evidence: string | null
	notes: string | null
}

export interface Releases {
	/** reason why the version may not go to the singers' portal; null = released */
	portal: string | null
	onPortal: boolean
	/** reason why the version may not go to the print station; null = released */
	printStation: string | null
}

export interface Proposal {
	id: number
	object_type: Level
	object_id: number
	field: string
	/** JSON-encoded new value */
	new_value: string | null
	reason: string | null
	source: string | null
	status: string
	created_by: string | null
	created: number
}

export type ReleaseStatus = 'remove' | 'add' | 'not_decided' | 'ok' | 'other_version_valid' | 'blocked'

/** One row of a release list (singers' portal or print station). */
export interface ReleaseRow {
	versionId: number
	copies: { id: number, shelfmark: string }[]
	title: string | null
	composer: string | null
	status: ReleaseStatus
	reason: string | null
	portalSince: string | null
	validSince: string | null
	rightsStatus: string | null
	confirmed: boolean
}

// ---- milestone 0.3: tasks, links, start page -------------------------------

export type TaskKind = 'question' | 'check' | 'report'
export type TaskStatus = 'open' | 'answered' | 'applied' | 'discarded'
export type TaskCategory = 'reading' | 'structure' | 'origin' | 'shelf' | 'rights' | 'other'

export interface Task {
	id: number
	kind: TaskKind
	copy_id: number
	page: number | null
	measure_from: string | null
	measure_to: string | null
	voice: string | null
	verse: number | null
	addressee: Role
	category: TaskCategory | null
	origin: string | null
	question: string
	answer: string | null
	status: TaskStatus
	due: string | null
	created_by: string | null
	created: number
	answered_by: string | null
	answered_by_name: string | null
	answered: number | null
	closed_by: string | null
	closed: number | null
	closing_note: string | null
	/** added by the lists (/api/tasks, /api/due) */
	shelfmark?: string | null
	binder?: number | null
	number?: number | null
	title?: string | null
	repertoire?: string | null
}

/** Fields of a new task. */
export interface TaskInput {
	kind: TaskKind
	addressee: Role
	question: string
	page?: number | null
	measure_from?: string | null
	measure_to?: string | null
	voice?: string | null
	verse?: number | null
	category?: TaskCategory | null
	origin?: string | null
	due?: string | null
}

export interface ExcerptLink {
	id: number
	token: string
	copy_id: number
	file_id: number | null
	measure_from: string | null
	measure_to: string | null
	voice: string | null
	verse: number | null
	tempo: number | null
	note: string | null
	task_id: number | null
	circle: string
	created_by?: string | null
	created: number
	expires: string | null
	revoked: number | null
	hits: number
}

/**
 * Who can open an excerpt link (milestone 0.7): the archive roles, the
 * choir members (role "member", module "member_links") or anyone without
 * signing in (module "public_links").
 */
export type LinkCircle = 'internal' | 'members' | 'public'

/** Fields of a new excerpt link. */
export interface LinkInput {
	measure_from?: string | null
	measure_to?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	/** not allowed for public links */
	note?: string | null
	/** stored for internal links only */
	task_id?: number | null
	expires?: string | null
	/** default 'internal' */
	circle?: LinkCircle
}

/** Whether a link for a circle can be created for a copy (GET /api/copies/{id}/link-options). */
export interface CircleOption {
	/** the module of the circle is switched on */
	available: boolean
	/** the rights of the version allow such a link */
	allowed: boolean
	/** translated reason with the next step when not available or not allowed */
	reason: string | null
	/** the recipients can play the excerpt */
	playback: boolean
	/** choir members can make practice files of it */
	practiceAudio: boolean
}

export interface LinkOptions {
	members: CircleOption
	public: CircleOption
}

/** A link for choir members, as the page "Shared with me" receives it. */
export interface MemberLink {
	token: string
	title: string
	composer: string
	measure_from: string
	measure_to: string
	voice: string | null
	verse: number | null
	/** tempo in percent of the score's tempo */
	tempo: number | null
	note: string | null
	expires: string | null
	canPlay: boolean
	canExportAudio: boolean
	/** translated reason why the excerpt cannot be played */
	reason: string | null
}

/** Initial state `member` of the page for choir members. */
export interface MemberPageState {
	links: MemberLink[]
}

/** Initial state `excerpt` of a public link: the excerpt, already cut by the server. */
export interface PublicExcerpt {
	title: string
	composer: string
	measureFrom: string
	measureTo: string
	voice: string | null
	verse: number | null
	tempo: number | null
	canPlay: boolean
	/** MusicXML with only the measures of the excerpt */
	xml: string
}

/** Body of POST /api/member/links/{token}/practice-audio (inside `export`). */
export interface MemberPracticeAudioInput {
	/** name of the emphasised voice; null for all voices equal */
	voice: string | null
	mode: 'all' | 'focus' | 'solo'
	format: 'wav' | 'midi'
	/** tempo in percent of the score's tempo */
	tempo: number
	repeats: boolean
}

/** Body of POST /api/versions/{id}/practice-audio (inside `export`). */
export interface PracticeAudioInput {
	copy_id: number
	/** printed numbers of the first and the last measure */
	measure_from: string
	measure_to: string
	/** name of the focus voice; null for all voices equal or every voice */
	voice: string | null
	mode: 'all' | 'focus' | 'solo'
	format: 'wav' | 'midi'
	/** one file per voice plus a tutti (ZIP) */
	all_voices: boolean
	/** tempo in percent of the score's tempo */
	tempo: number
	repeats: boolean
}

/** A link as returned by GET /api/links/{token}. */
export interface ResolvedLink extends ExcerptLink {
	shelfmark: string
	createdByName: string | null
	/** the MusicXML changed since the link was created */
	changed: boolean
}

export interface DueCard {
	key: string
	count: number
	/** translated by the server, always plural; the start page shows dueCardLabel() */
	label: string
}

/** A concert with a deadline now (start page "due"). */
export interface DueConcert {
	id: number
	title: string
	/** YYYY-MM-DD */
	date: string
	/** '10', '8', '6' (weeks before) or 'confirm' (after the concert) */
	stage: string
	/** what is due, translated by the server */
	label: string
}

export interface DueResult {
	cards: DueCard[]
	tasks: Task[]
	/** missing in answers of older app versions */
	concerts?: DueConcert[]
}

// ---- milestone 0.4: concerts, song lists, repertoire -----------------------

export type EventKind = 'concert' | 'song_list'
export type EventStatus = 'draft' | 'fixed' | 'sung' | 'cancelled'

export interface ChoirEvent {
	id: number
	kind: EventKind
	title: string | null
	event_date: string | null
	/** HH:MM, local time at the venue (concerts only) */
	start_time: string | null
	end_time: string | null
	/** time zone of the times, recorded by the server */
	time_zone: string | null
	location: string | null
	period: string | null
	status: EventStatus
	is_public: boolean | null
	board_agreed: boolean | null
	imported: boolean | null
	source: string | null
	notes: string | null
	created: number
	modified: number
	/** number of items (list only) */
	items?: number
}

/** Editable fields of an event. */
export interface EventInput {
	title?: string | null
	event_date?: string | null
	start_time?: string | null
	end_time?: string | null
	location?: string | null
	period?: string | null
	is_public?: boolean
	board_agreed?: boolean
	notes?: string | null
}

export interface EventItem {
	id: number
	event_id: number
	position: number
	copy_id: number | null
	version_id: number | null
	work_id: number | null
	title_text: string | null
	composer_text: string | null
	participation: string | null
	assignment: 'certain' | 'ambiguous' | 'not_in_catalog'
	/** candidate shelf marks, e.g. "7-5 | 1-31" */
	candidates: string | null
	state: string | null
	counts: boolean | null
	sung: boolean | null
	struck: boolean | null
	encore: boolean | null
	/** seconds */
	duration: number | null
	note: string | null
	/** added by the event detail */
	shelfmark?: string | null
	title?: string | null
	composer?: string | null
	arranger_text?: string | null
	publisher?: string | null
	/** translated warnings */
	warnings?: string[]
}

export interface EventDetail {
	event: ChoirEvent
	items: EventItem[]
	/** seconds */
	totalDuration: number
	missingDurations: number
}

// ---- concerts in the calendar ------------------------------------------------

/** A calendar of the account that can take new entries (GET /api/calendars). */
export interface UserCalendar {
	uri: string
	name: string
	color: string | null
}

/** An entry that is already in one of the account's calendars. */
export interface CalendarUpdate {
	/** name of the calendar it is in */
	calendar: string
	/** CalDAV path below remote.php/dav, segments URL-encoded */
	path: string
	/** new content (iCalendar) */
	ics: string
}

/** Answer of POST /api/events/{id}/calendar and /api/events/upcoming/calendar. */
export interface CalendarAddResult {
	/** name of the chosen calendar */
	calendar: string
	/** number of concerts considered */
	concerts: number
	/** entries created in the chosen calendar */
	created: number
	/** entries to update with a CalDAV PUT by the browser */
	updates: CalendarUpdate[]
	/** entries of cancelled concerts that are in no calendar (not added) */
	skipped: number
	/** entries that could not be created */
	failed: number
}

export interface RepertoireThresholds {
	/** years */
	current: number
	dormant: number
}

export interface PerformanceImportReport {
	dryRun: boolean
	source: string
	sha256: string
	snapshot: string | null
	events: number
	songLists: number
	items: number
	certain: number
	ambiguous: number
	notInCatalog: number
	counted: number
	ownChoir: string | null
	unknownShelfmarks: string[]
	sheets: string[]
}

export interface ImportReport {
	dryRun: boolean
	source: string
	sha256: string
	operation: string | null
	restore: boolean
	snapshot: string | null
	rows: number
	created: number
	changed: number
	unchanged: number
	fieldsChanged: number
	notInCsv: string[]
	withMusicxml: number
	preservationCopies: number
	distribution: {
		rightsStatus: Record<string, number>
		repertoire: Record<string, number>
		publication: Record<string, number>
	}
	warnings: string[]
}

/** Transcription reports found and read by the file sync. */
export interface FileSyncReports {
	files: number
	assigned: number
	/** read into review points with new content */
	imported: number
	/** not read again, or read with a known checksum */
	unchanged: number
	/** reports that could not be read, with the reason (translated) */
	failed: { path: string, error: string }[]
	/** the review of transcriptions is switched off: the reports were not read */
	switchedOff: boolean
}

export interface FileSyncReport {
	files: number
	assigned: number
	new: number
	changed: number
	missing: number
	/** files without a shelf mark in their name (instructions, lists) */
	ignored: number
	/** files whose shelf mark is not in the catalog */
	unassignable: string[]
	byType: Record<string, number>
	copiesWithoutFile: string[]
	skippedFolders: string[]
	/** transcription reports (`…bericht.json`), counted apart from the score files (milestone 0.6) */
	reports?: FileSyncReports
}

/** `GET /api/settings`: what the settings page needs, without the catalog. */
export interface Settings {
	/** role key => group ids */
	roles: Record<string, string[]>
	/** group id => display name, for the groups in `roles` */
	groupNames: Record<string, string>
	/** groups in `roles` that no longer exist */
	groupsMissing: string[]
	/** imported copies and files found by the file sync (setup steps) */
	catalog: { copies: number, files: number }
	/** role key => English label */
	roleLabels: Record<string, string>
	archive: ArchiveInfo
	/** null while the module "repertoire" is not active */
	repertoire: RepertoireThresholds | null
	/** the module "folder_book" is active: its retention period */
	folderBook: boolean
	/** the module "print_mirror" is active: its target folder */
	printMirror: boolean
	/** a real admin: complete backup and restore are only for them on this page */
	admin: boolean
	/** whether a restore is possible (every app table empty); null for others than admins */
	catalogEmpty: boolean | null
}

/** A Nextcloud group to choose for a role (`GET /api/settings/groups`). */
export interface GroupOption {
	id: string
	displayName: string
}

/** An account in the access check. */
export interface AccessAccount {
	uid: string
	displayName: string
}

/** Members of a group (or the admins without a role) in the access check. */
export interface AccessMembers {
	/** accounts in the group */
	members: number
	/** accounts checked */
	checked: number
	/** checked accounts that can open the archive folder */
	canOpen: number
	/** checked accounts that cannot */
	cannotOpen: AccessAccount[]
	/** not checked: disabled, never logged in, or beyond the limit */
	notChecked: number
}

export interface AccessGroup extends AccessMembers {
	id: string
	displayName: string
	/** false: the group was deleted after it was given the role */
	exists: boolean
}

/** `GET /api/settings/access`: who can open the archive folder. */
export interface AccessReport {
	archive: ArchiveInfo
	/** the person who asked */
	self: AccessAccount & { canOpen: boolean }
	roles: { role: string, groups: AccessGroup[] }[]
	adminsWithoutRole: AccessMembers
	/** accounts checked in all */
	checked: number
	/** at most this many accounts are checked */
	limit: number
	/** false when accounts were left out because of the limit */
	complete: boolean
}

export interface SettingsUpdate {
	roles?: Record<string, string[]>
	archiveFolderId?: number
}

/** One module of `GET /api/settings/modules` (texts translated by the server). */
export interface ModuleInfo {
	key: string
	group: string
	label: string
	description: string
	/** direct requirements */
	requires: string[]
	/** the module's own switch */
	enabled: boolean
	/** whether it works: its switch and everything it requires is on */
	active: boolean
}

export interface ModuleGroup {
	key: string
	label: string
}

export interface ModulePreset {
	key: string
	label: string
	description: string
	/** module key => on, as the preset sets it */
	modules: Record<string, boolean>
}

/** `GET /api/settings/modules` */
export interface ModuleSettings {
	modules: ModuleInfo[]
	groups: ModuleGroup[]
	presets: ModulePreset[]
	/** the core, always on (translated) */
	core: string[]
}

/** What changed with `PUT /api/settings/modules`. */
export interface ModuleChanges {
	/** switches that changed as requested */
	enabled: string[]
	/** requirements switched on as well */
	alsoEnabled: string[]
	disabled: string[]
	/** switch still on, but no longer working (needs a module switched off now) */
	inactive: string[]
	/** working again without being switched themselves */
	activated: string[]
}

export interface ModuleSettingsResult extends ModuleSettings {
	changes: ModuleChanges
}

/** Sort orders of the catalog list. */
export type SortOrder = 'catalog' | 'shelfmark' | 'title'

/** Filter state of the navigation. Empty string / false = no filter. */
export interface Filters {
	search: string
	binder: number | null
	rightsStatus: string | null
	/** repertoire class (current, dormant, earlier, none) */
	repertoire: string | null
	musicxml: boolean
	preservationCopy: boolean
	/** 'active' (default), a single status key, or 'all' */
	status: string
	/** only public domain versions whose rights are not confirmed */
	unconfirmed: boolean
	/** only works without a version that is valid for the choir */
	noValidVersion: boolean
	/** only copies with open review points of a transcription */
	reviewOpen: boolean
}

/** Facts the filters need beyond the entry itself. */
export interface FilterContext {
	/** ids of works that have a valid version */
	worksWithValidVersion: Set<number>
}

// ---- milestone 0.5: maintaining the catalog ----------------------------------

export interface AccessionProposal {
	proposal: { binder: number, number: number, shelfmark: string }
	binders: { binder: number, highest: number, next: number }[]
	openBinders: number[]
}

/** Fields of a new accession (work, version and copy in one step). */
export interface AccessionInput {
	title: string
	composer: string
	title_source: string
	accession_date: string
	origin: string
	binder?: number | null
	number?: number | null
	arranger_text?: string | null
	publisher?: string | null
	rights_status?: string | null
	version_kind?: string | null
	kind?: string | null
	pages?: string | null
	originals_count?: number | null
	copies_count?: number | null
	notes?: string | null
}

export interface ShelfLabel {
	copyId: number
	shelfmark: string
	binder: number | null
	number: number | null
	/** e.g. "10 / 75" */
	label: string
	title: string | null
	composer: string | null
	arranger_text: string | null
	arrangers: string[]
}

export interface AccessionResult {
	copyId: number
	versionId: number
	workId: number
	shelfmark: string
	label: ShelfLabel
}

export type IdentificationLevel = 'certain' | 'probable' | 'uncertain'

export interface Person {
	id: number
	name: string
	gnd: string | null
	wikidata: string | null
	born: string | null
	born_precision: string | null
	died: string | null
	died_precision: string | null
	life_dates_text: string | null
	source: string | null
	identification: IdentificationLevel | null
	checked_by: string | null
	checked_by_name?: string | null
	checked_at: string | null
	notes: string | null
	created: number
	modified: number
	/** list only: number of contributions */
	contributions?: number
	/** list only: why the person prevents a protection date (reason key) */
	blocking?: string | null
}

export interface Contribution {
	id: number
	version_id: number
	person_id: number
	role: string
	text_for_composition: boolean | null
	created: number
	person?: Person
}

/** A contribution in the detail of a person, with the work and its copies. */
export interface PersonContribution extends Contribution {
	title: string | null
	composer: string | null
	workId: number | null
	copies: { id: number, shelfmark: string, status: string }[]
}

export interface PersonDetail {
	person: Person
	contributions: PersonContribution[]
}

export interface ProtectionBlocker {
	personId: number
	name: string
	reason: string
	reasonText: string
}

/** Protection proposal (display only, never changes the rights status). */
export interface Protection {
	status: 'free' | 'protected' | 'unknown'
	until: string | null
	freeSince: string | null
	reason: string
	parameters: (string | number)[]
	decisivePersonId: number | null
	blocking: ProtectionBlocker[]
	/** translated explanation */
	reasonText: string
	/** translated: proposal only, no legal advice */
	notice: string
}

export interface ProtectionReviewRow {
	versionId: number
	kind: 'ended' | 'conflict' | 'upcoming'
	rightsStatus: string | null
	confirmed: boolean
	title: string | null
	composer: string | null
	workId: number
	copies: { id: number, shelfmark: string, status: string }[]
	protection: Protection
}

export interface PersonImportReport {
	dryRun: boolean
	source: string
	sha256: string
	operation: string | null
	snapshot: string | null
	rows: number
	created: number
	existing: number
	updated: number
	skipped: number
	contributions: number
	contributionsExisting: number
	precision: Record<string, number>
	identification: Record<string, number>
	renamed: { from: string, to: string }[]
	unknownShelfmarks: string[]
	protectionDiffers: { name: string, list: string, computed: string }[]
	warnings: string[]
}

export interface MergeCandidateGroup {
	title: string
	works: {
		workId: number
		work_no: string | null
		title: string | null
		composer: string | null
		copies: { id: number, shelfmark: string, versionId: number, status: string }[]
	}[]
}

export interface Merge {
	id: number
	kind: 'work' | 'copy'
	source_id: number
	target_id: number
	reason: string | null
	created_by: string | null
	/** current display name of the account (the account id if it no longer exists) */
	created_by_name?: string | null
	created: number
	undone: number | null
	undone_by: string | null
	undone_by_name?: string | null
	/** work: versions, eventItems, workNo; copy: fromVersion, eventItems */
	moved: { versions?: number[], eventItems?: number[], workNo?: string | null, fromVersion?: number }
	/** what was merged (title, composer; for copies the shelf mark) */
	source?: { title?: string | null, composer?: string | null, work_no?: string | null, shelfmark?: string | null, copyId?: number | null }
	target?: { title?: string | null, composer?: string | null, work_no?: string | null }
}

export interface InventoryRow {
	copyId: number
	shelfmark: string
	binder: number
	number: number
	status: string
	status_reason: string | null
	kind: string | null
	originals_count: number | null
	copies_count: number | null
	last_counted: string | null
	pages: string | null
	title: string | null
	composer: string | null
	arranger_text: string | null
	preservation_copy: boolean
}

export interface Inventory {
	binder: number
	binders: { binder: number, active: number, total: number, oldestCount: string | null, uncounted: number }[]
	rows: InventoryRow[]
}

export interface CountInput {
	copyId: number
	originals?: number
	copies?: number
}

export interface ChecksumMismatch {
	id: number
	copyId: number
	shelfmark: string
	title: string | null
	path: string
	name: string
	file_id: number
	sha256: string
	sha256_at: number | null
}

export interface ChecksumStatus {
	files: number
	hashed: number
	pending: number
	oldestCheck: number | null
	perRun: number
	mismatches: ChecksumMismatch[]
}

// ---- statistics (GET /api/statistics, /api/statistics/report) ----

/** Copies of one archive binder by state. */
export interface StatisticsBinderRow {
	binder: number
	active: number
	retired: number
	missing: number
	lent: number
	other: number
	total: number
}

/** New copies of one year by where they came from. */
export interface StatisticsAdditionRow {
	year: number
	/** accession assistant */
	accession: number
	/** catalog import */
	import: number
	/** other (e.g. creation date without audit entry) */
	other: number
	total: number
}

/** Concerts and documented performances of one year. */
export interface StatisticsYearRow {
	year: number
	/** concerts confirmed as sung */
	concerts: number
	/** concerts not confirmed yet */
	planned: number
	/** programme lines that count as documented performances */
	pieces: number
	/** different works among them */
	works: number
}

export interface StatisticsRightsRow {
	/** public_domain, protected, open or none */
	status: string
	confirmed: number
	unconfirmed: number
	total: number
}

export interface StatisticsTopWork {
	workId: number
	title: string | null
	composer: string | null
	/** performance days in the period */
	performances: number
	last: string
}

export interface StatisticsTopComposer {
	composer: string
	performances: number
	works: number
}

/** A work sung before, but not within the current repertoire. */
export interface StatisticsLongRow {
	workId: number
	title: string | null
	composer: string | null
	/** dormant or earlier */
	class: string
	performances: number | null
	last: string | null
	copyId: number
	shelfmark: string
}

/** Open and answered tasks of one addressed role, by age. */
export interface StatisticsTaskRow {
	addressee: string
	open: number
	answered: number
	days30: number
	days90: number
	days365: number
	older: number
}

export interface StatisticsProtectionRow {
	versionId: number
	title: string | null
	composer: string | null
	until: string
	rightsStatus: string | null
	confirmed: boolean
	copies: { id: number, shelfmark: string, status: string }[]
}

export interface Statistics {
	period: { from: number, to: number }
	today: string
	/** years with data, for the choice of the period */
	range: { first: number, last: number }
	stock: {
		works: number
		versions: number
		copies: number
		/** active, retired, missing, lent, other */
		status: Record<string, number>
		binders: StatisticsBinderRow[]
		active: number
		activeWithFile: number
		activeWithMusicxml: number
		preservationVersions: number
		preservationCopies: number
		additions: StatisticsAdditionRow[]
	}
	rights: {
		status: StatisticsRightsRow[]
		validVersions: { works: number, withValid: number, withoutValid: number, withoutValidSeveral: number, currentWithoutValid: number }
		licenses: { total: number, active: number, ending: number, ended: number, future: number, endingDays: number }
		/** release list state => versions */
		portal: Record<string, number>
		printStation: Record<string, number>
		/** publication status (or none) => versions */
		publication: Record<string, number>
	}
	repertoire: {
		years: StatisticsYearRow[]
		undated: number
		topWorks: StatisticsTopWork[]
		topComposers: StatisticsTopComposer[]
		/** current, dormant, earlier, none => works */
		classes: Record<string, number>
		sources: { performances: number, import: number }
		thresholds: { current: number, dormant: number }
		longNotSung: StatisticsLongRow[]
		longNotSungTotal: number
	}
	maintenance: {
		tasks: StatisticsTaskRow[]
		proposals: { open: number, olderThan30Days: number }
		checksums: { files: number, hashed: number, pending: number, mismatches: number }
		protection: {
			years: { year: number, versions: number }[]
			total: number
			rows: StatisticsProtectionRow[]
			/** internal preservation copies: counted, not listed */
			omitted: number
		}
	}
}

export interface ReportLicense {
	licenseId: number
	title: string | null
	composer: string | null
	holder: string | null
	uses: string[]
	validFrom: string | null
	validUntil: string | null
	shelfmarks: string[]
}

/** The annual report of one year. */
export interface AnnualReport {
	year: number
	today: string
	concerts: { id: number, date: string | null, title: string | null, location: string | null, status: string, imported: boolean, pieces: number }[]
	totals: StatisticsYearRow
	additions: StatisticsAdditionRow
	accessions: { copyId: number, shelfmark: string, title: string | null, composer: string | null, date: string }[]
	accessionsOmitted: number
	licenses: { validOn: string, valid: number, started: ReportLicense[], ended: ReportLicense[], omitted: number }
	/** as of today */
	openRights: {
		open: number
		none: number
		publicDomainUnconfirmed: number
		protectedUnconfirmed: number
		protectionEnded: number
		conflicts: number
		rightsTasks: number
	}
	stock: { works: number, versions: number, copies: number, active: number }
}

/** Everything about one copy (GET /api/entries/{id}). */
export interface Detail {
	work: DataRecord
	version: DataRecord
	copy: DataRecord
	files: FileRef[]
	siblings: Sibling[]
	audit: AuditEntry[]
	/** null if the copy has no MusicXML */
	score: ScoreInfo | null
	licenses: License[]
	releases: Releases
	proposals: Proposal[]
	tasks: Task[]
	links: ExcerptLink[]
	contributions: Contribution[]
	protection: Protection | null
	merges: Merge[]
}

// ---- milestone 0.6: reviewing transcriptions and corrections -------------------

export type ReviewCategory = 'uncertain' | 'omitted' | 'material' | 'question'
export type ReviewStatus = 'open' | 'confirmed' | 'correct' | 'corrected' | 'question' | 'dismissed'
/** Statuses a person can set (corrected is set by the app only). */
export type ReviewDecision = 'confirmed' | 'correct' | 'question' | 'dismissed' | 'open'

/** A review point of a transcription (GET /api/versions/{id}/review). */
export interface ReviewPoint {
	id: number
	version_id: number
	copy_id: number
	report_id: number | null
	/** `files[].id` of the MusicXML the point refers to */
	file_id: number | null
	file_sha256: string | null
	category: ReviewCategory | string
	/** measures as given, e.g. "12–14" */
	measures: string | null
	measure_from: string | null
	measure_to: string | null
	voice: string | null
	verse: number | null
	page: number | null
	text: string | null
	point_key?: string | null
	/** responsible role: conductor (musical points) or librarian */
	role: string
	status: ReviewStatus | string
	/** what to change (status correct) */
	correction: string | null
	decided_by: string | null
	decided_by_name: string | null
	/** Unix timestamp in seconds */
	decided: number | null
	decision_reason: string | null
	task_id: number | null
	resolved_report_id: number | null
	created_by: string | null
	created: number
}

/** A person named in the rights part of a report (German field names of bericht/1). */
export interface ReportPerson {
	rolle?: string | null
	name?: string | null
	lebensdaten?: string | null
	beleg?: string | null
}

/** Readable parts of a report; any field may be missing. */
export interface ReportSummary {
	title?: string | null
	template?: { typ?: string | null, beschreibung?: string | null, seiten?: number | null, schreiber_oder_verlag?: string | null } | null
	extent?: { takte?: number | null, auftakt?: boolean | null, stimmen?: number | null, systeme?: number | null, strophen?: number | null } | null
	persons?: ReportPerson[] | null
	edition?: string | null
	rights?: string | null
	fields?: Record<string, string> | null
	warnings?: string[] | null
}

/** A transcription report (bericht/1) as read by the app. */
export interface TranscriptionReport {
	id: number
	version_id: number
	copy_id: number
	report_file_id: number | null
	source: 'file' | 'api' | string
	sha256: string
	round: string | null
	report_date: string | null
	basename: string | null
	musicxml_id: number | null
	musicxml_sha256: string | null
	method: string | null
	/** wv.herkunft */
	origin: string | null
	/** wv.pruefstand: review state of the transcription */
	review: string | null
	/** wv.begruendung */
	reasoning: string | null
	rights_class: string | null
	summary: ReportSummary | null
	superseded_by: number | null
	created_by: string | null
	created: number
}

export interface ReviewCounts {
	open: number
	question: number
	correct: number
	confirmed: number
	corrected: number
	dismissed: number
}

/** What the account may do (the server checks again). */
export interface ReviewPermissions {
	decideMusical: boolean
	decideMaterial: boolean
	ingest: boolean
	correct: boolean
}

/** GET /api/versions/{id}/review */
export interface VersionReview {
	version_id: number
	/** newest first */
	reports: TranscriptionReport[]
	points: ReviewPoint[]
	counts: ReviewCounts
	may: ReviewPermissions
}

export interface ReviewDecisionInput {
	status: ReviewDecision
	correction?: string | null
	reason?: string | null
	addressee?: 'conductor' | 'librarian' | null
}

/** A correction order for the tool chain (GET /api/review/orders). */
export interface CorrectionOrder {
	id: number
	version_id: number
	copy_id: number
	shelfmark: string
	title: string | null
	basename: string | null
	file_id: number | null
	file_name: string | null
	file_sha256: string | null
	measures: string | null
	measure_from: string | null
	measure_to: string | null
	voice: string | null
	verse: number | null
	category: string
	text: string | null
	correction: string | null
	decided_by_name: string | null
	decided: number | null
}

/** Result of reading a report (POST /api/versions/{id}/transcription-reports). */
export interface ReportIngestResult {
	report: TranscriptionReport
	unchanged: boolean
	points: { created: number, kept: number, closed: number, open: number }
	proposals: number
	proposalsSkipped: boolean
	warnings: string[]
}

/** One changed note of a correction, as recorded by the server. */
export interface CorrectionChange {
	measure: string
	part: string
	staff: string
	voice: string
	kind: 'pitch' | 'accidental' | 'lyric' | string
	verse: string | number | null
	old: string
	new: string
}

export interface CorrectionInput {
	/** `score.fileId` of the score shown */
	fileId: number
	/** the whole changed MusicXML */
	content: string
	pointIds: number[]
	reason: string | null
}

/** POST /api/versions/{id}/corrections */
export interface CorrectionResult {
	file: FileRef
	changes: CorrectionChange[]
	points: number[]
}
