<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The folder book (module "folder_book"): which choir folder is out with
 * whom since when, what it holds and what is missing; issuing, taking back
 * and maintaining the folders. A folder that is out offers no second issue,
 * and taking back starts with what is known. Printable in A4.
 */
import type { Folder, FolderBook, FolderFilter } from '../folderBook.ts'

import { mdiAlertCircleOutline, mdiFolderAccountOutline, mdiFolderPlusOutline, mdiHistory } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FolderDialog from './FolderDialog.vue'
import FolderLoanDialog from './FolderLoanDialog.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage } from '../api.ts'
import { fetchFolderBook, filterFolders, folderSummary, holderText, outdatedText, retentionText, wearLabel } from '../folderBook.ts'
import { formatDate } from '../format.ts'

const book = ref<FolderBook | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		book.value = await fetchFolderBook()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const summary = computed(() => folderSummary(book.value?.folders ?? []))

const filter = ref<FolderFilter>('all')
const search = ref('')

interface FilterOption {
	id: FolderFilter
	label: string
}

const filterOptions = computed<FilterOption[]>(() => [
	{ id: 'all', label: t('notenarchiv', 'All folders in service ({count})', { count: summary.value.active }) },
	{ id: 'out', label: t('notenarchiv', 'Out ({count})', { count: summary.value.out }) },
	{ id: 'available', label: t('notenarchiv', 'Available ({count})', { count: summary.value.available }) },
	{ id: 'hints', label: t('notenarchiv', 'With missing sheets or a hint ({count})', { count: summary.value.hints }) },
	{ id: 'retired', label: t('notenarchiv', 'Out of service') },
])

const filterOption = computed({
	get: () => filterOptions.value.find((option) => option.id === filter.value) ?? filterOptions.value[0],
	set: (option: FilterOption | null) => {
		filter.value = option?.id ?? 'all'
	},
})

const shown = computed(() => filterFolders(book.value?.folders ?? [], filter.value, search.value))

const canEdit = computed(() => book.value?.canEdit === true)

// ---- dialogs ----

const editing = ref<Folder | null>(null)
const creating = ref(false)
const loanDialog = ref<{ folder: Folder, mode: 'issue' | 'return' } | null>(null)

/** Folders whose history is open. */
const openHistory = ref<Set<number>>(new Set())

/**
 * Show or hide the loans of a folder.
 *
 * @param folder - the folder
 */
function toggleHistory(folder: Folder): void {
	const next = new Set(openHistory.value)
	if (next.has(folder.id)) {
		next.delete(folder.id)
	} else {
		next.add(folder.id)
	}
	openHistory.value = next
}

async function onSaved(): Promise<void> {
	editing.value = null
	creating.value = false
	loanDialog.value = null
	await load()
}

/**
 * Hints of the contents of a folder that no longer apply.
 *
 * @param folder - the folder
 */
function hints(folder: Folder): string[] {
	return folder.contents.filter((item) => item.outdated !== null).map(outdatedText)
}
</script>

