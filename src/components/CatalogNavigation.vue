<script setup lang="ts">
/**
 * Left navigation: the views of the app (start page, catalog, tasks, inbox,
 * release lists, programmes, links, maintaining the catalog), the search and
 * – in the catalog – the filters, and the entries for archive management and
 * settings.
 */
import type { ViewName } from '../startParams.ts'

import {
	mdiAccountMultipleOutline,
	mdiArchiveOutline,
	mdiArchivePlusOutline,
	mdiBookOpenPageVariantOutline,
	mdiCalendarClockOutline,
	mdiCalendarStarOutline,
	mdiCallMerge,
	mdiClipboardCheckOutline,
	mdiClipboardTextOutline,
	mdiCogOutline,
	mdiFileCertificateOutline,
	mdiFileCheckOutline,
	mdiFilterRemoveOutline,
	mdiFormatListNumbered,
	mdiHelpRhombusOutline,
	mdiHomeOutline,
	mdiInboxArrowDownOutline,
	mdiLinkVariant,
	mdiPrinterOutline,
	mdiWeb,
} from '@mdi/js'
import { emit } from '@nextcloud/event-bus'
import { n, t } from '../l10n.ts'
import { computed, watch } from 'vue'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import { REPERTOIRE_CLASSES } from '../catalog.ts'
import { translateLabel } from '../format.ts'
import { repertoireClassLabel } from '../labels.ts'
import { currentView, navigate } from '../navigation.ts'
import { buildQuery } from '../startParams.ts'
import { viewTitle } from '../viewTitles.ts'
import {
	archiveDialogOpen,
	binderOptions,
	canSeeProposals,
	choiceLabel,
	entries,
	entriesStatus,
	fieldInfo,
	filters,
	filtersActive,
	isAdmin,
	isLibrarian,
	resetFilters,
	settingsDialogOpen,
	visibleEntries,
} from '../store.ts'

const isMobile = useIsMobile()

interface Option<T> {
	id: T
	label: string
}

/**
 * NcSelect works on option objects; the filters store only the id.
 *
 * @param option - selected option
 */
function reduceId(option: Option<unknown>): unknown {
	return option.id
}

// ---- views ----------------------------------------------------------------------

interface ViewEntry {
	view: ViewName
	name: string
	icon: string
}

const workViews = computed<ViewEntry[]>(() => [
	{ view: 'due', name: viewTitle('due', isLibrarian.value), icon: mdiHomeOutline },
	{ view: 'tasks', name: viewTitle('tasks', isLibrarian.value), icon: mdiClipboardTextOutline },
	...(canSeeProposals.value ? [{ view: 'proposals' as ViewName, name: viewTitle('proposals', isLibrarian.value), icon: mdiInboxArrowDownOutline }] : []),
])

const releaseViews = computed<ViewEntry[]>(() => [
	{ view: 'portal', name: viewTitle('portal', isLibrarian.value), icon: mdiWeb },
	{ view: 'printStation', name: viewTitle('printStation', isLibrarian.value), icon: mdiPrinterOutline },
	{ view: 'licenses', name: viewTitle('licenses', isLibrarian.value), icon: mdiFileCertificateOutline },
	{ view: 'links', name: viewTitle('links', isLibrarian.value), icon: mdiLinkVariant },
])

const archiveViews = computed<ViewEntry[]>(() => [
	{ view: 'persons', name: viewTitle('persons', isLibrarian.value), icon: mdiAccountMultipleOutline },
	{ view: 'protection', name: viewTitle('protection', isLibrarian.value), icon: mdiCalendarClockOutline },
])

const maintenanceViews = computed<ViewEntry[]>(() => [
	{ view: 'accession', name: viewTitle('accession', isLibrarian.value), icon: mdiArchivePlusOutline },
	{ view: 'merges', name: viewTitle('merges', isLibrarian.value), icon: mdiCallMerge },
	{ view: 'inventory', name: viewTitle('inventory', isLibrarian.value), icon: mdiClipboardCheckOutline },
	{ view: 'checksums', name: viewTitle('checksums', isLibrarian.value), icon: mdiFileCheckOutline },
])

