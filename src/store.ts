/**
 * Shared state of the app: meta data, catalog entries, filters, selected
 * entry and open dialogs. Plain Vue refs, no store library.
 */
import type { Detail, Entry, FieldInfo, FilterContext, Filters, Level, Meta, ReleaseRow, Role, SortOrder } from './types.ts'

import { loadState } from '@nextcloud/initial-state'
import { computed, reactive, ref, shallowRef } from 'vue'
import { errorMessage, fetchEntries, fetchEntry, fetchMeta, fetchReleaseList, normalizeMeta } from './api.ts'
import { DEFAULT_FILTERS, filterEntries, hasActiveFilters, searchText, sortEntries, worksWithValidVersion } from './catalog.ts'
import { optionLabel, translateLabel } from './format.ts'

/** Role keys used by the UI (see docs/api.md). */
export const ROLE_LIBRARIAN = 'librarian'

// ---- meta -----------------------------------------------------------------

export const meta = shallowRef<Meta | null>(null)
export const metaError = ref('')

export const isAdmin = computed(() => meta.value?.admin === true)

/**
 * Whether the current user holds a role.
 *
 * @param role - role key
 */
export function hasRole(role: string): boolean {
	return meta.value?.roles.includes(role) ?? false
}

export const isLibrarian = computed(() => hasRole(ROLE_LIBRARIAN))
export const isOperations = computed(() => hasRole('operations'))
export const isConductor = computed(() => hasRole('conductor'))
export const isContributor = computed(() => hasRole('contributor'))
export const isReader = computed(() => hasRole('reader'))

/**
 * Whether the user holds one of the roles.
 *
 * @param roles - role keys
 */
export function hasAnyRole(roles: Role[]): boolean {
	return roles.some((role) => hasRole(role))
}

/** Roles that may create tasks and questions. */
export const canCreateTasks = computed(() => hasAnyRole(['librarian', 'conductor', 'operations', 'contributor']))
/** Roles that may create internal excerpt links. */
export const canCreateLinks = computed(() => hasAnyRole(['librarian', 'conductor', 'operations']))
/** Roles that plan concerts and song lists. */
export const canPlanEvents = computed(() => hasAnyRole(['conductor', 'librarian']))
/** Roles that see the proposals inbox. */
export const canSeeProposals = computed(() => hasAnyRole(['librarian', 'contributor', 'reader']))

export const hasArchiveFolder = computed(() => (meta.value?.archive.id ?? 0) > 0)

/**
 * Whether the current user may change a field: one of the field's write
 * roles must be among the user's roles. The server checks again.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function canWrite(level: Level, key: string): boolean {
	const info = fieldInfo(level, key)
	if (!info || info.write.length === 0) {
		return false
	}
	return info.write.some((role) => hasRole(role))
}

/**
 * Field directory entry, if the server knows the field.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function fieldInfo(level: Level, key: string): FieldInfo | undefined {
	return meta.value?.fields[level]?.[key]
}

/**
 * Translated label of a field; the key if the field is unknown.
 *
 * @param level - work, version or copy
 * @param key - field key
 */
export function fieldLabel(level: Level, key: string): string {
	const info = fieldInfo(level, key)
	return info ? translateLabel(info.label) : key
}

/**
 * Translated label of a choice value, e.g. a copy status.
 *
 * @param level - work, version or copy
 * @param key - field key
 * @param value - option key
 */
export function choiceLabel(level: Level, key: string, value: string | null | undefined): string {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	return optionLabel(fieldInfo(level, key), value)
}

/** Take meta from the initial state of the page, or fetch it. */
export async function initMeta(): Promise<void> {
	let initial: Meta | null = null
	try {
		initial = loadState<Meta | null>('notenarchiv', 'meta', null)
	} catch {
		initial = null
	}
	if (initial) {
		meta.value = normalizeMeta(initial)
		return
	}
	await reloadMeta()
}

export async function reloadMeta(): Promise<void> {
	try {
		meta.value = await fetchMeta()
		metaError.value = ''
	} catch (error) {
		metaError.value = errorMessage(error)
	}
}

// ---- catalog entries ------------------------------------------------------

export const entries = shallowRef<Entry[]>([])
export const entriesStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
export const entriesError = ref('')

/**
 * (Re)load the catalog. While reloading after a change the old list stays
 * visible; a failed reload keeps it and only sets `entriesError`.
 *
 * @return whether loading succeeded
 */
export async function loadEntries(): Promise<boolean> {
	if (entriesStatus.value !== 'ready') {
		entriesStatus.value = 'loading'
	}
	try {
		entries.value = await fetchEntries()
		entriesStatus.value = 'ready'
		entriesError.value = ''
		return true
	} catch (error) {
		entriesError.value = errorMessage(error)
		if (entriesStatus.value !== 'ready') {
			entriesStatus.value = 'error'
		}
		return false
	}
}

