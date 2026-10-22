<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Issue a choir folder to a person (a Nextcloud account or a name), or take
 * it back. Taking back starts with today, the condition and the missing
 * sheets known so far; the condition and the missing sheets entered become
 * those of the folder.
 */
import type { AccountOption, Folder, FolderWear } from '../folderBook.ts'

import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { errorMessage } from '../api.ts'
import { holderText, issueFolder, returnFolder, searchAccounts, todayIso, WEARS, wearLabel } from '../folderBook.ts'
import { formatDate } from '../format.ts'

const props = defineProps<{
	folder: Folder
	mode: 'issue' | 'return'
}>()

const emit = defineEmits<{
	saved: []
	close: []
}>()

const today = todayIso()

const draft = reactive({
	kind: 'name' as 'account' | 'name',
	name: '',
	account: null as AccountOption | null,
	day: today,
	note: '',
	// prefilled from the folder for the return
	wear: props.folder.wear as FolderWear,
	missing: props.folder.missing ?? '',
})

const accountOptions = ref<AccountOption[]>([])
const searching = ref(false)
let searchRun = 0

/**
 * Look up accounts while typing.
 *
 * @param query - typed text
 */
async function onSearch(query: string): Promise<void> {
	const run = ++searchRun
	searching.value = true
	try {
		const found = await searchAccounts(query)
		if (run === searchRun) {
			accountOptions.value = found
		}
	} catch {
		if (run === searchRun) {
			accountOptions.value = []
		}
	} finally {
		if (run === searchRun) {
			searching.value = false
		}
	}
}

const busy = ref(false)
const error = ref('')

const title = computed(() => props.mode === 'issue'
	? t('notenarchiv', 'Issue folder {label}', { label: props.folder.label })
	: t('notenarchiv', 'Take back folder {label}', { label: props.folder.label }))

async function save(): Promise<void> {
	error.value = ''
	if (draft.day === '' || draft.day > today) {
		error.value = t('notenarchiv', 'Please enter a day up to today.')
		return
	}
	busy.value = true
	try {
		if (props.mode === 'issue') {
			if (draft.kind === 'account' && draft.account === null) {
				error.value = t('notenarchiv', 'Please choose the Nextcloud account, or enter the name instead.')
				return
			}
			if (draft.kind === 'name' && draft.name.trim() === '') {
				error.value = t('notenarchiv', 'Please enter the name of the person.')
				return
			}
			await issueFolder(props.folder.id, {
				...(draft.kind === 'account' ? { holderUid: draft.account?.id ?? '' } : { holderName: draft.name.trim() }),
				issuedOn: draft.day,
				note: draft.note.trim() === '' ? null : draft.note.trim(),
			})
		} else if (props.folder.loan) {
			if (draft.day < props.folder.loan.issuedOn) {
				error.value = t('notenarchiv', 'The folder cannot come back before it was handed out ({date}). Please check the date.', { date: formatDate(props.folder.loan.issuedOn) })
				return
			}
			await returnFolder(props.folder.loan.id, {
				returnedOn: draft.day,
				wear: draft.wear,
				missing: draft.missing.trim() === '' ? null : draft.missing.trim(),
				note: draft.note.trim() === '' ? null : draft.note.trim(),
			})
		}
		emit('saved')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="title"
		:submit-label="mode === 'issue' ? t('notenarchiv', 'Issue') : t('notenarchiv', 'Take back')"
		:busy="busy"
		:error="error"
		@submit="save"
		@close="emit('close')">
		<template v-if="mode === 'issue'">
			<fieldset class="loan-dialog__group">
				<legend>{{ t('notenarchiv', 'To whom?') }}</legend>
				<NcCheckboxRadioSwitch v-model="draft.kind"
					type="radio"
					value="name"
					name="loan-holder">
					{{ t('notenarchiv', 'Name of the person') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="draft.kind"
					type="radio"
					value="account"
					name="loan-holder">
					{{ t('notenarchiv', 'Nextcloud account') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextField v-if="draft.kind === 'name'"
				v-model="draft.name"
				:label="t('notenarchiv', 'Name (required)')"
				maxlength="255"
				required />
			<NcSelect v-else
				v-model="draft.account"
				:input-label="t('notenarchiv', 'Nextcloud account (required)')"
				:placeholder="t('notenarchiv', 'Type at least two letters of the name')"
				:options="accountOptions"
				:loading="searching"
				:filterable="false"
				@search="onSearch" />
			<DateField v-model="draft.day" :label="t('notenarchiv', 'Handed out on')" :max="today" />
		</template>

		<template v-else>
			<p v-if="folder.loan">
				{{ t('notenarchiv', 'Out with {holder} since {date}.', { holder: holderText(folder.loan), date: formatDate(folder.loan.issuedOn) }) }}
			</p>
			<DateField v-model="draft.day"
				:label="t('notenarchiv', 'Taken back on')"
				:min="folder.loan?.issuedOn"
				:max="today" />
			<fieldset class="loan-dialog__group">
				<legend>{{ t('notenarchiv', 'Condition') }}</legend>
				<NcCheckboxRadioSwitch v-for="wear in WEARS"
					:key="wear"
					v-model="draft.wear"
					type="radio"
					:value="wear"
					name="loan-wear">
					{{ wearLabel(wear) }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextArea v-model="draft.missing"
				:label="t('notenarchiv', 'Missing sheets')"
				:helper-text="t('notenarchiv', 'What was missing so far is filled in. Empty when nothing is missing.')"
				resize="vertical"
				rows="2" />
		</template>

		<NcTextArea v-model="draft.note"
			:label="t('notenarchiv', 'Note')"
			:helper-text="t('notenarchiv', 'Removed together with the name after the retention period.')"
			resize="vertical"
			rows="2" />
	</ActionDialog>
</template>

<style scoped>
.loan-dialog__group {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	border: none;
}

.loan-dialog__group legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}
</style>