const programmeViews = computed<ViewEntry[]>(() => [
	{ view: 'concerts', name: viewTitle('concerts', isLibrarian.value), icon: mdiCalendarStarOutline },
	{ view: 'songLists', name: viewTitle('songLists', isLibrarian.value), icon: mdiFormatListNumbered },
	{ view: 'ambiguous', name: viewTitle('ambiguous', isLibrarian.value), icon: mdiHelpRhombusOutline },
])

/**
 * URL of a view, so that it can also be opened in a new tab.
 *
 * @param view - the view
 */
function viewHref(view: ViewName): string {
	return window.location.pathname + buildQuery({ view })
}

/**
 * Whether a navigation entry is the current view.
 *
 * @param view - the view
 */
function isActive(view: ViewName): boolean {
	return currentView.value === view
		|| (view === 'concerts' && currentView.value === 'event')
}

/**
 * Show a view; keeps the browser's own behaviour for opening in a new tab.
 *
 * @param event - click on the navigation entry
 * @param view - the view
 */
function onViewClick(event: MouseEvent, view: ViewName): void {
	if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
		return
	}
	event.preventDefault()
	navigate(view)
	if (isMobile.value) {
		emit('toggle-navigation', { open: false })
	}
}

// Typing a search switches to the catalog
watch(() => filters.search, (search) => {
	if (search.trim() !== '' && currentView.value !== 'catalog') {
		navigate('catalog')
	}
})

// ---- catalog filters ------------------------------------------------------------

const binderSelectOptions = computed<Option<number>[]>(() => binderOptions.value.map((binder) => ({
	id: binder,
	label: t('notenarchiv', 'Binder {binder}', { binder }),
})))

const rightsSelectOptions = computed<Option<string>[]>(() => {
	const options = fieldInfo('version', 'rights_status')?.options ?? {
		public_domain: 'public domain',
		protected: 'protected',
		open: 'open',
	}
	return Object.entries(options).map(([id, label]) => ({ id, label: translateLabel(label) }))
})

const repertoireSelectOptions = computed<Option<string>[]>(() => REPERTOIRE_CLASSES.map((value) => ({ id: value, label: repertoireClassLabel(value) })))

const statusSelectOptions = computed<Option<string>[]>(() => {
	const keys = Object.keys(fieldInfo('copy', 'status')?.options ?? { active: '', retired: '', missing: '', lent: '' })
	if (!keys.includes('active')) {
		keys.unshift('active')
	}
	return [
		...keys.map((key) => ({ id: key, label: choiceLabel('copy', 'status', key) })),
		{ id: 'all', label: t('notenarchiv', 'All statuses') },
	]
})

const countText = computed(() => {
	const shown = visibleEntries.value.length
	const total = entries.value.length
	if (shown === total) {
		return n('notenarchiv', '%n copy', '%n copies', total)
	}
	return t('notenarchiv', '{shown} of {total} copies', { shown, total })
})

/**
 * Footer entries are buttons, not links.
 *
 * @param event - click on the navigation entry
 * @param open - what to open
 */
function onFooterClick(event: Event, open: 'archive' | 'settings'): void {
	event.preventDefault()
	if (open === 'archive') {
		archiveDialogOpen.value = true
	} else {
		settingsDialogOpen.value = true
	}
}
</script>