<template>
	<div class="folder-book">
		<ViewHeader :title="t('notenarchiv', 'Folder book')"
			:description="t('notenarchiv', 'Which choir folder is out with whom since when, what it holds and what is missing.')"
			printable
			reloadable
			:loading="loading"
			@reload="load">
			<template v-if="canEdit" #actions>
				<NcButton variant="primary" @click="creating = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderPlusOutline" />
					</template>
					{{ t('notenarchiv', 'New folder') }}
				</NcButton>
			</template>
		</ViewHeader>

		<div class="folder-book__content">
			<NcEmptyContent v-if="book === null && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="book === null && error"
				:name="t('notenarchiv', 'The folder book could not be loaded')"
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

			<template v-else-if="book !== null">
				<NcNoteCard type="info" class="no-print">
					{{ retentionText(book.retentionMonths) }}
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error" role="alert">
					{{ error }}
				</NcNoteCard>

				<NcEmptyContent v-if="book.folders.length === 0"
					:name="t('notenarchiv', 'No folders yet')"
					:description="canEdit
						? t('notenarchiv', 'Create a folder for each choir folder you hand out: its number or name, the voice and the copies it holds. Then issue it to a person.')
						: t('notenarchiv', 'The music librarians have not entered any folders yet.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderAccountOutline" />
					</template>
					<template v-if="canEdit" #action>
						<NcButton variant="primary" @click="creating = true">
							{{ t('notenarchiv', 'New folder') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<template v-else>
					<p class="folder-book__summary">
						{{ n('notenarchiv', '%n folder in service: {out} out, {available} available.', '%n folders in service: {out} out, {available} available.',
							summary.active, { out: summary.out, available: summary.available }) }}
					</p>
					<div class="folder-book__filters no-print">
						<NcSelect v-model="filterOption"
							class="folder-book__filter"
							:input-label="t('notenarchiv', 'Show')"
							:options="filterOptions"
							:clearable="false"
							:searchable="false" />
						<NcTextField v-model="search"
							class="folder-book__search"
							:label="t('notenarchiv', 'Find a folder, voice or person')"
							trailing-button-icon="close"
							:show-trailing-button="search !== ''"
							@trailing-button-click="search = ''" />
					</div>

					<p v-if="shown.length === 0" class="folder-book__empty" role="status">
						{{ t('notenarchiv', 'No folder matches.') }}
					</p>
					<table v-else class="folder-book__table">
						<caption class="hidden-visually">
							{{ n('notenarchiv', '%n folder', '%n folders', shown.length) }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('notenarchiv', 'Folder') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Voice') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Out with') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Since') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Contents') }}
								</th>
								<th scope="col">
									{{ t('notenarchiv', 'Condition and missing sheets') }}
								</th>
								<th scope="col" class="no-print">
									<span class="hidden-visually">{{ t('notenarchiv', 'Actions') }}</span>
								</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="folder in shown" :key="folder.id">
								<tr>
									<th scope="row" class="folder-book__label">
										{{ folder.label }}
										<span v-if="folder.status === 'retired'" class="folder-book__muted">
											{{ t('notenarchiv', 'Out of service since {date}: {reason}', { date: formatDate(folder.statusSince ?? ''), reason: folder.statusReason ?? '' }) }}
										</span>
									</th>
									<td>{{ folder.voice ?? '–' }}</td>
									<td>
										<template v-if="folder.loan">
											{{ holderText(folder.loan) }}
										</template>
										<span v-else class="folder-book__muted">{{ t('notenarchiv', 'available') }}</span>
									</td>
									<td>{{ folder.loan ? formatDate(folder.loan.issuedOn) : '–' }}</td>
									<td>
										<ul v-if="folder.contents.length > 0" class="folder-book__items">
											<li v-for="item in folder.contents" :key="item.copyId">
												{{ item.shelfmark }} {{ item.title ?? '' }}
												<template v-if="item.count > 1">
													({{ n('notenarchiv', '%n copy', '%n copies', item.count) }})
												</template>
											</li>
										</ul>
										<span v-else class="folder-book__muted">{{ t('notenarchiv', 'No contents entered') }}</span>
										<div v-if="folder.outdated" class="folder-book__hint">
											<strong>{{ t('notenarchiv', 'Hint: the folder contains a version that no longer applies.') }}</strong>
											<span v-for="hint in hints(folder)" :key="hint" class="folder-book__hint-line">{{ hint }}</span>
										</div>
									</td>
									<td>
										{{ wearLabel(folder.wear) }}
										<span v-if="folder.missing" class="folder-book__missing">
											{{ t('notenarchiv', 'Missing: {sheets}', { sheets: folder.missing }) }}
										</span>
									</td>
									<td class="folder-book__actions no-print">
										<template v-if="canEdit && folder.status === 'active'">
											<NcButton v-if="folder.loan"
												variant="primary"
												:aria-label="t('notenarchiv', 'Take back folder {label}', { label: folder.label })"
												@click="loanDialog = { folder, mode: 'return' }">
												{{ t('notenarchiv', 'Take back') }}
											</NcButton>
											<NcButton v-else
												variant="primary"
												:aria-label="t('notenarchiv', 'Issue folder {label}', { label: folder.label })"
												@click="loanDialog = { folder, mode: 'issue' }">
												{{ t('notenarchiv', 'Issue') }}
											</NcButton>
										</template>
										<NcButton v-if="canEdit"
											:aria-label="t('notenarchiv', 'Edit folder {label}', { label: folder.label })"
											@click="editing = folder">
											{{ t('notenarchiv', 'Edit') }}
										</NcButton>
										<NcButton v-if="folder.history.length > 0"
											variant="tertiary"
											:aria-expanded="openHistory.has(folder.id) ? 'true' : 'false'"
											:aria-label="t('notenarchiv', 'Loans of folder {label}', { label: folder.label })"
											@click="toggleHistory(folder)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiHistory" />
											</template>
											{{ n('notenarchiv', '%n loan', '%n loans', folder.history.length) }}
										</NcButton>
									</td>
								</tr>
								<tr v-if="openHistory.has(folder.id)" class="folder-book__history no-print">
									<td colspan="7">
										<ul>
											<li v-for="loan in folder.history" :key="loan.id">
												<strong>{{ holderText(loan) }}</strong>:
												{{ loan.returnedOn
													? t('notenarchiv', 'from {from} to {to}', { from: formatDate(loan.issuedOn), to: formatDate(loan.returnedOn) })
													: t('notenarchiv', 'since {from}', { from: formatDate(loan.issuedOn) }) }}
												<template v-if="loan.returnWear">
													– {{ t('notenarchiv', 'came back: {condition}', { condition: wearLabel(loan.returnWear) }) }}
												</template>
												<template v-if="loan.returnMissing">
													– {{ t('notenarchiv', 'Missing: {sheets}', { sheets: loan.returnMissing }) }}
												</template>
												<span v-if="loan.issueNote || loan.returnNote" class="folder-book__muted">
													{{ [loan.issueNote, loan.returnNote].filter(Boolean).join(' · ') }}
												</span>
											</li>
										</ul>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</template>
			</template>
		</div>

		<FolderDialog v-if="creating || editing !== null"
			:folder="editing"
			@saved="onSaved"
			@close="editing = null; creating = false" />
		<FolderLoanDialog v-if="loanDialog !== null"
			:folder="loanDialog.folder"
			:mode="loanDialog.mode"
			@saved="onSaved"
			@close="loanDialog = null" />
	</div>
