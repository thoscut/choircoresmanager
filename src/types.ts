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

export interface Meta {
	uid: string
	admin: boolean
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

/** Fields of a new excerpt link. */
export interface LinkInput {
	measure_from?: string | null
	measure_to?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	note?: string | null
	task_id?: number | null
	expires?: string | null
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

export interface DueResult {
	cards: DueCard[]
	tasks: Task[]
}

// ---- milestone 0.4: concerts, song lists, repertoire -----------------------

export type EventKind = 'concert' | 'song_list'
export type EventStatus = 'draft' | 'fixed' | 'sung'

export interface ChoirEvent {
	id: number
	kind: EventKind
	title: string | null
	event_date: string | null
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
}

export interface Settings {
	roles: Record<string, string[]>
	archive: ArchiveInfo
}

export interface SettingsUpdate {
	roles?: Record<string, string[]>
	archiveFolderId?: number
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
