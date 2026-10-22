<script setup lang="ts">
/**
 * Report of the file sync with the archive folder.
 */
import type { FileSyncReport } from '../types.ts'

import { n, t } from '../l10n.ts'
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ReportList from './ReportList.vue'

const props = defineProps<{
	report: FileSyncReport
}>()

const counts = computed(() => [
	{ label: t('notenarchiv', 'Files with a shelf mark in the name'), value: props.report.files },
	{ label: t('notenarchiv', 'Linked to a copy'), value: props.report.assigned },
	{ label: t('notenarchiv', 'Newly linked'), value: props.report.new },
	{ label: t('notenarchiv', 'Changed since the last sync'), value: props.report.changed },
	{ label: t('notenarchiv', 'No longer found'), value: props.report.missing },
	{ label: t('notenarchiv', 'Other files (ignored)'), value: props.report.ignored ?? 0 },
])

const byType = computed(() => Object.entries(props.report.byType && !Array.isArray(props.report.byType) ? props.report.byType : {})
	.sort((a, b) => b[1] - a[1]))
</script>

<template>
	<div class="file-sync-report">
		<NcNoteCard type="success" :heading="t('notenarchiv', 'File sync completed')">
			{{ t('notenarchiv', 'Files were only read; nothing was changed, moved or deleted.') }}
		</NcNoteCard>

		<dl class="file-sync-report__counts">
			<div v-for="count in counts" :key="count.label">
				<dt>{{ count.label }}</dt>
				<dd>{{ count.value }}</dd>
			</div>
		</dl>

		<table v-if="byType.length > 0" class="file-sync-report__table">
			<caption>{{ t('notenarchiv', 'Files by type') }}</caption>
			<tbody>
				<tr v-for="[type, count] in byType" :key="type">
					<th scope="row">
						{{ type.toUpperCase() }}
					</th>
					<td>{{ count }}</td>
				</tr>
			</tbody>
		</table>

		<NcNoteCard v-if="report.missing > 0" type="warning">
			{{ n('notenarchiv',
				'%n file is no longer in the archive folder. It stays listed as "not found".',
				'%n files are no longer in the archive folder. They stay listed as "not found".',
				report.missing) }}
		</NcNoteCard>

		<div v-if="report.unassignable.length > 0">
			<ReportList :summary="n('notenarchiv', '%n file could not be assigned', '%n files could not be assigned', report.unassignable.length)"
				:items="report.unassignable" />
			<p class="file-sync-report__muted">
				{{ t('notenarchiv', 'The shelf mark at the start of these file names is not in the catalog. Check the file name or the catalog.') }}
			</p>
		</div>

		<div v-if="report.copiesWithoutFile.length > 0">
			<ReportList :summary="n('notenarchiv', '%n copy has no file', '%n copies have no file', report.copiesWithoutFile.length)"
				:items="report.copiesWithoutFile"
				inline
				:open-up-to="40" />
		</div>

		<div v-if="report.skippedFolders.length > 0">
			<ReportList :summary="n('notenarchiv', '%n folder was skipped', '%n folders were skipped', report.skippedFolders.length)"
				:items="report.skippedFolders"
				:open-up-to="0" />
			<p class="file-sync-report__muted">
				{{ t('notenarchiv', 'Folders whose name starts with "_" (tools, exports, duplicates) are not searched.') }}
			</p>
		</div>
	</div>
</template>

<style scoped>
.file-sync-report {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.file-sync-report__counts {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
}

.file-sync-report__counts div {
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
}

.file-sync-report__counts dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.file-sync-report__counts dd {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.file-sync-report__table caption {
	text-align: start;
	font-weight: bold;
}

.file-sync-report__table th,
.file-sync-report__table td {
	padding: 2px calc(2 * var(--default-grid-baseline)) 2px 0;
	text-align: start;
	font-weight: normal;
}

.file-sync-report__table td {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.file-sync-report__muted {
	color: var(--color-text-maxcontrast);
}
</style>