</template>

<style scoped>
.folder-book__content {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.folder-book__summary {
	margin: 0;
	font-weight: bold;
}

.folder-book__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
}

.folder-book__filter {
	min-width: 18em;
}

.folder-book__search {
	max-width: 24em;
}

.folder-book__empty {
	color: var(--color-text-maxcontrast);
}

.folder-book__table {
	width: 100%;
	border-collapse: collapse;
}

.folder-book__table th,
.folder-book__table td {
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
	overflow-wrap: break-word;
}

.folder-book__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.folder-book__label {
	font-weight: bold;
}

.folder-book__muted {
	display: block;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.folder-book__items {
	margin: 0;
	padding: 0;
	list-style: none;
}

.folder-book__hint {
	margin-top: var(--default-grid-baseline);
	padding-inline-start: var(--default-grid-baseline);
	border-inline-start: 3px solid var(--color-warning);
}

.folder-book__hint-line,
.folder-book__missing {
	display: block;
}

.folder-book__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.folder-book__history ul {
	margin: 0;
	padding-inline-start: calc(4 * var(--default-grid-baseline));
	list-style: disc;
}

@media print {
	.folder-book__content {
		padding: 0;
	}

	.folder-book__table th,
	.folder-book__table td {
		border-bottom: 1px solid #000;
	}

	.folder-book__hint {
		border-inline-start: 2px solid #000;
	}
}
</style>
