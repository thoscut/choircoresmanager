<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Left navigation: the views of the app (start page, catalog, tasks, inbox,
 * release lists, programmes, links, maintaining the catalog), the search and
 * – in the catalog – the filters, and the entries for archive management and
 * settings (a link to Administration → Music archive).
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
	mdiChartBar,
	mdiClipboardCheckOutline,
	mdiClipboardTextOutline,
	mdiCogOutline,
	mdiEyeSettingsOutline,
	mdiFileCertificateOutline,
	mdiFileCheckOutline,
	mdiFilterRemoveOutline,
	mdiFormatListNumbered,
	mdiHelpCircleOutline,
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
import { adminSettingsUrl } from '../api.ts'
import { REPERTOIRE_CLASSES } from '../catalog.ts'
import { translateLabel } from '../format.ts'
import { repertoireClassLabel } from '../labels.ts'
import { helpUrl } from '../docs.ts'
import { currentView, navigate } from '../navigation.ts'
import { buildQuery } from '../startParams.ts'
import { viewTitle } from '../viewTitles.ts'
import {
	archiveDialogOpen,
	binderOptions,
	canChangeSettings,
	canSeeProposals,
	choiceLabel,
	entries,
	entriesStatus,
	fieldInfo,
	filters,
	filtersActive,
	isLibrarian,
	isModuleActive,
	isViewShown,
	personalViewDialogOpen,
	resetFilters,
	visibleEntries,
} from '../store.ts'

/** The settings are a page under Administration → Music archive. */
const settingsUrl = adminSettingsUrl()

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

/**
 * Entries of a group of the navigation: views of switched-off modules and
 * views the account has hidden (personal view) are left out.
 *
 * @param list - view and icon of each entry
 */
function shown(list: { view: ViewName, icon: string }[]): ViewEntry[] {
	return list
		.filter((entry) => isViewShown(entry.view))
		.map((entry) => ({ ...entry, name: viewTitle(entry.view, isLibrarian.value) }))
}

const workViews = computed<ViewEntry[]>(() => shown([
	{ view: 'due', icon: mdiHomeOutline },
	{ view: 'tasks', icon: mdiClipboardTextOutline },
	...(canSeeProposals.value ? [{ view: 'proposals' as ViewName, icon: mdiInboxArrowDownOutline }] : []),
]))

const releaseViews = computed<ViewEntry[]>(() => shown([
	{ view: 'portal', icon: mdiWeb },
	{ view: 'printStation', icon: mdiPrinterOutline },
	{ view: 'licenses', icon: mdiFileCertificateOutline },
	{ view: 'links', icon: mdiLinkVariant },
]))

const archiveViews = computed<ViewEntry[]>(() => shown([
	{ view: 'persons', icon: mdiAccountMultipleOutline },
	{ view: 'protection', icon: mdiCalendarClockOutline },
	{ view: 'statistics', icon: mdiChartBar },
]))

const maintenanceViews = computed<ViewEntry[]>(() => shown([
	{ view: 'accession', icon: mdiArchivePlusOutline },
	{ view: 'merges', icon: mdiCallMerge },
	{ view: 'inventory', icon: mdiClipboardCheckOutline },
	{ view: 'checksums', icon: mdiFileCheckOutline },
]))

const programmeViews = computed<ViewEntry[]>(() => shown([
	{ view: 'concerts', icon: mdiCalendarStarOutline },
	{ view: 'songLists', icon: mdiFormatListNumbered },
	{ view: 'ambiguous', icon: mdiHelpRhombusOutline },
]))

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
 * Footer entries that open a dialog are buttons, not links.
 *
 * @param event - click on the navigation entry
 * @param open - what to open
 */
function onFooterClick(event: Event, open: 'archive' | 'view'): void {
	event.preventDefault()
	if (open === 'archive') {
		archiveDialogOpen.value = true
	} else {
		personalViewDialogOpen.value = true
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
				<NcCheckboxRadioSwitch v-if="isModuleActive('rights')" v-model="filters.unconfirmed">
					{{ t('notenarchiv', 'Public domain, not confirmed') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="isModuleActive('valid_version')" v-model="filters.noValidVersion">
					{{ t('notenarchiv', 'No version valid for the choir') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="isModuleActive('transcription_review')" v-model="filters.reviewOpen">
					{{ t('notenarchiv', 'Open review points') }}
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

			<NcAppNavigationCaption v-if="releaseViews.length > 0" :name="t('notenarchiv', 'Releases')" />
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

			<NcAppNavigationCaption v-if="programmeViews.length > 0" :name="t('notenarchiv', 'Programmes')" />
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

			<NcAppNavigationCaption v-if="maintenanceViews.length > 0" :name="t('notenarchiv', 'Maintaining the catalog')" />
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

		<template #footer>
			<NcAppNavigationList class="catalog-footer">
				<!-- the guide opens in a new tab (external link) -->
				<NcAppNavigationItem :name="t('notenarchiv', 'Help')"
					:href="helpUrl()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiHelpCircleOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('notenarchiv', 'Customize view')"
					@click="onFooterClick($event, 'view')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiEyeSettingsOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="isLibrarian"
					:name="t('notenarchiv', 'Archive management')"
					@click="onFooterClick($event, 'archive')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArchiveOutline" />
					</template>
				</NcAppNavigationItem>
				<!-- A link: the settings are a page under Administration -->
				<NcAppNavigationItem v-if="canChangeSettings"
					:name="t('notenarchiv', 'Settings')"
					:href="settingsUrl">
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
