<script setup lang="ts">
/**
 * The annual report of one year for the board and the general meeting:
 * concerts with their dates and pieces, new accessions, licenses, open
 * rights questions and the stock. Printable on A4 in black and white (also
 * in large print) and as CSV. The figures about rights and stock are those
 * of today: the archive keeps no earlier states of them.
 */
import type { AnnualReport, ReportLicense } from '../types.ts'

import { mdiAlertCircleOutline, mdiDownload, mdiPrinterOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { annualReportCsvUrl, errorMessage, fetchAnnualReport } from '../api.ts'
import { formatDate } from '../format.ts'
import { n, t } from '../l10n.ts'
import { licenseUseLabel } from '../labels.ts'
import { defaultReportYear, formatNumber, yearsBetween } from '../statistics.ts'

const props = defineProps<{
	/** today ("YYYY-MM-DD", from the server) */
	today: string
	/** first year with data */
	firstYear: number
}>()

const largePrint = defineModel<boolean>('largePrint', { default: false })

const emit = defineEmits<{
	print: []
}>()

const report = ref<AnnualReport | null>(null)
const loading = ref(false)
const error = ref('')
const announcement = ref('')
const year = ref(defaultReportYear(props.today))

const currentYear = computed(() => Number(props.today.slice(0, 4)))

const yearOptions = computed(() => yearsBetween(Math.min(props.firstYear, currentYear.value - 9), currentYear.value)
	.reverse()
	.map((value) => ({ id: value, label: String(value) })))

const yearOption = computed({
	get: () => yearOptions.value.find((option) => option.id === year.value) ?? null,
	set: (option: { id: number } | null) => {
		if (option) {
			year.value = option.id
		}
	},
})

/** Number of the latest request: an older answer that arrives later is dropped. */
let latest = 0

async function load(): Promise<void> {
	const request = ++latest
	loading.value = true
	error.value = ''
	try {
		const result = await fetchAnnualReport(year.value)
		if (request !== latest) {
			return
		}
		report.value = result
		announcement.value = t('notenarchiv', 'Annual report {year} loaded.', { year: result.year })
	} catch (caught) {
		if (request === latest) {
			error.value = errorMessage(caught)
		}
	} finally {
		if (request === latest) {
			loading.value = false
		}
	}
}

onMounted(load)
watch(year, load)

// The view reloads the report together with the statistics
defineExpose({ load })

/** The year in figures. */
const summary = computed(() => {
	const r = report.value
	if (r === null) {
		return []
	}
	const rows = [
		{ label: t('notenarchiv', 'Concerts sung'), value: r.totals.concerts },
		{ label: t('notenarchiv', 'Planned concerts'), value: r.totals.planned },
		{ label: t('notenarchiv', 'Pieces sung'), value: r.totals.pieces },
		{ label: t('notenarchiv', 'Different works'), value: r.totals.works },
		{ label: t('notenarchiv', 'New accessions'), value: r.additions.accession },
		{ label: t('notenarchiv', 'From the catalog import'), value: r.additions.import },
		{ label: t('notenarchiv', 'Other new copies'), value: r.additions.other },
		{ label: t('notenarchiv', 'Licenses valid on {date}', { date: formatDate(r.licenses.validOn) }), value: r.licenses.valid },
		{ label: t('notenarchiv', 'Licenses that started in {year}', { year: r.year }), value: r.licenses.started.length },
		{ label: t('notenarchiv', 'Licenses that end in {year}', { year: r.year }), value: r.licenses.ended.length },
	]
	// Figures that are always zero for most choirs only when they are not
	return rows.filter((row, index) => row.value > 0 || ![1, 5, 6].includes(index))
})

const openRights = computed(() => {
	const open = report.value?.openRights
	if (!open) {
		return []
	}
	return [
		{ label: t('notenarchiv', 'Rights status open'), value: open.open },
		{ label: t('notenarchiv', 'No rights status'), value: open.none },
		{ label: t('notenarchiv', 'Public domain, not confirmed'), value: open.publicDomainUnconfirmed },
		{ label: t('notenarchiv', 'Protected, not confirmed'), value: open.protectedUnconfirmed },
		{ label: t('notenarchiv', 'Marked protected, but public domain by the life dates'), value: open.protectionEnded },
		{ label: t('notenarchiv', 'Marked public domain, but the persons do not allow it'), value: open.conflicts },
		{ label: t('notenarchiv', 'Open tasks about rights'), value: open.rightsTasks },
	]
})

const stock = computed(() => {
	const s = report.value?.stock
	if (!s) {
		return []
	}
	return [
		{ label: t('notenarchiv', 'Works'), value: s.works },
		{ label: t('notenarchiv', 'Versions'), value: s.versions },
		{ label: t('notenarchiv', 'Copies'), value: s.copies },
		{ label: t('notenarchiv', 'Active copies'), value: s.active },
	]
})

const licenseTables = computed(() => {
	const r = report.value
	if (r === null) {
		return []
	}
	return [
		{ key: 'started', caption: t('notenarchiv', 'Licenses that started in {year}', { year: r.year }), rows: r.licenses.started },
		{ key: 'ended', caption: t('notenarchiv', 'Licenses that end in {year}', { year: r.year }), rows: r.licenses.ended },
	]
})

/**
 * Uses of a license as text.
 *
 * @param license - license row
 */
function usesText(license: ReportLicense): string {
	return license.uses.map(licenseUseLabel).join(', ') || '–'
}

/**
 * Date of a concert: a full date in the user's format, a year as it is.
 *
 * @param date - "YYYY-MM-DD", "YYYY" or null
 */
function concertDate(date: string | null): string {
	return date === null ? '–' : formatDate(date)
}
</script>

<template>
	<section class="annual-report" aria-labelledby="statistics-report">
		<h3 id="statistics-report" class="annual-report__heading no-print">
			{{ t('notenarchiv', 'Annual report') }}
		</h3>
		<p class="annual-report__intro no-print">
			{{ t('notenarchiv', 'A summary of one year for the board and the general meeting: concerts, pieces sung, new accessions, licenses and open rights questions. It prints on A4 in black and white.') }}
		</p>
		<div class="annual-report__controls no-print">
			<NcSelect v-model="yearOption"
				class="annual-report__year"
				:input-label="t('notenarchiv', 'Year of the report')"
				:options="yearOptions"
				:clearable="false" />
			<NcCheckboxRadioSwitch v-model="largePrint" type="switch">
				{{ t('notenarchiv', 'Large print') }}
			</NcCheckboxRadioSwitch>
			<NcButton :disabled="report === null || loading" @click="emit('print')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPrinterOutline" />
				</template>
				{{ t('notenarchiv', 'Print annual report') }}
			</NcButton>
			<NcButton :href="annualReportCsvUrl(year)" download>
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('notenarchiv', 'Annual report as CSV') }}
			</NcButton>
		</div>
		<p class="hidden-visually" aria-live="polite">
			{{ announcement }}
		</p>

		<NcEmptyContent v-if="report === null && loading" :name="t('notenarchiv', 'Loading …')">
			<template #icon>
				<NcLoadingIcon :size="64" />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="report === null && error"
			:name="t('notenarchiv', 'The annual report could not be loaded')"
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
		<div v-else-if="report !== null" class="annual-report__body" :aria-busy="loading">
			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
			<h4 class="annual-report__title">
				{{ t('notenarchiv', 'Annual report {year}', { year: report.year }) }}
			</h4>
			<p class="annual-report__meta">
				{{ t('notenarchiv', 'Music archive, as of {date}', { date: formatDate(report.today) }) }}
			</p>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('notenarchiv', 'The year in figures') }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('notenarchiv', 'Figure') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('notenarchiv', 'Number') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in summary" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>

			<table class="annual-report__table">
				<caption>{{ t('notenarchiv', 'Concerts {year}', { year: report.year }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('notenarchiv', 'Date') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Concert') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Location') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Status') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('notenarchiv', 'Pieces') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="report.concerts.length === 0">
						<td colspan="5">
							{{ t('notenarchiv', 'No concerts in this year.') }}
						</td>
					</tr>
					<tr v-for="concert in report.concerts" :key="concert.id">
						<td class="annual-report__date">
							{{ concertDate(concert.date) }}
						</td>
						<th scope="row">
							{{ concert.title || t('notenarchiv', 'Untitled') }}
						</th>
						<td>{{ concert.location || '–' }}</td>
						<td>{{ concert.status === 'sung' ? t('notenarchiv', 'Sung') : t('notenarchiv', 'Planned') }}</td>
						<td class="annual-report__number">
							{{ formatNumber(concert.pieces) }}
						</td>
					</tr>
				</tbody>
			</table>
			<p class="annual-report__note">
				{{ t('notenarchiv', 'Pieces of a sung concert: the documented performances of works in the catalog; of a planned concert: the pieces on the programme.') }}
			</p>

			<table class="annual-report__table">
				<caption>{{ t('notenarchiv', 'New accessions {year}', { year: report.year }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('notenarchiv', 'Recorded on') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Shelf mark') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Title') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Composer') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="report.accessions.length === 0">
						<td colspan="4">
							{{ t('notenarchiv', 'No new accessions in this year.') }}
						</td>
					</tr>
					<tr v-for="accession in report.accessions" :key="accession.copyId">
						<td class="annual-report__date">
							{{ formatDate(accession.date) }}
						</td>
						<th scope="row" class="annual-report__shelfmark">
							{{ accession.shelfmark }}
						</th>
						<td>{{ accession.title || t('notenarchiv', 'Untitled') }}</td>
						<td>{{ accession.composer || '–' }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="report.accessionsOmitted > 0" class="annual-report__note">
				{{ n('notenarchiv', '%n internal preservation copy is counted but not listed.', '%n internal preservation copies are counted but not listed.', report.accessionsOmitted) }}
			</p>

			<table v-for="table in licenseTables" :key="table.key" class="annual-report__table">
				<caption>{{ table.caption }}</caption>
				<thead>
					<tr>
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
							{{ t('notenarchiv', 'Valid from') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Valid until') }}
						</th>
						<th scope="col">
							{{ t('notenarchiv', 'Shelf marks') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="table.rows.length === 0">
						<td colspan="6">
							{{ t('notenarchiv', 'None.') }}
						</td>
					</tr>
					<tr v-for="license in table.rows" :key="license.licenseId">
						<th scope="row">
							{{ license.title || t('notenarchiv', 'Untitled') }}
							<span v-if="license.composer" class="annual-report__muted">{{ license.composer }}</span>
						</th>
						<td>{{ license.holder || '–' }}</td>
						<td>{{ usesText(license) }}</td>
						<td class="annual-report__date">
							{{ license.validFrom ? formatDate(license.validFrom) : '–' }}
						</td>
						<td class="annual-report__date">
							{{ license.validUntil ? formatDate(license.validUntil) : t('notenarchiv', 'without end') }}
						</td>
						<td>{{ license.shelfmarks.join(', ') || '–' }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="report.licenses.omitted > 0" class="annual-report__note">
				{{ n('notenarchiv', '%n license of internal preservation copies is counted but not listed.', '%n licenses of internal preservation copies are counted but not listed.', report.licenses.omitted) }}
			</p>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('notenarchiv', 'Open rights questions (as of {date})', { date: formatDate(report.today) }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('notenarchiv', 'Question') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('notenarchiv', 'Versions or tasks') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in openRights" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>

			<table class="annual-report__table annual-report__table--figures">
				<caption>{{ t('notenarchiv', 'Stock (as of {date})', { date: formatDate(report.today) }) }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('notenarchiv', 'Figure') }}
						</th>
						<th scope="col" class="annual-report__number">
							{{ t('notenarchiv', 'Number') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in stock" :key="row.label">
						<th scope="row">
							{{ row.label }}
						</th>
						<td class="annual-report__number">
							{{ formatNumber(row.value) }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>

<style scoped>
.annual-report {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.annual-report__heading {
	margin: 0;
	font-size: 1.25em;
	font-weight: bold;
}

.annual-report__intro {
	max-width: 80ch;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.annual-report__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.annual-report__year {
	width: 12em;
}

.annual-report__body {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.annual-report__title {
	margin: 0;
	font-size: 1.15em;
	font-weight: bold;
}

.annual-report__meta,
.annual-report__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.annual-report__table {
	width: 100%;
	border-collapse: collapse;
}

.annual-report__table--figures {
	max-width: 40em;
}

.annual-report__table caption {
	padding-bottom: var(--default-grid-baseline);
	font-weight: bold;
	text-align: start;
}

.annual-report__table th,
.annual-report__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	overflow-wrap: break-word;
}

.annual-report__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.annual-report__table tbody th {
	font-weight: normal;
}

.annual-report__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.annual-report__date,
.annual-report__shelfmark {
	white-space: nowrap;
}

.annual-report__muted {
	display: block;
	color: var(--color-text-maxcontrast);
}

@media print {
	.annual-report__meta,
	.annual-report__note,
	.annual-report__muted,
	.annual-report__table thead th {
		color: #000;
	}

	.annual-report__title {
		font-size: 1.6em;
	}

	.annual-report__table th,
	.annual-report__table td {
		padding: 1.2mm 2mm;
		border: 0.3mm solid #000;
	}

	.annual-report__table {
		break-inside: auto;
	}

	.annual-report__table tr {
		break-inside: avoid;
	}

	.annual-report__table thead {
		display: table-header-group;
	}

	.annual-report__table caption {
		break-after: avoid;
	}
}
</style>