<template>
	<NcAppNavigation :aria-label="t('notenarchiv', 'Navigation of the music archive')">
		<template #search>
			<NcAppNavigationSearch v-model="filters.search"
				:label="t('notenarchiv', 'Shelf mark, title or composer')" />
		</template>

		<!-- NcAppNavigation wraps this slot in a list (ul) of its own -->
		<template #list>
			<NcAppNavigationItem v-for="entry in workViews"
				:key="entry.view"
				:name="entry.name"
				:href="viewHref(entry.view)"
				:active="isActive(entry.view)"
				@click="onViewClick($event, entry.view)">
				<template #icon>
					<NcIconSvgWrapper :path="entry.icon" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('notenarchiv', 'Archive')" />
			<NcAppNavigationItem :name="viewTitle('catalog', isLibrarian)"
				:href="viewHref('catalog')"
				:active="isActive('catalog')"
				@click="onViewClick($event, 'catalog')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBookOpenPageVariantOutline" />
				</template>
			</NcAppNavigationItem>
			<li v-if="currentView === 'catalog'" class="catalog-filters">
				<p v-if="entriesStatus === 'ready'" class="catalog-filters__count" aria-live="polite">
					{{ countText }}
				</p>

				<NcSelect v-model="filters.binder"
					:input-label="t('notenarchiv', 'Archive binder')"
					:placeholder="t('notenarchiv', 'All binders')"
					:options="binderSelectOptions"
					:reduce="reduceId" />

				<NcSelect v-model="filters.rightsStatus"
					:input-label="t('notenarchiv', 'Rights status')"
					:placeholder="t('notenarchiv', 'Any rights status')"
					:options="rightsSelectOptions"
					:reduce="reduceId" />

				<NcSelect v-model="filters.repertoire"
					:input-label="t('notenarchiv', 'Repertoire')"
					:placeholder="t('notenarchiv', 'Any repertoire')"
					:options="repertoireSelectOptions"
					:reduce="reduceId" />

				<NcSelect v-model="filters.status"
					:input-label="t('notenarchiv', 'Status')"
					:options="statusSelectOptions"
					:clearable="false"
					:searchable="false"
					:reduce="reduceId" />

				<NcCheckboxRadioSwitch v-model="filters.musicxml">
					{{ t('notenarchiv', 'With MusicXML') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="filters.preservationCopy">
					{{ t('notenarchiv', 'Internal preservation copies only') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="filters.unconfirmed">
					{{ t('notenarchiv', 'Public domain, not confirmed') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="filters.noValidVersion">
					{{ t('notenarchiv', 'No version valid for the choir') }}
				</NcCheckboxRadioSwitch>

				<NcButton v-if="filtersActive"
					variant="tertiary"
					wide
					@click="resetFilters">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFilterRemoveOutline" />
					</template>
					{{ t('notenarchiv', 'Reset search and filters') }}
				</NcButton>
			</li>
			<NcAppNavigationItem v-for="entry in archiveViews"
				:key="entry.view"
				:name="entry.name"
				:href="viewHref(entry.view)"
				:active="isActive(entry.view)"
				@click="onViewClick($event, entry.view)">
				<template #icon>
					<NcIconSvgWrapper :path="entry.icon" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('notenarchiv', 'Releases')" />
			<NcAppNavigationItem v-for="entry in releaseViews"
				:key="entry.view"
				:name="entry.name"
				:href="viewHref(entry.view)"
				:active="isActive(entry.view)"
				@click="onViewClick($event, entry.view)">
				<template #icon>
					<NcIconSvgWrapper :path="entry.icon" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('notenarchiv', 'Programmes')" />
			<NcAppNavigationItem v-for="entry in programmeViews"
				:key="entry.view"
				:name="entry.name"
				:href="viewHref(entry.view)"
				:active="isActive(entry.view)"
				@click="onViewClick($event, entry.view)">
				<template #icon>
					<NcIconSvgWrapper :path="entry.icon" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('notenarchiv', 'Maintaining the catalog')" />
			<NcAppNavigationItem v-for="entry in maintenanceViews"
				:key="entry.view"
				:name="entry.name"
				:href="viewHref(entry.view)"
				:active="isActive(entry.view)"
				@click="onViewClick($event, entry.view)">
				<template #icon>
					<NcIconSvgWrapper :path="entry.icon" />
				</template>
			</NcAppNavigationItem>
		</template>

		<template v-if="isLibrarian || isAdmin" #footer>
			<NcAppNavigationList class="catalog-footer">
				<NcAppNavigationItem v-if="isLibrarian"
					:name="t('notenarchiv', 'Archive management')"
					@click="onFooterClick($event, 'archive')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArchiveOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="isAdmin"
					:name="t('notenarchiv', 'Settings')"
					@click="onFooterClick($event, 'settings')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCogOutline" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</template>
	</NcAppNavigation>
</template>

<style scoped>
.catalog-filters {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline)) calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	list-style: none;
}

.catalog-filters__count {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.catalog-footer {
	flex: 0 0 auto;
	padding-block: var(--default-grid-baseline);
}
</style>
