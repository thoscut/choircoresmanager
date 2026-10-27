<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Annual snapshots (module "annual_snapshot", milestone 0.8): freeze the
 * state of the archive into a dated folder of the backup folder (catalog
 * as CSV and JSON, checksums of the archive files), optionally at the turn
 * of the year, and compare two snapshots as a printable A4 list.
 */
import type { Snapshot, SnapshotChange, SnapshotComparison, SnapshotOverview } from '../archiveExtras.ts'
import type { Level } from '../types.ts'

import { mdiAlertCircleOutline, mdiArchiveClockOutline, mdiCheckCircleOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ActionDialog from './ActionDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage } from '../api.ts'
import { defaultPair, differenceCount } from '../archiveExtras.ts'
import { compareSnapshots, createSnapshot, fetchSnapshots, setSnapshotAuto } from '../archiveExtrasApi.ts'
import { displayValue, formatDate, formatSize } from '../format.ts'
import { licenseUseLabel } from '../labels.ts'
import { fieldInfo, fieldLabel, isLibrarian } from '../store.ts'

const overview = ref<SnapshotOverview | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		overview.value = await fetchSnapshots()
		const pair = defaultPair(overview.value.snapshots)
		const names = overview.value.snapshots.map((snapshot) => snapshot.name)
		if (!names.includes(older.value ?? '') || !names.includes(newer.value ?? '')) {
			older.value = pair?.older ?? null
			newer.value = pair?.newer ?? null
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

/**
 * Date and time a snapshot was made, for display.
 *
 * @param snapshot - the snapshot
 */
function createdText(snapshot: Snapshot): string {
	return `${formatDate(snapshot.created.slice(0, 10))} ${snapshot.created.slice(11, 16)}`
}

/**
 * Name of a snapshot in the lists.
 *
 * @param snapshot - the snapshot
 */
function snapshotLabel(snapshot: Snapshot): string {
	return t('notenarchiv', 'Year {year}, frozen on {date}', { year: snapshot.year, date: createdText(snapshot) })
}

// ---- making a snapshot ----

const confirming = ref(false)
const busy = ref(false)
const createError = ref('')

/** Ask first: the dialog says what is written where. */
function startCreate(): void {
	createError.value = ''
	confirming.value = true
}

async function create(): Promise<void> {
	busy.value = true
	createError.value = ''
	try {
		const result = await createSnapshot()
		showSuccess(t('notenarchiv', 'The snapshot has been stored in {folder}.', { folder: result.folder }))
		confirming.value = false
		await load()
	} catch (caught) {
		createError.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}

const autoBusy = ref(false)

/**
 * @param auto - freeze automatically at the turn of the year
 */
async function changeAuto(auto: boolean): Promise<void> {
	if (!overview.value) {
		return
	}
	autoBusy.value = true
	try {
		overview.value = { ...overview.value, auto: await setSnapshotAuto(auto) }
		showSuccess(auto
			? t('notenarchiv', 'The state is frozen automatically at the turn of the year.')
			: t('notenarchiv', 'The state is no longer frozen automatically.'))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		autoBusy.value = false
	}
}

// ---- comparing ----

const older = ref<string | null>(null)
const newer = ref<string | null>(null)
const comparison = ref<SnapshotComparison | null>(null)
const comparing = ref(false)
const compareError = ref('')

interface Option {
	id: string
	label: string
}

const options = computed<Option[]>(() => (overview.value?.snapshots ?? [])
	.filter((snapshot) => snapshot.complete)
	.map((snapshot) => ({ id: snapshot.name, label: snapshotLabel(snapshot) })))

const olderOption = computed({
	get: () => options.value.find((option) => option.id === older.value) ?? null,
	set: (option: Option | null) => {
		older.value = option?.id ?? null
	},
})

const newerOption = computed({
	get: () => options.value.find((option) => option.id === newer.value) ?? null,
	set: (option: Option | null) => {
		newer.value = option?.id ?? null
	},
})

async function compare(): Promise<void> {
	if (older.value === null || newer.value === null) {
		compareError.value = t('notenarchiv', 'Choose two snapshots to compare.')
		return
	}
	comparing.value = true
	compareError.value = ''
	try {
		// The older one first, whichever way they were chosen
		const [first, second] = older.value < newer.value ? [older.value, newer.value] : [newer.value, older.value]
		comparison.value = await compareSnapshots(first, second)
	} catch (caught) {
		compareError.value = errorMessage(caught)
	} finally {
		comparing.value = false
	}
}

const FILE_GROUPS = ['added', 'removed', 'changed'] as const

const licenseFields: Record<string, () => string> = {
	holder: () => t('notenarchiv', 'License holder or publisher'),
	uses: () => t('notenarchiv', 'Uses'),
	quantity: () => t('notenarchiv', 'Number of copies'),
	valid_from: () => t('notenarchiv', 'Valid from'),
	valid_until: () => t('notenarchiv', 'Valid until'),
}

/**
 * Label of a changed field.
 *
 * @param level - level of the field, or 'license'
 * @param field - field key
 */
function changeLabel(level: Level | 'license', field: string): string {
	if (field === 'title') {
		return t('notenarchiv', 'Title')
	}
	if (field === 'version') {
		return t('notenarchiv', 'Version')
	}
	if (level === 'license') {
		return licenseFields[field]?.() ?? field
	}
	return fieldLabel(level, field)
}

/**
 * A changed value for display.
 *
 * @param level - level of the field, or 'license'
 * @param field - field key
 * @param value - old or new value
 */
function changeValue(level: Level | 'license', field: string, value: string | number | null): string {
	if (value === null || value === '') {
		return '–'
	}
	if (level === 'license') {
		if (field === 'uses') {
			return String(value).split(',').filter((use) => use !== '').map(licenseUseLabel).join(', ')
		}
		return field.startsWith('valid_') ? formatDate(String(value)) : String(value)
	}
	if (field === 'title' || field === 'version') {
		return String(value)
	}
	return displayValue(fieldInfo(level, field), value) || '–'
}

/**
 * The changes of a row as one line: "Status: active → retired; …".
 *
 * @param level - level of the fields
 * @param changes - changed fields
 */
function changesText(level: Level | 'license', changes: SnapshotChange[]): string {
	return changes.map((change) => `${changeLabel(level, change.field)}: ${changeValue(level, change.field, change.old)} → ${changeValue(level, change.field, change.new)}`)
		.join('; ')
}

/**
 * Holder and uses of a license in the comparison.
 *
 * @param row - license row of the comparison
 * @param row.holder - who granted the license
 * @param row.uses - comma-separated use keys
 */
function licenseDetails(row: { holder: string | null, uses: string | null }): string {
	return row.uses ? `${row.holder ?? '–'}; ${changeValue('license', 'uses', row.uses)}` : (row.holder ?? '–')
}

/**
 * @param change - kind of change of a license
 */
function licenseChangeLabel(change: 'added' | 'changed' | 'removed'): string {
	switch (change) {
	case 'added':
		return t('notenarchiv', 'New license')
	case 'removed':
		return t('notenarchiv', 'License removed')
	default:
		return t('notenarchiv', 'License changed')
	}
}
</script>

<template>
	<div class="snapshots-view">
		<ViewHeader :title="t('notenarchiv', 'Annual snapshots')"
			:description="t('notenarchiv', 'A snapshot freezes the state of the archive: the catalog as CSV and JSON, without accounts and the folder book, and a list of checksums of the archive files, in a dated folder of the backup folder outside the archive folder. Two snapshots can be compared and printed.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="snapshots-view__content">
			<NcEmptyContent v-if="!overview && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!overview && error" :name="t('notenarchiv', 'The snapshots could not be loaded')" :description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="overview">
				<NcNoteCard v-if="error"
					type="error"
					role="alert"
					class="no-print">
					{{ error }}
				</NcNoteCard>
				<NcNoteCard v-if="overview.lastError" type="warning" class="no-print">
					{{ t('notenarchiv', 'The last automatic snapshot failed: {error}', { error: overview.lastError }) }}
				</NcNoteCard>

				<section v-if="isLibrarian" class="snapshots-view__section no-print" aria-labelledby="snapshots-make">
					<h3 id="snapshots-make">
						{{ t('notenarchiv', 'Freeze the state') }}
					</h3>
					<p>
						{{ t('notenarchiv', 'A new snapshot of the year {year} is written to the folder "{folder}" of the account that set the archive folder. Existing snapshots are never changed.', { year: overview.next.year, folder: overview.next.folder }) }}
					</p>
					<p v-if="overview.next.exists" class="snapshots-view__muted">
						{{ t('notenarchiv', 'There is a snapshot of {year} already; the new one is added next to it.', { year: overview.next.year }) }}
					</p>
					<div class="snapshots-view__actions">
						<NcButton variant="primary" @click="startCreate">
							<template #icon>
								<NcIconSvgWrapper :path="mdiArchiveClockOutline" />
							</template>
							{{ t('notenarchiv', 'Freeze the state now …') }}
						</NcButton>
					</div>
					<NcCheckboxRadioSwitch :model-value="overview.auto"
						type="switch"
						:disabled="autoBusy"
						@update:model-value="changeAuto">
						{{ t('notenarchiv', 'Freeze automatically at the turn of the year') }}
						<template #description>
							{{ t('notenarchiv', 'In January, once, for the year before; written as the account that set the archive folder.') }}
						</template>
					</NcCheckboxRadioSwitch>
				</section>

				<section class="snapshots-view__section no-print" aria-labelledby="snapshots-list">
					<h3 id="snapshots-list">
						{{ n('notenarchiv', '%n snapshot', '%n snapshots', overview.snapshots.length) }}
					</h3>
					<p v-if="overview.snapshots.length === 0" class="snapshots-view__muted">
						{{ isLibrarian
							? t('notenarchiv', 'There is no snapshot yet. Freeze the state above.')
							: t('notenarchiv', 'There is no snapshot yet. The catalog librarian makes them.') }}
					</p>
					<ul v-else class="snapshots-view__list">
						<li v-for="snapshot in overview.snapshots" :key="snapshot.name">
							<strong>{{ snapshotLabel(snapshot) }}</strong>
							<span class="snapshots-view__muted"> · {{ snapshot.name }}</span>
							<span v-if="!snapshot.complete" class="snapshots-view__warning">
								· {{ t('notenarchiv', 'incomplete: a file is missing, it cannot be compared') }}
							</span>
						</li>
					</ul>
				</section>

				<section v-if="options.length >= 2" class="snapshots-view__section" aria-labelledby="snapshots-compare">
					<h3 id="snapshots-compare">
						{{ t('notenarchiv', 'Compare two snapshots') }}
					</h3>
					<div class="snapshots-view__compare no-print">
						<NcSelect v-model="olderOption"
							:input-label="t('notenarchiv', 'Earlier snapshot')"
							:options="options"
							:clearable="false" />
						<NcSelect v-model="newerOption"
							:input-label="t('notenarchiv', 'Later snapshot')"
							:options="options"
							:clearable="false" />
						<NcButton :disabled="comparing" @click="compare">
							<template v-if="comparing" #icon>
								<NcLoadingIcon />
							</template>
							{{ t('notenarchiv', 'Compare') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="compareError" type="error" role="alert">
						{{ compareError }}
					</NcNoteCard>

					<div v-if="comparison" class="snapshots-view__result" aria-live="polite">
						<p class="snapshots-view__heading">
							{{ t('notenarchiv', 'Changes from {older} to {newer}', { older: snapshotLabel(comparison.older), newer: snapshotLabel(comparison.newer) }) }}
						</p>
						<p v-if="differenceCount(comparison) === 0" class="snapshots-view__ok">
							<NcIconSvgWrapper :path="mdiCheckCircleOutline" inline />
							{{ t('notenarchiv', 'No differences.') }}
						</p>

						<template v-if="comparison.copies.added.length > 0">
							<h4>{{ n('notenarchiv', '%n new copy', '%n new copies', comparison.copies.added.length) }}</h4>
							<ul>
								<li v-for="row in comparison.copies.added" :key="row.shelfmark">
									{{ row.shelfmark }} · {{ row.title ?? '–' }}
								</li>
							</ul>
						</template>
						<template v-if="comparison.copies.removed.length > 0">
							<h4>{{ n('notenarchiv', '%n copy no longer in the catalog', '%n copies no longer in the catalog', comparison.copies.removed.length) }}</h4>
							<ul>
								<li v-for="row in comparison.copies.removed" :key="row.shelfmark">
									{{ row.shelfmark }} · {{ row.title ?? '–' }}
								</li>
							</ul>
						</template>
						<template v-if="comparison.copies.changed.length > 0">
							<h4>{{ n('notenarchiv', '%n changed copy', '%n changed copies', comparison.copies.changed.length) }}</h4>
							<ul>
								<li v-for="row in comparison.copies.changed" :key="row.shelfmark">
									{{ row.shelfmark }} · {{ row.title ?? '–' }}:
									{{ changesText('copy', row.changes) }}
								</li>
							</ul>
						</template>
						<template v-if="comparison.rights.length > 0">
							<h4>{{ n('notenarchiv', '%n version with changed rights', '%n versions with changed rights', comparison.rights.length) }}</h4>
							<ul>
								<li v-for="(row, index) in comparison.rights" :key="`${row.shelfmark}-${index}`">
									{{ row.shelfmark || '–' }} · {{ row.title ?? '–' }}:
									{{ changesText('version', row.changes) }}
								</li>
							</ul>
						</template>
						<template v-if="comparison.licenses.length > 0">
							<h4>{{ n('notenarchiv', '%n license change', '%n license changes', comparison.licenses.length) }}</h4>
							<ul>
								<li v-for="(row, index) in comparison.licenses" :key="`${row.shelfmark}-${index}`">
									{{ licenseChangeLabel(row.change) }}: {{ row.shelfmark || '–' }} · {{ row.title ?? '–' }}
									({{ licenseDetails(row) }})
									<template v-if="row.changes.length > 0">
										– {{ changesText('license', row.changes) }}
									</template>
								</li>
							</ul>
						</template>

						<NcNoteCard v-if="!comparison.files.compared" type="warning">
							{{ t('notenarchiv', 'The checksums of one of the snapshots cannot be read, so the files were not compared.') }}
						</NcNoteCard>
						<template v-for="group in FILE_GROUPS" :key="group">
							<template v-if="comparison.files[group].length > 0">
								<h4>
									{{ group === 'added'
										? n('notenarchiv', '%n new file', '%n new files', comparison.files[group].length)
										: group === 'removed'
											? n('notenarchiv', '%n file no longer there', '%n files no longer there', comparison.files[group].length)
											: n('notenarchiv', '%n file with changed content', '%n files with changed content', comparison.files[group].length) }}
								</h4>
								<ul class="snapshots-view__files">
									<li v-for="file in comparison.files[group]" :key="file.path">
										{{ file.path }}
										<span v-if="file.size !== null" class="snapshots-view__muted">({{ formatSize(file.size) }})</span>
									</li>
								</ul>
							</template>
						</template>
					</div>
				</section>
				<p v-else-if="overview.snapshots.length > 0" class="snapshots-view__muted no-print">
					{{ t('notenarchiv', 'A comparison needs two complete snapshots.') }}
				</p>
			</template>
		</div>

		<ActionDialog v-if="confirming && overview"
			:name="t('notenarchiv', 'Freeze the state now')"
			:submit-label="t('notenarchiv', 'Freeze')"
			:busy="busy"
			:error="createError"
			@submit="create"
			@close="confirming = false">
			<p>{{ t('notenarchiv', 'The app writes a new folder for the year {year} into "{folder}":', { year: overview.next.year, folder: overview.next.folder }) }}</p>
			<ul class="snapshots-view__files-to-write">
				<li>{{ t('notenarchiv', 'catalog.csv: the catalog with all fields, as in the weekly backup') }}</li>
				<li>{{ t('notenarchiv', 'catalog.json: the complete backup of the catalog, licenses, persons and audit log') }}</li>
				<li>
					{{ n('notenarchiv', 'manifest.csv: SHA-256 checksum, size and path of %n archive file ({size})',
						'manifest.csv: SHA-256 checksum, size and path of %n archive files ({size} in total)',
						overview.next.archiveFiles, { size: formatSize(overview.next.bytes) }) }}
				</li>
			</ul>
			<p class="action-dialog__hint">
				{{ t('notenarchiv', 'Every archive file is read for its checksum; with many files this takes a few minutes. Keep this page open until it is done.') }}
			</p>
		</ActionDialog>
	</div>
</template>

<style scoped>
.snapshots-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	max-width: 70em;
	padding: calc(3 * var(--default-grid-baseline));
}

.snapshots-view__section,
.snapshots-view__result {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.snapshots-view__section h3 {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
}

.snapshots-view__result h4,
.snapshots-view__heading {
	margin: 0;
	font-weight: bold;
}

.snapshots-view__section p {
	margin: 0;
}

.snapshots-view__result ul,
.snapshots-view__list,
.snapshots-view__files-to-write {
	padding-inline-start: 1.5em;
	list-style: disc;
	overflow-wrap: anywhere;
}

.snapshots-view__actions,
.snapshots-view__compare {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.snapshots-view__compare > * {
	flex: 1 1 16em;
}

.snapshots-view__compare > button {
	flex: 0 0 auto;
}

.snapshots-view__ok {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.snapshots-view__muted {
	color: var(--color-text-maxcontrast);
}

.snapshots-view__warning {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: bold;
}

@media print {
	.snapshots-view__content {
		padding: 0;
		font-size: 10pt;
	}

	.snapshots-view__result li {
		break-inside: avoid;
	}
}
</style>
