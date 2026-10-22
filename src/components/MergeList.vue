<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Merges of works and moved copies, with "Undo" for the librarian. Used in
 * the merge view (all merges) and in the overview of a copy (the merges
 * that concern it).
 */
import type { Merge } from '../types.ts'

import { mdiUndoVariant } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, undoMerge } from '../api.ts'
import { focusAfterDialog } from '../focus.ts'
import { formatDateTime } from '../format.ts'
import { isLibrarian, openEntry } from '../store.ts'

defineProps<{
	merges: Merge[]
	/** shorter texts for the overview of a copy */
	compact?: boolean
}>()

const emit = defineEmits<{
	changed: []
}>()

/**
 * Title, composer and work number of a work, as far as known.
 *
 * @param work - work facts
 * @param work.title - title
 * @param work.composer - composer
 * @param work.work_no - work number
 */
function workName(work?: { title?: string | null, composer?: string | null, work_no?: string | null }): string {
	if (!work) {
		return '–'
	}
	const title = work.title || t('notenarchiv', 'Untitled')
	const parts = [work.composer, work.work_no].filter((part) => part)
	return parts.length > 0 ? `${title} (${parts.join(', ')})` : title
}

/**
 * What was merged, in one sentence.
 *
 * @param merge - the merge
 */
function mergeText(merge: Merge): string {
	if (merge.kind === 'work') {
		if (merge.source || merge.target) {
			return t('notenarchiv', '"{source}" merged into "{target}"', { source: workName(merge.source), target: workName(merge.target) })
		}
		return t('notenarchiv', 'Another work has been merged into this work.')
	}
	if (merge.source?.shelfmark) {
		return t('notenarchiv', 'Copy {shelfmark} moved from "{source}" to a version of "{target}"', {
			shelfmark: merge.source.shelfmark,
			source: workName(merge.source),
			target: workName(merge.target),
		})
	}
	return t('notenarchiv', 'This copy has been moved to another version.')
}

/**
 * What moved along, e.g. versions and programme lines.
 *
 * @param merge - the merge
 */
function movedText(merge: Merge): string {
	const parts: string[] = []
	if (merge.kind === 'work' && merge.moved.versions?.length) {
		parts.push(n('notenarchiv', '%n version moved', '%n versions moved', merge.moved.versions.length))
	}
	if (merge.moved.eventItems?.length) {
		parts.push(n('notenarchiv', '%n programme line moved', '%n programme lines moved', merge.moved.eventItems.length))
	}
	if (merge.moved.workNo) {
		parts.push(t('notenarchiv', 'work number {number} given', { number: merge.moved.workNo }))
	}
	return parts.join(' · ')
}

/**
 * Who merged: the display name, otherwise the account id.
 *
 * @param merge - the merge
 */
function createdBy(merge: Merge): string {
	return merge.created_by_name || merge.created_by || ''
}

/**
 * Who undid the merge: the display name, otherwise the account id.
 *
 * @param merge - the merge
 */
function undoneBy(merge: Merge): string {
	return merge.undone_by_name || merge.undone_by || ''
}

// ---- undo ----

const undoing = ref<Merge | null>(null)
const reason = ref('')
const busy = ref(false)
const error = ref('')

/**
 * @param merge - merge to undo
 */
function startUndo(merge: Merge): void {
	undoing.value = merge
	reason.value = ''
	error.value = ''
}

const root = useTemplateRef<HTMLElement>('root')

async function confirmUndo(): Promise<void> {
	if (!undoing.value) {
		return
	}
	// The heading above the list gets the focus afterwards: the button that
	// was used is gone (WCAG 2.4.3)
	const heading = root.value?.closest('section')?.querySelector('h2, h3') ?? null
	busy.value = true
	error.value = ''
	try {
		await undoMerge(undoing.value.id, reason.value.trim() === '' ? null : reason.value.trim())
		showSuccess(t('notenarchiv', 'The merge has been undone.'))
		undoing.value = null
		emit('changed')
		focusAfterDialog(() => (heading?.isConnected ? heading : document.querySelector('.app-sidebar-header__mainname, #app-content-vue h2')))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div ref="root">
		<ul class="merge-list">
			<li v-for="merge in merges"
				:key="merge.id"
				class="merge-list__item"
				:class="{ 'merge-list__item--undone': merge.undone !== null }">
				<div class="merge-list__text">
					<span class="merge-list__what">{{ mergeText(merge) }}</span>
					<span v-if="movedText(merge) && !compact" class="merge-list__muted">{{ movedText(merge) }}</span>
					<span v-if="merge.reason" class="merge-list__muted">{{ t('notenarchiv', 'Reason: {reason}', { reason: merge.reason }) }}</span>
					<span class="merge-list__muted">
						{{ createdBy(merge)
							? t('notenarchiv', '{date} by {user}', { date: formatDateTime(merge.created), user: createdBy(merge) })
							: formatDateTime(merge.created) }}
					</span>
					<span v-if="merge.undone !== null" class="merge-list__undone">
						{{ undoneBy(merge)
							? t('notenarchiv', 'Undone on {date} by {user}', { date: formatDateTime(merge.undone), user: undoneBy(merge) })
							: t('notenarchiv', 'Undone on {date}', { date: formatDateTime(merge.undone) }) }}
					</span>
				</div>
				<div class="merge-list__actions">
					<NcButton v-if="merge.kind === 'copy' && merge.source?.copyId && !compact"
						variant="tertiary"
						@click="openEntry(merge.source.copyId)">
						{{ t('notenarchiv', 'Open {shelfmark}', { shelfmark: merge.source.shelfmark ?? '' }) }}
					</NcButton>
					<NcButton v-if="isLibrarian && merge.undone === null"
						variant="secondary"
						@click="startUndo(merge)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiUndoVariant" />
						</template>
						{{ t('notenarchiv', 'Undo') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<ActionDialog v-if="undoing"
			:name="t('notenarchiv', 'Undo merge')"
			:submit-label="t('notenarchiv', 'Undo merge')"
			:busy="busy"
			:error="error"
			@submit="confirmUndo"
			@close="undoing = null">
			<p>{{ mergeText(undoing) }}</p>
			<p class="action-dialog__hint">
				{{ t('notenarchiv', 'Everything moves back as it was. This is only possible as long as the moved data has not been changed; later merges have to be undone first. A work number given by the merge stays.') }}
			</p>
			<NcTextField v-model="reason" :label="t('notenarchiv', 'Reason (optional)')" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.merge-list {
	display: flex;
	flex-direction: column;
}

.merge-list__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-block: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.merge-list__item:last-child {
	border-bottom: none;
}

.merge-list__text {
	display: flex;
	flex: 1 1 20em;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	overflow-wrap: anywhere;
}

.merge-list__what {
	font-weight: bold;
}

.merge-list__item--undone .merge-list__what {
	text-decoration: line-through;
}

.merge-list__muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.merge-list__undone {
	font-size: var(--font-size-small, 13px);
	font-weight: bold;
}

.merge-list__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