// ---- release information ----------------------------------------------------

/**
 * All rows of the singers' portal list (also blocked versions), for the
 * column "in the portal / paper only" of the song lists. Loaded only by the
 * views that need it.
 */
export const portalRows = shallowRef<ReleaseRow[]>([])

let portalRowsLoaded = false

export async function loadPortalRows(): Promise<void> {
	portalRowsLoaded = true
	try {
		portalRows.value = await fetchReleaseList('portal', true)
	} catch {
		// Optional information: the list still works without it
		portalRows.value = []
	}
}

/** Reload the portal rows after a change, if a view has loaded them. */
export async function refreshPortalRows(): Promise<void> {
	if (portalRowsLoaded) {
		await loadPortalRows()
	}
}

/** Versions that are in the singers' portal now. */
export const versionsOnPortal = computed(() => new Set(portalRows.value
	.filter((row) => row.status === 'ok' || row.status === 'remove')
	.map((row) => row.versionId)))

export const filterContext = computed<FilterContext>(() => ({
	worksWithValidVersion: worksWithValidVersion(entries.value),
}))

// ---- filters and sorting --------------------------------------------------

export const filters = reactive<Filters>({ ...DEFAULT_FILTERS })
export const sortOrder = ref<SortOrder>('catalog')

export const filtersActive = computed(() => hasActiveFilters(filters))

export function resetFilters(): void {
	Object.assign(filters, DEFAULT_FILTERS)
}

const searchIndex = computed(() => new Map(entries.value.map((entry) => [entry.id, searchText(entry)])))

export const visibleEntries = computed(() => sortEntries(
	filterEntries(entries.value, filters, searchIndex.value, filterContext.value),
	sortOrder.value,
))

/** Entries by id, e.g. to show shelf marks in lists of tasks or links. */
export const entriesById = computed(() => new Map(entries.value.map((entry) => [entry.id, entry])))

/** Archive binders present in the data, ascending. */
export const binderOptions = computed(() => {
	const binders = new Set<number>()
	for (const entry of entries.value) {
		if (entry.binder !== null && entry.binder !== undefined) {
			binders.add(entry.binder)
		}
	}
	return [...binders].sort((a, b) => a - b)
})

// ---- selected entry -------------------------------------------------------

export const selectedId = ref<number | null>(null)
/** Tab of the sidebar: overview, rights, tasks, files, audit */
export const sidebarTab = ref('overview')
/** Task to point out in the sidebar (opened via ?task=) */
export const highlightedTaskId = ref<number | null>(null)
export const detail = shallowRef<Detail | null>(null)
export const detailLoading = ref(false)
export const detailError = ref('')

let detailRequest = 0

/**
 * Open an entry in the sidebar.
 *
 * @param id - copy id
 * @param tab - sidebar tab to show (default: overview for another copy)
 */
export async function openEntry(id: number, tab?: string): Promise<void> {
	if (tab !== undefined) {
		sidebarTab.value = tab
	} else if (selectedId.value !== id) {
		// another copy starts with the overview
		sidebarTab.value = 'overview'
	}
	if (selectedId.value !== id) {
		highlightedTaskId.value = null
	}
	selectedId.value = id
	if (detail.value?.copy.id !== id) {
		detail.value = null
	}
	await loadDetail(true)
}

/**
 * (Re)load the selected entry.
 *
 * @param showSpinner - false keeps the current content visible while loading
 */
export async function loadDetail(showSpinner = false): Promise<void> {
	const id = selectedId.value
	if (id === null) {
		return
	}
	const request = ++detailRequest
	detailLoading.value = showSpinner
	detailError.value = ''
	try {
		const result = await fetchEntry(id)
		if (request === detailRequest) {
			detail.value = result
		}
	} catch (error) {
		if (request === detailRequest) {
			detailError.value = errorMessage(error)
		}
	} finally {
		if (request === detailRequest) {
			detailLoading.value = false
		}
	}
}

export function closeEntry(): void {
	detailRequest++
	selectedId.value = null
	highlightedTaskId.value = null
	scoreRequest.value = null
	detail.value = null
	detailError.value = ''
	detailLoading.value = false
}

// ---- score view requests -----------------------------------------------------

/** What the score view should show when it opens (from a link or a task). */
export interface ScoreRequest {
	copyId: number
	measureFrom?: string | null
	measureTo?: string | null
	voice?: string | null
	verse?: number | null
	tempo?: number | null
	note?: string | null
	/** message to show above the score, e.g. "the score has changed" */
	notice?: string | null
	noticeType?: 'info' | 'warning'
}

/** Set to open the score view of a copy; CopySidebar takes it over. */
export const scoreRequest = shallowRef<ScoreRequest | null>(null)

// ---- dialogs --------------------------------------------------------------

export const archiveDialogOpen = ref(false)
export const settingsDialogOpen = ref(false)
