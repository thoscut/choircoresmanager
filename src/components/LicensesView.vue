<script setup lang="ts">
/**
 * Licenses that end soon or have ended, the soonest first – the target of
 * the start page card "licenses ending". Each row leads to the rights tab of
 * the copies, where the license is renewed or the release withdrawn.
 */
import type { EndingLicense } from '../types.ts'

import { mdiAlertCircleOutline, mdiAlertOutline, mdiCheckCircleOutline, mdiClockOutline } from '@mdi/js'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchEndingLicenses } from '../api.ts'
import { formatDate, parseIsoDate, toIsoDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { licenseUseLabel } from '../labels.ts'
import { openEntry } from '../store.ts'

const licenses = ref<EndingLicense[]>([])
const loaded = ref(false)
const loading = ref(false)
const error = ref('')

/** Period in days (as the start page card: 30). */
const days = ref(30)

const periodOptions = computed(() => [30, 90, 180, 366].map((value) => ({
	id: value,
	label: value === 366
		? t('notenarchiv', 'within a year')
		: n('notenarchiv', 'within %n day', 'within %n days', value),
})))

const period = computed({
	get: () => periodOptions.value.find((option) => option.id === days.value) ?? periodOptions.value[0],
	set: (option: { id: number } | null) => {
		if (option && option.id !== days.value) {
			days.value = option.id
			load()
		}
	},
})

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		licenses.value = await fetchEndingLicenses(days.value)
		loaded.value = true
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const today = parseIsoDate(toIsoDate(new Date()))

/**
 * When the license ends, as text.
 *
 * @param license - ending license
 */
function endText(license: EndingLicense): string {
	if (license.ended) {
		return t('notenarchiv', 'ended on {date}', { date: formatDate(license.validUntil) })
	}
	const end = parseIsoDate(license.validUntil)
	const left = end && today ? Math.round((end.getTime() - today.getTime()) / 86400000) : null
	if (left === 0) {
		return t('notenarchiv', 'ends today')
	}
	return left === null
		? t('notenarchiv', 'ends on {date}', { date: formatDate(license.validUntil) })
		: n('notenarchiv', 'ends on {date} (in %n day)', 'ends on {date} (in %n days)', left, { date: formatDate(license.validUntil) })
}

const endedCount = computed(() => licenses.value.filter((license) => license.ended).length)
</script>

<template>
	<div class="licenses-view">
		<ViewHeader :title="t('notenarchiv', 'Ending licenses')"
			:description="t('notenarchiv', 'Licenses of protected versions that end soon or have ended. Renew the license or withdraw the copies from the singers\' portal and the print station in the rights tab of the copy.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="licenses-view__content">
			<NcSelect v-model="period"
				class="licenses-view__period no-print"
				:input-label="t('notenarchiv', 'Period')"
				:options="periodOptions"
				:clearable="false"
				:searchable="false" />

			<NcEmptyContent v-if="!loaded && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!loaded && error" :name="t('notenarchiv', 'The list could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else-if="loaded">
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>
				<p v-if="licenses.length === 0" class="licenses-view__ok">
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" inline />
					{{ t('notenarchiv', 'No license ends in this period, and none has ended.') }}
				</p>
				<template v-else>
					<p class="licenses-view__summary" aria-live="polite">
						{{ n('notenarchiv', '%n license', '%n licenses', licenses.length) }}<template v-if="endedCount > 0">
							· {{ n('notenarchiv', '%n has ended', '%n have ended', endedCount) }}
						</template>
					</p>
					<table class="licenses-view__table">
						<caption class="hidden-visually">
							{{ t('notenarchiv', 'Ending licenses') }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('notenarchiv', 'Valid until') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Work') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'License holder') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Uses') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Copies') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="license in licenses"
								:key="license.licenseId"
								:class="{ 'licenses-view__row--ended': license.ended }">
								<td class="licenses-view__end">
									<NcIconSvgWrapper :path="license.ended ? mdiAlertOutline : mdiClockOutline" inline />
									{{ endText(license) }}
								</td>
								<td>
									<span class="licenses-view__title">{{ license.title || t('notenarchiv', 'Untitled') }}</span>
									<span v-if="license.composer" class="licenses-view__muted">{{ license.composer }}</span>
								</td>
								<td>{{ license.holder || '–' }}</td>
								<td>{{ license.uses.map(licenseUseLabel).join(', ') || '–' }}</td>
								<td class="licenses-view__copies">
									<button v-for="copy in license.copies"
										:key="copy.id"
										type="button"
										class="licenses-view__shelfmark"
										:aria-label="t('notenarchiv', 'Open the rights of {shelfmark}', { shelfmark: copy.shelfmark })"
										@click="openEntry(copy.id, 'rights')">
										{{ copy.shelfmark }}
									</button>
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
.licenses-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
	overflow-x: auto;
}

.licenses-view__period {
	max-width: 20em;
}

.licenses-view__ok,
.licenses-view__summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.licenses-view__table {
	width: 100%;
	border-collapse: collapse;
}

.licenses-view__table th,
.licenses-view__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	/* long words break only when they do not fit; headings stay whole */
	overflow-wrap: break-word;
}

.licenses-view__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.licenses-view__end {
	white-space: nowrap;
}

/* ended: bold and with a warning icon, not only colour */
.licenses-view__row--ended .licenses-view__end {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.licenses-view__title {
	display: block;
	font-weight: bold;
}

.licenses-view__muted {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.licenses-view__copies {
	white-space: nowrap;
}

.licenses-view__shelfmark {
	margin: 0 var(--default-grid-baseline) 0 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-decoration: underline;
	cursor: pointer;
}

.licenses-view__shelfmark:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

@media print {
	.licenses-view__content {
		padding: 0;
		overflow: visible;
	}

	.licenses-view__shelfmark {
		text-decoration: none;
	}
}
</style>
