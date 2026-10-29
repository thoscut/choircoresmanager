<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Programme lines from the imported history whose shelf mark is unclear:
 * choose one of the candidates (or another copy), or confirm that the piece
 * is not in the archive.
 */
import type { ChoirEvent, Entry, EventItem } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ViewHeader from './ViewHeader.vue'
import { canonicalShelfmark } from '../catalog.ts'
import { errorMessage, fetchAmbiguousItems, fetchEvents, resolveEventItem } from '../api.ts'
import { formatDate } from '../format.ts'
import { canPlanEvents, entries, loadEntries } from '../store.ts'

const items = ref<EventItem[]>([])
const events = ref(new Map<number, ChoirEvent>())
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const [ambiguous, concerts, songLists] = await Promise.all([
			fetchAmbiguousItems(),
			fetchEvents('concert'),
			fetchEvents('song_list'),
		])
		items.value = ambiguous
		events.value = new Map([...concerts, ...songLists].map((event) => [event.id, event]))
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const byShelfmark = computed(() => new Map(entries.value.map((entry) => [canonicalShelfmark(entry.shelfmark), entry])))

/**
 * Copies named as candidates ("7-5 | 1-31").
 *
 * @param item - unclear programme line
 */
function candidates(item: EventItem): Entry[] {
	return (item.candidates ?? '').split('|')
		.map((mark) => byShelfmark.value.get(canonicalShelfmark(mark.trim())))
		.filter((entry): entry is Entry => entry !== undefined)
}

/**
 * Concert or song list of a line.
 *
 * @param item - programme line
 */
function eventText(item: EventItem): string {
	const event = events.value.get(item.event_id)
	if (!event) {
		return ''
	}
	return [event.title, event.event_date ? formatDate(event.event_date) : event.period].filter((part) => part).join(' · ')
}

/** Choice per line: copy id, 'none' (not in the archive) or 'other'. */
const choices = reactive<Record<number, string>>({})
const others = reactive<Record<number, { id: number, label: string } | null>>({})
const busyId = ref<number | null>(null)
const itemError = reactive<Record<number, string>>({})

const copyOptions = computed(() => entries.value.map((entry) => ({ id: entry.id, label: `${entry.shelfmark} – ${entry.title ?? ''}` })))

/**
 * @param item - programme line to resolve
 */
async function resolve(item: EventItem): Promise<void> {
	const choice = choices[item.id]
	let copyId: number | null
	if (choice === 'none') {
		copyId = null
	} else if (choice === 'other') {
		copyId = others[item.id]?.id ?? 0
	} else {
		copyId = Number(choice)
	}
	if (copyId === 0 || Number.isNaN(copyId)) {
		itemError[item.id] = t('cantoria', 'Please choose a copy.')
		return
	}
	busyId.value = item.id
	itemError[item.id] = ''
	try {
		await resolveEventItem(item.id, copyId)
		showSuccess(t('cantoria', 'The programme line has been assigned.'))
		items.value = items.value.filter((other) => other.id !== item.id)
		await loadEntries()
	} catch (caught) {
		itemError[item.id] = errorMessage(caught)
	} finally {
		busyId.value = null
	}
}
</script>

<template>
	<div class="ambiguous-view">
		<ViewHeader :title="t('cantoria', 'Unclear programme lines')"
			:description="t('cantoria', 'Lines of imported programmes whose shelf mark is not clear. Choose the right copy or confirm that the piece is not in the archive.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="ambiguous-view__content">
			<NcEmptyContent v-if="!loaded && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error"
				:name="t('cantoria', 'The list could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="items.length === 0"
				:name="t('cantoria', 'All programme lines are assigned')"
				:description="t('cantoria', 'There are no unclear shelf marks.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
				</template>
			</NcEmptyContent>
			<template v-else>
				<p class="ambiguous-view__count">
					{{ n('cantoria', '%n unclear line', '%n unclear lines', items.length) }}
				</p>
				<ul class="ambiguous-view__list">
					<li v-for="item in items" :key="item.id" class="ambiguous-item">
						<p class="ambiguous-item__title">
							{{ item.title_text || t('cantoria', '(no title)') }}
							<span v-if="item.composer_text" class="ambiguous-item__muted">{{ item.composer_text }}</span>
						</p>
						<p class="ambiguous-item__muted">
							{{ eventText(item) }}
							<template v-if="item.candidates">
								· {{ t('cantoria', 'Candidates: {candidates}', { candidates: item.candidates }) }}
							</template>
						</p>
						<fieldset v-if="canPlanEvents" class="ambiguous-item__choices no-print">
							<legend class="hidden-visually">
								{{ t('cantoria', 'Assign: {title}', { title: item.title_text ?? '' }) }}
							</legend>
							<NcCheckboxRadioSwitch v-for="candidate in candidates(item)"
								:key="candidate.id"
								v-model="choices[item.id]"
								type="radio"
								:value="String(candidate.id)"
								:name="`ambiguous-${item.id}`">
								{{ candidate.shelfmark }} – {{ candidate.title }}
								<template v-if="candidate.composer">
									({{ candidate.composer }})
								</template>
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch v-model="choices[item.id]"
								type="radio"
								value="other"
								:name="`ambiguous-${item.id}`">
								{{ t('cantoria', 'Another copy') }}
							</NcCheckboxRadioSwitch>
							<NcSelect v-if="choices[item.id] === 'other'"
								v-model="others[item.id]"
								class="ambiguous-item__other"
								:input-label="t('cantoria', 'Copy')"
								:options="copyOptions" />
							<NcCheckboxRadioSwitch v-model="choices[item.id]"
								type="radio"
								value="none"
								:name="`ambiguous-${item.id}`">
								{{ t('cantoria', 'Not in the archive') }}
							</NcCheckboxRadioSwitch>
							<div>
								<NcButton variant="primary"
									:disabled="!choices[item.id] || busyId !== null"
									@click="resolve(item)">
									<template v-if="busyId === item.id" #icon>
										<NcLoadingIcon />
									</template>
									{{ t('cantoria', 'Assign') }}
								</NcButton>
							</div>
							<NcNoteCard v-if="itemError[item.id]" type="error" role="alert">
								{{ itemError[item.id] }}
							</NcNoteCard>
						</fieldset>
					</li>
				</ul>
			</template>
		</div>
	</div>
</template>

<style scoped>
.ambiguous-view__content {
	padding: calc(3 * var(--default-grid-baseline));
}

.ambiguous-view__count {
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.ambiguous-view__list {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.ambiguous-item {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	break-inside: avoid;
}

.ambiguous-item p {
	margin: 0;
}

.ambiguous-item__title {
	font-weight: bold;
}

.ambiguous-item__muted {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.ambiguous-item__choices {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: var(--default-grid-baseline) 0 0;
	padding: 0;
	border: none;
}

.ambiguous-item__other {
	max-width: 30em;
	margin-inline-start: calc(6 * var(--default-grid-baseline));
}
</style>
