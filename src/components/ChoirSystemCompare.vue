<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Reading what the choir system has (milestone 0.12): an export of the
 * choir system (.csv or .xlsx) is compared with the list of the singers'
 * portal, always as a dry run first. "Create proposals" puts the
 * differences into the inbox; nothing in the catalog changes until the
 * catalog librarian accepts them there.
 */
import type { ChoirSystemReport, ComparedPiece } from '../types.ts'

import { mdiFileCompare, mdiInboxArrowDownOutline } from '@mdi/js'
import { FilePickerClosed, FilePickerType, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { compareChoirSystem, errorMessage } from '../api.ts'
import { focusAfterDialog, focusAfterUpdate } from '../focus.ts'
import { showError } from '../toasts.ts'

const file = ref<{ id: number, name: string } | null>(null)
const report = ref<ChoirSystemReport | null>(null)
const busy = ref(false)
const error = ref('')

const chooseButton = useTemplateRef('chooseButton')
const reportSection = useTemplateRef<HTMLElement>('reportSection')

async function choose(): Promise<void> {
	let nodes
	try {
		nodes = await getFilePickerBuilder(t('cantoria', 'Choose the export of the choir system (.csv or .xlsx)'))
			.setMultiSelect(false)
			.setMimeTypeFilter(['text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
			.setType(FilePickerType.Choose)
			.build()
			.pickNodes()
	} catch (caught) {
		if (!(caught instanceof FilePickerClosed)) {
			showError(errorMessage(caught))
		}
		await focusAfterDialog(chooseButton)
		return
	}
	const node = nodes[0]
	if (!node?.fileid) {
		showError(t('cantoria', 'The chosen file cannot be used.'))
		await focusAfterDialog(chooseButton)
		return
	}
	file.value = { id: node.fileid, name: node.displayname || node.basename }
	report.value = null
	await run(true)
}

/**
 * @param dryRun - only compare
 */
async function run(dryRun: boolean): Promise<void> {
	if (!file.value) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		report.value = await compareChoirSystem(file.value.id, dryRun)
		if (!dryRun) {
			showSuccess(t('cantoria', 'The differences are in the inbox as proposals.'))
		}
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
	await focusAfterUpdate(() => (error.value === '' ? reportSection.value?.querySelector('.notecard') : chooseButton.value))
}

/**
 * "2-3 Am Brunnen vor dem Tore"
 *
 * @param piece - the piece
 */
function pieceName(piece: ComparedPiece): string {
	return [piece.shelfmark, piece.title].filter(Boolean).join(' ')
}
</script>

<template>
	<div class="choir-compare">
		<div class="choir-compare__actions">
			<NcButton ref="chooseButton" :disabled="busy" @click="choose">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileCompare" />
				</template>
				{{ file ? t('cantoria', 'Choose another file …') : t('cantoria', 'Compare with an export …') }}
			</NcButton>
			<NcButton v-if="report?.dryRun && report.added.length + report.removed.length > 0"
				variant="primary"
				:disabled="busy"
				@click="run(false)">
				<template #icon>
					<NcLoadingIcon v-if="busy" />
					<NcIconSvgWrapper v-else :path="mdiInboxArrowDownOutline" />
				</template>
				{{ t('cantoria', 'Create proposals') }}
			</NcButton>
		</div>
		<p v-if="busy" class="choir-compare__muted">
			<NcLoadingIcon :size="20" />
			{{ t('cantoria', 'Comparing …') }}
		</p>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>

		<section v-if="report && !busy"
			ref="reportSection"
			class="choir-compare__report"
			:aria-label="t('cantoria', 'Comparison with the choir system')">
			<NcNoteCard :type="report.dryRun ? 'info' : 'success'">
				{{ report.dryRun
					? t('cantoria', '{file}: {matched} of {rows} rows are pieces of the archive. Nothing has been proposed yet.', { file: report.file, matched: report.matched, rows: report.rows })
					: t('cantoria', '{file}: the proposals are in the inbox. The catalog changes only when they are accepted there.', { file: report.file }) }}
			</NcNoteCard>
			<p v-if="report.added.length + report.removed.length + report.removeThere.length === 0">
				{{ t('cantoria', 'The choir system has exactly what the list of the singers\' portal says.') }}
			</p>
			<template v-if="report.added.length > 0">
				<h4>{{ n('cantoria', '%n piece is in the choir system but not recorded as in the portal', '%n pieces are in the choir system but not recorded as in the portal', report.added.length) }}</h4>
				<ul>
					<li v-for="piece in report.added" :key="'a' + piece.versionId">
						{{ pieceName(piece) }}
					</li>
				</ul>
			</template>
			<template v-if="report.removed.length > 0">
				<h4>{{ n('cantoria', '%n piece is recorded as in the portal but missing in the choir system', '%n pieces are recorded as in the portal but missing in the choir system', report.removed.length) }}</h4>
				<ul>
					<li v-for="piece in report.removed" :key="'r' + piece.versionId">
						{{ pieceName(piece) }}
					</li>
				</ul>
			</template>
			<p v-if="report.alreadyProposed > 0" class="choir-compare__muted">
				{{ n('cantoria', '%n difference is already waiting in the inbox.', '%n differences are already waiting in the inbox.', report.alreadyProposed) }}
			</p>
			<template v-if="report.removeThere.length > 0">
				<NcNoteCard type="warning">
					{{ n('cantoria', '%n piece is in the choir system but not released for the singers\' portal. Remove it there; the archive stays as it is.', '%n pieces are in the choir system but not released for the singers\' portal. Remove them there; the archive stays as it is.', report.removeThere.length) }}
				</NcNoteCard>
				<ul>
					<li v-for="piece in report.removeThere" :key="'x' + piece.versionId">
						{{ pieceName(piece) }}
						<span class="choir-compare__muted">{{ piece.reason || t('cantoria', 'Another version of the work is valid for the choir.') }}</span>
					</li>
				</ul>
			</template>
			<template v-if="report.unknownCount > 0">
				<h4>{{ n('cantoria', '%n row without a shelf mark of the archive', '%n rows without a shelf mark of the archive', report.unknownCount) }}</h4>
				<ul>
					<li v-for="(row, index) in report.unknown" :key="'u' + index">
						{{ row }}
					</li>
				</ul>
			</template>
		</section>
	</div>
</template>

<style scoped>
.choir-compare,
.choir-compare__report {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.choir-compare__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.choir-compare p,
.choir-compare li {
	margin: 0;
	max-width: 80ch;
	overflow-wrap: anywhere;
}

.choir-compare h4 {
	margin: 0;
	font-weight: bold;
}

.choir-compare ul {
	margin: 0;
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
}

.choir-compare__muted {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

li .choir-compare__muted {
	display: block;
}
</style>
