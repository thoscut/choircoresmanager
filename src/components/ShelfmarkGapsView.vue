<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Gaps in the shelf marks per binder (part of the inventory, milestone 0.8):
 * the numbers between 1 and the highest one used that no copy has, as a
 * printable A4 list. Information only: numbers are never reused, a new
 * accession always gets the next number after the highest.
 */
import type { ShelfmarkGaps } from '../archiveExtras.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ViewHeader from './ViewHeader.vue'
import { errorMessage } from '../api.ts'
import { rangesText } from '../archiveExtras.ts'
import { fetchShelfmarkGaps } from '../archiveExtrasApi.ts'
import { formatDate, toIsoDate } from '../format.ts'

const binders = ref<ShelfmarkGaps[] | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		binders.value = await fetchShelfmarkGaps()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const printedOn = formatDate(toIsoDate(new Date()))
const totalMissing = computed(() => (binders.value ?? []).reduce((sum, binder) => sum + binder.missing, 0))
</script>

<template>
	<div class="gaps-view">
		<ViewHeader :title="t('cantoria', 'Gaps in shelf marks')"
			:description="t('cantoria', 'Numbers between 1 and the highest number of each binder that no copy has. For information only: numbers are never reused, a new accession always gets the next number after the highest. Printed on {date}.', { date: printedOn })"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="gaps-view__content">
			<NcEmptyContent v-if="!binders && loading" :name="t('cantoria', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!binders && error" :name="t('cantoria', 'The list could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cantoria', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="binders">
				<NcNoteCard v-if="error"
					type="error"
					role="alert"
					class="no-print">
					{{ error }}
				</NcNoteCard>
				<p v-if="binders.length === 0" class="gaps-view__muted">
					{{ t('cantoria', 'The catalog has no copies yet.') }}
				</p>
				<template v-else>
					<p class="gaps-view__summary">
						<NcIconSvgWrapper v-if="totalMissing === 0" :path="mdiCheckCircleOutline" inline />
						{{ totalMissing === 0
							? t('cantoria', 'No gaps: every number up to the highest has a copy.')
							: n('cantoria', '%n number without a copy in all binders.', '%n numbers without a copy in all binders.', totalMissing) }}
					</p>
					<table class="gaps-view__table">
						<caption class="hidden-visually">
							{{ t('cantoria', 'Gaps in shelf marks') }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('cantoria', 'Binder') }}
								</th>
								<th scope="col" class="gaps-view__number">
									{{ t('cantoria', 'Highest number') }}
								</th>
								<th scope="col" class="gaps-view__number">
									{{ t('cantoria', 'Copies') }}
								</th>
								<th scope="col" class="gaps-view__number">
									{{ t('cantoria', 'Without a copy') }}
								</th>
								<th scope="col">
									{{ t('cantoria', 'Missing shelf marks') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="binder in binders" :key="binder.binder">
								<th scope="row">
									{{ binder.binder }}
								</th>
								<td class="gaps-view__number">
									{{ binder.highest }}
								</td>
								<td class="gaps-view__number">
									{{ binder.copies }}
								</td>
								<td class="gaps-view__number">
									{{ binder.missing }}
								</td>
								<td class="gaps-view__ranges">
									<template v-if="binder.ranges.length === 0">
										–
									</template>
									<template v-else>
										{{ rangesText(binder.binder, binder.ranges) }}
									</template>
									<span v-if="binder.withFile.length > 0" class="gaps-view__file">
										{{ n('cantoria', 'A file uses %n of these numbers: {list}. Probably a copy that is not in the catalog yet.',
											'Files use %n of these numbers: {list}. Probably copies that are not in the catalog yet.',
											binder.withFile.length,
											{ list: binder.withFile.map((number) => `${binder.binder}-${number}`).join(', ') }) }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</template>
			</template>
		</div>
	</div>
</template>

<style scoped>
.gaps-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	max-width: 70em;
	padding: calc(3 * var(--default-grid-baseline));
}

.gaps-view__summary {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.gaps-view__table {
	width: 100%;
	border-collapse: collapse;
}

.gaps-view__table th,
.gaps-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.gaps-view__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.gaps-view__ranges {
	overflow-wrap: anywhere;
}

.gaps-view__file {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.gaps-view__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

@media print {
	.gaps-view__content {
		padding: 0;
	}

	.gaps-view__table {
		font-size: 9.5pt;
	}

	.gaps-view__table th,
	.gaps-view__table td {
		padding: 1.2mm 1mm;
		border: 1px solid #000;
	}

	.gaps-view__table tr {
		break-inside: avoid;
	}

	.gaps-view__table thead {
		display: table-header-group;
	}
}
</style>
