<script setup lang="ts">
/**
 * List of concerts or song lists. Imported and sung concerts are history;
 * planned events can be created and edited by the conductor and the
 * catalog role.
 */
import type { ChoirEvent, EventKind } from '../types.ts'

import { mdiAlertCircleOutline, mdiCalendarBlankOutline, mdiPlus } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import EventDialog from './EventDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchEvents } from '../api.ts'
import { focusWhenPresent } from '../focus.ts'
import { formatDate } from '../format.ts'
import { eventStatusLabel } from '../labels.ts'
import { navigate } from '../navigation.ts'
import { canPlanEvents } from '../store.ts'

const props = defineProps<{
	kind: EventKind
}>()

const events = ref<ChoirEvent[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')
const showImported = ref(true)
const creating = ref(false)

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		events.value = await fetchEvents(props.kind)
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.kind, () => {
	loaded.value = false
	load()
})

const isConcert = computed(() => props.kind === 'concert')

/** Newest first; events without a date at the top (still in planning). */
const shown = computed(() => [...events.value]
	.filter((event) => showImported.value || !event.imported)
	.sort((a, b) => (b.event_date ?? '9999').localeCompare(a.event_date ?? '9999') || (b.period ?? '').localeCompare(a.period ?? '')))

/**
 * Date or period of an event.
 *
 * @param event - concert or song list
 */
function when(event: ChoirEvent): string {
	if (event.event_date) {
		return formatDate(event.event_date)
	}
	return event.period ?? '–'
}

/**
 * @param event - the new event
 */
function onCreated(event: ChoirEvent): void {
	creating.value = false
	navigate('event', { eventId: event.id })
	// The new programme opens; its title gets the focus once it is loaded
	focusWhenPresent('event-detail-title')
}
</script>

<template>
	<div class="events-view">
		<ViewHeader :title="isConcert ? t('notenarchiv', 'Concerts') : t('notenarchiv', 'Song lists')"
			:description="isConcert
				? t('notenarchiv', 'Programmes with the version per item, warnings about rights and deadlines. A concert confirmed as sung counts as documented performance.')
				: t('notenarchiv', 'Song lists of a period. They are no proof of performance.')"
			printable
			reloadable
			:loading="loading"
			@reload="load">
			<template #actions>
				<NcButton v-if="canPlanEvents" variant="primary" @click="creating = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ isConcert ? t('notenarchiv', 'New concert') : t('notenarchiv', 'New song list') }}
				</NcButton>
			</template>
		</ViewHeader>

		<div class="events-view__filters no-print">
			<NcCheckboxRadioSwitch v-model="showImported" type="switch">
				{{ t('notenarchiv', 'Show imported history') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="events-view__content">
			<NcEmptyContent v-if="!loaded && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('notenarchiv', 'The list could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="shown.length === 0"
				:name="isConcert ? t('notenarchiv', 'No concerts yet') : t('notenarchiv', 'No song lists yet')"
				:description="t('notenarchiv', 'Create one or import the history in the archive management.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCalendarBlankOutline" />
				</template>
			</NcEmptyContent>
			<table v-else class="events-view__table">
				<caption class="hidden-visually">
					{{ n('notenarchiv', '%n entry', '%n entries', shown.length) }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ isConcert ? t('notenarchiv', 'Date') : t('notenarchiv', 'Period') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Title') }}
						</th>
						<th v-if="isConcert" scope="col">
							{{ t('notenarchiv', 'Location') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Status') }}
						</th>
						<th scope="col" class="events-view__number">
							{{ t('notenarchiv', 'Pieces') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="event in shown" :key="event.id">
						<td class="events-view__when">
							{{ when(event) }}
						</td>
						<td>
							<button type="button" class="events-view__open" @click="navigate('event', { eventId: event.id })">
								{{ event.title || t('notenarchiv', '(no title)') }}
							</button>
							<span v-if="event.imported" class="events-view__tag">{{ t('notenarchiv', 'imported') }}</span>
						</td>
						<td v-if="isConcert">
							{{ event.location }}
						</td>
						<td>{{ eventStatusLabel(event.status) }}</td>
						<td class="events-view__number">
							{{ event.items ?? '' }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<EventDialog v-if="creating"
			:kind="kind"
			@saved="onCreated"
			@close="creating = false" />
	</div>
</template>

<style scoped>
.events-view__filters {
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.events-view__content {
	padding: calc(3 * var(--default-grid-baseline));
}

.events-view__table {
	width: 100%;
	border-collapse: collapse;
}

.events-view__table th,
.events-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.events-view__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.events-view__when {
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.events-view__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.events-view__open {
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
}

.events-view__open:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.events-view__tag {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

@media print {
	.events-view__content {
		padding: 0;
	}

	.events-view__open {
		text-decoration: none;
	}
}
</style>
